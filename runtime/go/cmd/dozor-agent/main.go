package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"sync"
	"syscall"
	"time"

	"github.com/pepperfm/dozor-agent/runtime/go/internal/ingest"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/protocol"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/shipping"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/state"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/transform"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/tui"
)

const version = "0.2.0"

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
func envInt(key string, fallback int) int {
	if n, e := strconv.Atoi(os.Getenv(key)); e == nil && n > 0 {
		return n
	}
	return fallback
}
func envDuration(key string, fallback time.Duration) time.Duration {
	if v, e := strconv.ParseFloat(os.Getenv(key), 64); e == nil && v > 0 {
		return time.Duration(v * float64(time.Second))
	}
	return fallback
}
func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "dozor-agent:", err)
		os.Exit(1)
	}
}
func run() error {
	if len(os.Args) > 1 && os.Args[1] == "status" {
		return status(os.Args[2:])
	}
	fs := flag.NewFlagSet("dozor-agent", flag.ContinueOnError)
	listen := fs.String("listen", env("DOZOR_INGEST_URI", "127.0.0.1:4815"), "local TCP listener")
	path := fs.String("spool", env("DOZOR_GO_SPOOL_PATH", env("DOZOR_AGENT_SPOOL_PATH", "./spool")), "durable spool directory")
	statePath := fs.String("state-path", env("DOZOR_AGENT_STATE_PATH", "./runtime-state.json"), "runtime state JSON")
	tokenHash := fs.String("token-hash", env("DOZOR_AGENT_TOKEN_HASH", ""), "optional PHP-compatible token prefix")
	maxBatches := fs.Int("max-batches", envInt("DOZOR_AGENT_SHIP_MAX_SPOOL_BATCHES", 500), "max persisted batches (including dead letters)")
	maxClients := fs.Int("max-clients", envInt("DOZOR_AGENT_MAX_CLIENTS", 32), "max concurrent PHP clients")
	maxSpans := fs.Int("max-spans", envInt("DOZOR_AGENT_TRACE_MAX_SPANS_PER_TRACE", 200), "max spans per trace")
	debug := fs.Bool("debug", false, "render live terminal UI, or print safe logs without TTY")
	silent := fs.Bool("silent", false, "suppress startup output")
	ingestOnly := fs.Bool("ingest-only", false, "receive and spool data without shipping")
	ver := fs.Bool("version", false, "print binary version")
	if err := fs.Parse(os.Args[1:]); err != nil {
		return err
	}
	if *ver {
		fmt.Println(version)
		return nil
	}

	token := env("DOZOR_TOKEN", "")
	hash := *tokenHash
	if hash == "" && token != "" {
		hash = protocol.TokenHash(token)
	}
	if len(hash) != 7 {
		return errors.New("set DOZOR_TOKEN or DOZOR_AGENT_TOKEN_HASH to match PHP SDK")
	}
	hostname, _, err := net.SplitHostPort(*listen)
	if err != nil {
		return fmt.Errorf("invalid listener: %w", err)
	}
	if hostname != "127.0.0.1" && hostname != "::1" && hostname != "localhost" {
		return errors.New("TCP v1 uses only a 28-bit hash prefix: listen on loopback only")
	}
	remoteURL := env("DOZOR_HOSTED_INGEST_URL", "")
	remoteToken := env("DOZOR_HOSTED_INGEST_TOKEN", token)
	shippingEnabled := !*ingestOnly
	if shippingEnabled && (remoteURL == "" || remoteToken == "") {
		return errors.New("configure DOZOR_HOSTED_INGEST_URL and DOZOR_TOKEN or run --ingest-only")
	}
	if *maxBatches < 1 || *maxClients < 1 {
		return errors.New("max-batches and max-clients must be positive")
	}

	q, err := spool.Open(*path, *maxBatches)
	if err != nil {
		return err
	}
	defer q.Close()
	if err = q.Recover(); err != nil {
		return fmt.Errorf("recover spool: %w", err)
	}
	ln, err := net.Listen("tcp", *listen)
	if err != nil {
		return err
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()
	stats := &shipping.Stats{}
	writer := &state.Writer{Path: *statePath, ShipperEnabled: shippingEnabled}
	events := make(chan tui.Event, 128)
	terminal := *debug && tui.IsTerminal(os.Stdout)
	srv := &ingest.Server{Listener: ln, Queue: q, TokenHash: hash, MaxClients: *maxClients, BatchSize: envInt("DOZOR_AGENT_SHIP_BATCH_SIZE", 100), Debug: *debug && !terminal, Events: events}

	var workerWG sync.WaitGroup
	if shippingEnabled {
		tr := &transform.Transformer{
			AppName:  env("DOZOR_APP_NAME", env("APP_NAME", "laravel")),
			AppToken: token, Environment: env("DOZOR_ENV", env("APP_ENV", "production")),
			ServerName: env("DOZOR_SERVER", hostName()), MaxSpansPerTrace: *maxSpans,
		}
		// The app token must be provided even if a distinct hosted bearer token is configured.
		if token == "" {
			return errors.New("DOZOR_TOKEN required in shipping mode for payload app.token")
		}
		httpClient := &http.Client{Timeout: envDuration("DOZOR_AGENT_SHIP_TIMEOUT", 5*time.Second),
			Transport: &http.Transport{Proxy: http.ProxyFromEnvironment, MaxIdleConns: 64, MaxIdleConnsPerHost: 16,
				IdleConnTimeout: 90 * time.Second, ResponseHeaderTimeout: envDuration("DOZOR_AGENT_SHIP_TIMEOUT", 5*time.Second)},
		}
		client := &shipping.Client{URL: remoteURL, Token: remoteToken, HTTP: httpClient, Attempts: envInt("DOZOR_AGENT_SHIP_RETRY_ATTEMPTS", 3)}
		worker := &shipping.Worker{
			OnEvent: func(kind string) {
				if *debug && !terminal {
					// Log sanitized event names, not outbound payloads or tokens.
					log.Printf("shipping event=%s", kind)
				}
				select {
				case events <- tui.Event{Time: time.Now(), Kind: kind}:
				default:
				}
			}, Queue: q, Transport: client, Transformer: tr,
			MaxAttempts:  envInt("DOZOR_AGENT_SHIP_MAX_ATTEMPTS_PER_BATCH", 8),
			PollInterval: envDuration("DOZOR_AGENT_SHIP_FLUSH_INTERVAL_SECONDS", 2*time.Second),
			RetryBase:    time.Duration(envInt("DOZOR_AGENT_SHIP_QUEUE_BACKOFF_BASE_MS", 500)) * time.Millisecond,
			RetryCap:     time.Duration(envInt("DOZOR_AGENT_SHIP_QUEUE_BACKOFF_CAP_MS", 30000)) * time.Millisecond,
			Stats:        stats,
		}
		workerWG.Add(1)
		go func() {
			defer workerWG.Done()
			if err := worker.Run(ctx); err != nil && ctx.Err() == nil {
				log.Printf("shipping worker terminated: %T", err)
				stop()
			}
		}()
		// Agent heartbeat is independent of PHP SDK request activity.
		workerWG.Add(1)
		go func() {
			defer workerWG.Done()
			sendHeartbeat(ctx, q, tr, envDuration("DOZOR_AGENT_HEARTBEAT_INTERVAL_SECONDS", 15*time.Second))
		}()
	}

	var stateWG sync.WaitGroup
	stateCtx, stateCancel := context.WithCancel(context.Background())
	persist := func(status string) {
		queued, failed, err := q.Count()
		if err != nil {
			log.Printf("spool status unavailable: %T", err)
			return
		}
		if err = writer.Mark(status, *listen, queued, failed, srv.Received.Load(), srv.Rejected.Load(),
			stats.DeliveredBatches.Load(), stats.FailedUploads.Load()); err != nil {
			log.Printf("state persistence failure: %T", err)
		}
	}
	persist("running")
	stateWG.Add(1)
	go func() {
		defer stateWG.Done()
		ticker := time.NewTicker(time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-stateCtx.Done():
				return
			case <-ticker.C:
				persist("running")
			}
		}
	}()
	defer func() { stateCancel(); stateWG.Wait(); persist("stopped") }()

	if !*silent && !terminal {
		log.Printf("Dozor Agent %s listening on %s (shipping=%t)", version, *listen, shippingEnabled)
	}
	if terminal {
		screenCtx, cancelScreen := context.WithCancel(ctx)
		var screenWG sync.WaitGroup
		screenWG.Add(1)
		go func() { defer screenWG.Done(); tui.Run(screenCtx, *statePath, events, os.Stdout) }()
		defer func() { cancelScreen(); screenWG.Wait() }()
	} else if *debug {
		done := make(chan struct{})
		go func() {
			defer close(done)
			ticker := time.NewTicker(2 * time.Second)
			defer ticker.Stop()
			for {
				select {
				case <-ctx.Done():
					return
				case <-ticker.C:
					queued, failed, _ := q.Count()
					log.Printf("received=%d rejected=%d active=%d queued=%d failed=%d delivered=%d http_errors=%d",
						srv.Received.Load(), srv.Rejected.Load(), srv.Active.Load(), queued, failed,
						stats.DeliveredBatches.Load(), stats.FailedUploads.Load())
				}
			}
		}()
		defer func() { <-done }()
	}
	err = srv.Serve(ctx)
	// Do not close the spool until the independent worker has stopped.
	stop()
	workerWG.Wait()
	return err
}
func hostName() string {
	host, err := os.Hostname()
	if err != nil {
		return "unknown"
	}
	return host
}
func sendHeartbeat(ctx context.Context, q *spool.Queue, tr *transform.Transformer, interval time.Duration) {
	if interval <= 0 {
		return
	}
	started := time.Now()
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case now := <-ticker.C:
			queued, failed, err := q.Count()
			if err != nil {
				continue
			}
			record := map[string]any{
				"type": "heartbeat", "trace_id": transform.NewTraceID(),
				"happened_at": now.UTC().Format("2006-01-02T15:04:05-07:00"),
				"app":         tr.AppName, "environment": tr.Environment, "server": tr.ServerName,
				"payload": map[string]any{"status": "healthy", "uptime_seconds": int(now.Sub(started).Seconds()),
					"queue_depth": queued, "failed_queue_depth": failed, "memory_peak_bytes": 0},
			}
			body, err := json.Marshal([]any{record})
			if err != nil {
				continue
			}
			if _, err = q.Enqueue(body); err != nil {
				log.Printf("heartbeat not queued: %T", err)
			}
		}
	}
}
func status(arguments []string) error {
	fs := flag.NewFlagSet("status", flag.ContinueOnError)
	jsonFlag := fs.Bool("json", false, "machine-readable status")
	strict := fs.Bool("strict", false, "nonzero exit unless agent and remote shipping are ready")
	path := fs.String("spool", env("DOZOR_GO_SPOOL_PATH", env("DOZOR_AGENT_SPOOL_PATH", "./spool")), "spool directory")
	statePath := fs.String("state-path", env("DOZOR_AGENT_STATE_PATH", "./runtime-state.json"), "state JSON")
	listen := fs.String("listen", env("DOZOR_INGEST_URI", "127.0.0.1:4815"), "TCP listener")
	if err := fs.Parse(arguments); err != nil {
		return err
	}
	pending, failed, err := spool.CountAt(*path)
	if err != nil {
		return err
	}
	snapshot, _ := state.Read(*statePath)
	reachable := false
	hash := env("DOZOR_AGENT_TOKEN_HASH", "")
	if hash == "" && env("DOZOR_TOKEN", "") != "" {
		hash = protocol.TokenHash(env("DOZOR_TOKEN", ""))
	}
	if len(hash) == 7 {
		conn, e := net.DialTimeout("tcp", *listen, 500*time.Millisecond)
		if e == nil {
			frame := "v1:" + hash + ":PING"
			_ = conn.SetDeadline(time.Now().Add(time.Second))
			_, _ = io.WriteString(conn, fmt.Sprintf("%d:%s", len(frame), frame))
			var reply [4]byte
			_, e = io.ReadFull(conn, reply[:])
			_ = conn.Close()
			reachable = e == nil && string(reply[:]) == "2:OK"
		}
	}
	report := map[string]any{"enabled": true, "reachable": reachable, "ingest_uri": *listen,
		"shipper_enabled": snapshot.Context.ShipperEnabled,
		"lifecycle":       snapshot.Lifecycle, "metrics": snapshot.Metrics,
		"queue_depth": pending, "failed_queue_depth": failed, "runtime": "go"}
	if *jsonFlag {
		if err := json.NewEncoder(os.Stdout).Encode(report); err != nil {
			return err
		}
	} else {
		fmt.Printf("Dozor Agent: reachable=%t, shipping=%t, queue=%d, failed=%d, uptime=%ds\n",
			reachable, snapshot.Context.ShipperEnabled, pending, failed, snapshot.Metrics.UptimeSeconds)
	}
	if *strict && !reachable {
		return errors.New("local agent not reachable")
	}
	if *strict && !snapshot.Context.ShipperEnabled {
		return errors.New("remote shipping is disabled")
	}
	return nil
}
