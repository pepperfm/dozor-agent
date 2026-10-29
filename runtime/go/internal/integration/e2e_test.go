package integration

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/pepperfm/dozor-agent/runtime/go/internal/ingest"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/protocol"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/shipping"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/transform"
)

func send(t *testing.T, address, token, payload string) string {
	t.Helper()
	c, err := net.DialTimeout("tcp", address, 2*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(3 * time.Second))
	frame := "v1:" + protocol.TokenHash(token) + ":" + payload
	if _, err = fmt.Fprintf(c, "%d:%s", len(frame), frame); err != nil {
		t.Fatal(err)
	}
	var response [4]byte
	if _, err = io.ReadFull(c, response[:]); err != nil {
		t.Fatal(err)
	}
	return string(response[:])
}

func TestPHPWireToHTTPSDelivery(t *testing.T) {
	token := "integration-test-token"
	received := make(chan map[string]any, 1)
	remote := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+token {
			w.WriteHeader(401)
			return
		}
		var payload map[string]any
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			w.WriteHeader(422)
			return
		}
		if payload["v"] != float64(1) {
			w.WriteHeader(422)
			return
		}
		received <- payload
		w.WriteHeader(202)
	}))
	defer remote.Close()
	q, err := spool.Open(t.TempDir(), 50)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	server := &ingest.Server{Listener: ln, Queue: q, TokenHash: protocol.TokenHash(token), MaxClients: 10}
	srvErr := make(chan error, 1)
	go func() { srvErr <- server.Serve(ctx) }()
	tr := &transform.Transformer{AppName: "integration-app", AppToken: token, Environment: "testing", ServerName: "testhost"}
	worker := &shipping.Worker{Queue: q, Transformer: tr,
		Transport:    &shipping.Client{URL: remote.URL, Token: token, Attempts: 2},
		PollInterval: 10 * time.Millisecond,
	}
	workerErr := make(chan error, 1)
	go func() { workerErr <- worker.Run(ctx) }()
	const records = `[{"type":"request","trace_id":"01J00000000000000000000900","record_id":"01J00000000000000000000901","happened_at":"2026-10-29T02:00:00+00:00","payload":{"method":"GET","route_uri":"/integration","duration_ms":7}},{"type":"query","trace_id":"01J00000000000000000000900","record_id":"01J00000000000000000000902","payload":{"time_ms":2,"sql":"select 1"}}]`
	if got := send(t, ln.Addr().String(), token, records); got != "2:OK" {
		t.Fatalf("ACK: %s", got)
	}
	select {
	case payload := <-received:
		traces, ok := payload["traces"].([]any)
		if !ok || len(traces) != 1 {
			t.Fatalf("expected one trace: %+v", payload)
		}
		trace := traces[0].(map[string]any)
		if trace["route"] != "/integration" || trace["db_time_ms"] != float64(2) {
			t.Fatalf("broken trace: %+v", trace)
		}
		spans := trace["spans"].([]any)
		if len(spans) != 2 || spans[1].(map[string]any)["kind"] != "query" {
			t.Fatalf("broken spans %+v", spans)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("asynchronous shipping failed")
	}
	deadline := time.After(5 * time.Second)
	for {
		n, _, err := q.Count()
		if err != nil {
			t.Fatal(err)
		}
		if n == 0 {
			break
		}
		select {
		case <-deadline:
			t.Fatal("delivered batch not completed")
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	if err := <-srvErr; err != nil {
		t.Fatal(err)
	}
	if err := <-workerErr; err != nil {
		t.Fatal(err)
	}
}

func TestSpoolCapacityRejectsInsteadOfFalseACK(t *testing.T) {
	q, err := spool.Open(t.TempDir(), 1)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	server := &ingest.Server{Listener: ln, Queue: q, TokenHash: protocol.TokenHash("capacity"), MaxClients: 2}
	var wg sync.WaitGroup
	wg.Add(1)
	go func() { defer wg.Done(); _ = server.Serve(ctx) }()
	if got := send(t, ln.Addr().String(), "capacity", `[{"type":"request"}]`); got != "2:OK" {
		t.Fatal(got)
	}
	if got := send(t, ln.Addr().String(), "capacity", `[{"type":"request"}]`); got != "5:ER" {
		t.Fatal(got)
	}
	cancel()
	wg.Wait()
}

// A remote HTTP handler which has not responded must never hold the local TCP
// listener. The second durable ACK arrives before the receiver is released.
func TestSlowHTTPSReceiverDoesNotBlockLocalIngest(t *testing.T) {
	const token = "slow-receiver-contract"
	entered := make(chan struct{}, 1)
	release := make(chan struct{})
	remote := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case entered <- struct{}{}:
		default:
		}
		<-release
		w.WriteHeader(http.StatusAccepted)
	}))
	defer remote.Close()
	defer close(release)

	q, err := spool.Open(t.TempDir(), 20)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	server := &ingest.Server{Listener: ln, Queue: q, TokenHash: protocol.TokenHash(token), MaxClients: 8}
	serverDone := make(chan error, 1)
	go func() { serverDone <- server.Serve(ctx) }()
	worker := &shipping.Worker{
		Queue:        q,
		Transport:    &shipping.Client{URL: remote.URL, Token: token, Attempts: 1, HTTP: &http.Client{Timeout: 5 * time.Second}},
		Transformer:  &transform.Transformer{AppName: "app", AppToken: token, Environment: "test", ServerName: "node"},
		PollInterval: time.Millisecond,
	}
	workerDone := make(chan error, 1)
	go func() { workerDone <- worker.Run(ctx) }()
	const records = `[{"type":"request","trace_id":"01J00000000000000000000991","record_id":"01J00000000000000000000992","payload":{"duration_ms":4}}]`
	if response := send(t, ln.Addr().String(), token, records); response != "2:OK" {
		t.Fatalf("initial ACK: %s", response)
	}
	select {
	case <-entered:
	case <-time.After(3 * time.Second):
		t.Fatal("shipping worker did not start HTTP upload")
	}

	start := time.Now()
	if response := send(t, ln.Addr().String(), token, records); response != "2:OK" {
		t.Fatalf("second ACK blocked by remote receiver: %s", response)
	}
	if time.Since(start) > 2*time.Second {
		t.Fatal("local ACK waited for remote receiver")
	}
	cancel()
	if err := <-serverDone; err != nil {
		t.Fatal(err)
	}
	if err := <-workerDone; err != nil {
		t.Fatal(err)
	}
}

// Disk errors must become 5:ER. Returning a successful ACK would cause
// the PHP SDK to discard records that the daemon never persisted.
func TestDiskWriteFailureRejectsWithProtocolError(t *testing.T) {
	directory := t.TempDir()
	q, err := spool.Open(directory, 5)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	if err := os.RemoveAll(filepath.Join(directory, "queue")); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(directory, "queue"), []byte("not a directory"), 0600); err != nil {
		t.Fatal(err)
	}
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	server := &ingest.Server{Listener: ln, Queue: q, TokenHash: protocol.TokenHash("disk-failure"), MaxClients: 2}
	done := make(chan error, 1)
	go func() { done <- server.Serve(ctx) }()
	const records = `[{"type":"request","trace_id":"01J00000000000000000000999","payload":{"duration_ms":1}}]`
	if response := send(t, ln.Addr().String(), "disk-failure", records); response != "5:ER" {
		t.Fatalf("lost telemetry was incorrectly acknowledged: %s", response)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}
