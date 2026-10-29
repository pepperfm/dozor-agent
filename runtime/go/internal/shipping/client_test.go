package shipping

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"sync/atomic"
	"testing"
	"time"
)

func TestUploadRetriesSamePayloadAndHonorsRetryAfter(t *testing.T) {
	var calls atomic.Int64
	var payloads []string
	var delays []time.Duration
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" || r.Header.Get("Authorization") != "Bearer secret" {
			t.Errorf("unexpected headers/method")
		}
		b, _ := io.ReadAll(r.Body)
		payloads = append(payloads, string(b))
		if calls.Add(1) == 1 {
			w.Header().Set("Retry-After", "1")
			w.WriteHeader(429)
			return
		}
		w.WriteHeader(202)
	}))
	defer server.Close()
	body := json.RawMessage(`{"v":1,"sent_at":"unchanged","traces":[]}`)
	client := Client{URL: server.URL, Token: "secret", Attempts: 2, Wait: func(_ context.Context, d time.Duration) error { delays = append(delays, d); return nil }}
	if err := client.Upload(context.Background(), body); err != nil {
		t.Fatal(err)
	}
	if calls.Load() != 2 || !reflect.DeepEqual(payloads, []string{string(body), string(body)}) {
		t.Fatalf("payload changed: %v", payloads)
	}
	if len(delays) != 1 || delays[0] < time.Second {
		t.Fatalf("Retry-After ignored: %v", delays)
	}
}
func TestHTTPErrorDoesNotRetry(t *testing.T) {
	var calls atomic.Int64
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { calls.Add(1); w.WriteHeader(422) }))
	defer server.Close()
	client := Client{URL: server.URL, Token: "secret", Attempts: 5}
	err := client.Upload(context.Background(), json.RawMessage(`{"v":1}`))
	var permanent *HTTPError
	if !errors.As(err, &permanent) || permanent.Status != 422 || permanent.Retryable || calls.Load() != 1 {
		t.Fatalf("unexpected %v %d", err, calls.Load())
	}
}
func TestCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	client := Client{URL: "http://127.0.0.1:1", Token: "secret", Attempts: 3}
	if err := client.Upload(ctx, json.RawMessage(`{}`)); !errors.Is(err, context.Canceled) {
		t.Fatalf("unexpected: %v", err)
	}
}
func TestRetryAfterParser(t *testing.T) {
	now := time.Now()
	if got := retryAfter("5", now); got != 5*time.Second {
		t.Fatal(got)
	}
	if got := retryAfter("nonsense", now); got != 0 {
		t.Fatal(got)
	}
}

func TestHTTP429PreservesRetryAfterForDurableSpool(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Retry-After", "7")
		w.WriteHeader(429)
	}))
	defer server.Close()
	client := Client{URL: server.URL, Token: "secret", Attempts: 1}
	err := client.Upload(context.Background(), json.RawMessage(`{"v":1}`))
	var throttled *HTTPError
	if !errors.As(err, &throttled) || !throttled.Retryable || throttled.RetryAfter != 7*time.Second {
		t.Fatalf("expected 7s retry after, got %+v", err)
	}
}

func TestLongRetryAfterYieldsToDurableWorker(t *testing.T) {
	var attempts atomic.Int64
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		attempts.Add(1)
		w.Header().Set("Retry-After", "120")
		w.WriteHeader(http.StatusTooManyRequests)
	}))
	defer server.Close()

	var waited atomic.Bool
	client := Client{URL: server.URL, Token: "secret", Attempts: 3,
		MaxBackoff: time.Second, Wait: func(_ context.Context, _ time.Duration) error {
			waited.Store(true)
			return nil
		},
	}
	err := client.Upload(context.Background(), json.RawMessage(`{"v":1}`))
	var throttled *HTTPError
	if !errors.As(err, &throttled) || throttled.RetryAfter != 120*time.Second {
		t.Fatalf("expected durable 120s cooldown: %v", err)
	}
	if waited.Load() || attempts.Load() != 1 {
		t.Fatalf("long cooldown blocked the HTTP client: waited=%t attempts=%d", waited.Load(), attempts.Load())
	}
	if got := retryAfter("999999999999", time.Now()); got != 24*time.Hour {
		t.Fatalf("unbounded Retry-After: %v", got)
	}
}

func TestHTTPSShippingWithValidatedTLS(t *testing.T) {
	var received atomic.Bool
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.TLS == nil || r.Header.Get("Authorization") != "Bearer tls-secret" {
			t.Errorf("expected authenticated TLS request")
		}
		received.Store(true)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	client := Client{
		URL:      server.URL,
		Token:    "tls-secret",
		HTTP:     server.Client(), // Trust only this test server's certificate.
		Attempts: 1,
	}
	if err := client.Upload(context.Background(), json.RawMessage(`{"v":1,"traces":[]}`)); err != nil {
		t.Fatal(err)
	}
	if !received.Load() {
		t.Fatal("TLS receiver did not receive payload")
	}
}

func TestHTTPSRejectsUntrustedCertificate(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	// Production defaults must verify TLS chains; a local self-signed
	// certificate is rejected unless explicitly trusted in the client.
	client := Client{URL: server.URL, Token: "tls-secret", Attempts: 1}
	err := client.Upload(context.Background(), json.RawMessage(`{"v":1}`))
	if err == nil {
		t.Fatal("untrusted TLS certificate was accepted")
	}
}
