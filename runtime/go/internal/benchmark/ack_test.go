package benchmark

import (
	"context"
	"fmt"
	"io"
	"net"
	"runtime"
	"sort"
	"sync"
	"testing"
	"time"

	"github.com/pepperfm/dozor-agent/runtime/go/internal/ingest"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/protocol"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
)

// BenchmarkACK measures the durable local ACK path, not remote HTTP latency.
// Run with: go test -run '^$' -bench BenchmarkACK -benchtime 5s -benchmem ./internal/benchmark
func BenchmarkACK(b *testing.B) {
	q, err := spool.Open(b.TempDir(), 1_000_000)
	if err != nil {
		b.Fatal(err)
	}
	defer q.Close()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		b.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	server := &ingest.Server{Listener: ln, Queue: q, TokenHash: protocol.TokenHash("benchmark"), MaxClients: 128}
	done := make(chan error, 1)
	go func() { done <- server.Serve(ctx) }()
	frameBody := "v1:" + protocol.TokenHash("benchmark") + ":" + `[{"type":"request","trace_id":"01J00000000000000000000001","payload":{"duration_ms":2}}]`
	frame := []byte(fmt.Sprintf("%d:%s", len(frameBody), frameBody))
	var mu sync.Mutex
	samples := make([]time.Duration, 0, b.N)
	b.ResetTimer()
	b.RunParallel(func(pb *testing.PB) {
		local := make([]time.Duration, 0, 256)
		for pb.Next() {
			start := time.Now()
			conn, err := net.DialTimeout("tcp", ln.Addr().String(), time.Second)
			if err != nil {
				b.Errorf("dial: %v", err)
				return
			}
			_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
			_, err = conn.Write(frame)
			var ack [4]byte
			if err == nil {
				_, err = io.ReadFull(conn, ack[:])
			}
			_ = conn.Close()
			if err != nil || string(ack[:]) != "2:OK" {
				b.Errorf("ack=%q err=%v", ack, err)
				return
			}
			local = append(local, time.Since(start))
		}
		mu.Lock()
		samples = append(samples, local...)
		mu.Unlock()
	})
	b.StopTimer()
	cancel()
	if err := <-done; err != nil {
		b.Fatal(err)
	}
	sort.Slice(samples, func(i, j int) bool { return samples[i] < samples[j] })
	if len(samples) > 0 {
		pct := func(p float64) float64 {
			return float64(samples[min(len(samples)-1, int(float64(len(samples))*p))]) / float64(time.Millisecond)
		}
		b.ReportMetric(pct(.5), "p50_ms")
		b.ReportMetric(pct(.95), "p95_ms")
		b.ReportMetric(pct(.99), "p99_ms")
	}
	var mem runtime.MemStats
	runtime.ReadMemStats(&mem)
	b.ReportMetric(float64(mem.Alloc)/1048576, "heap_MiB")
	b.ReportMetric(float64(mem.Sys)/1048576, "runtime_sys_MiB")
}
