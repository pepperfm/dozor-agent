package ingest

import (
	"context"
	"errors"
	"fmt"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
	"io"
	"net"
	"sync"
	"testing"
	"time"
)

func TestConcurrentClientsAndOutage(t *testing.T) {
	q, err := spool.Open(t.TempDir(), 200)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	srv := &Server{Listener: listener, Queue: q, TokenHash: "abcdef0", MaxClients: 8}
	done := make(chan error, 1)
	go func() { done <- srv.Serve(ctx) }()
	var wg sync.WaitGroup
	for i := 0; i < 50; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			c, e := net.DialTimeout("tcp", listener.Addr().String(), time.Second)
			if e != nil {
				return
			}
			defer c.Close()
			b := `v1:abcdef0:[{"type":"request"}]`
			fmt.Fprintf(c, "%d:%s", len(b), b)
			var resp [4]byte
			_ = c.SetReadDeadline(time.Now().Add(2 * time.Second))
			_, _ = io.ReadFull(c, resp[:])
		}()
	}
	wg.Wait()
	cancel()
	select {
	case err = <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("shutdown blocked")
	}
	count, _, err := q.Count()
	if err != nil || count == 0 {
		t.Fatalf("spool count %d err %v", count, err)
	}
}

type failsAfterFirstAccept struct {
	net.Listener
	accepted int
}

func (l *failsAfterFirstAccept) Accept() (net.Conn, error) {
	l.accepted++
	if l.accepted > 1 {
		return nil, errors.New("forced listener failure")
	}
	return l.Listener.Accept()
}

func TestFatalAcceptDrainsInFlightClient(t *testing.T) {
	q, err := spool.Open(t.TempDir(), 4)
	if err != nil {
		t.Fatal(err)
	}
	defer q.Close()
	real, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	listener := &failsAfterFirstAccept{Listener: real}
	srv := &Server{Listener: listener, Queue: q, TokenHash: "abcdef0", MaxClients: 2}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	result := make(chan error, 1)
	go func() { result <- srv.Serve(ctx) }()

	c, err := net.DialTimeout("tcp", real.Addr().String(), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	// This accepted client is intentionally slow. Even when Accept fails,
	// Serve must not return while its handler could still be writing spool files.
	select {
	case err := <-result:
		t.Fatalf("server exited with client in flight: %v", err)
	case <-time.After(100 * time.Millisecond):
	}
	_ = c.Close()
	select {
	case err := <-result:
		if err == nil || err.Error() != "forced listener failure" {
			t.Fatalf("unexpected accept error: %v", err)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("fatal shutdown did not drain active clients")
	}
}
