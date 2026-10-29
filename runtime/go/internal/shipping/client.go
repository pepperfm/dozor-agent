package shipping

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// HTTPError separates retryable remote responses from permanent validation failures.
type HTTPError struct {
	Status     int
	Retryable  bool
	RetryAfter time.Duration
}

func (e *HTTPError) Error() string {
	return fmt.Sprintf("ingest HTTP status %d (retryable=%t)", e.Status, e.Retryable)
}

type Client struct {
	URL        string
	Token      string
	HTTP       *http.Client
	Attempts   int
	MaxBackoff time.Duration
	// Wait overrides backoff only in tests.
	Wait func(context.Context, time.Duration) error
}

// Upload never alters the stored payload on retry, preserving the server's hash-based idempotency.
func (c *Client) Upload(ctx context.Context, payload json.RawMessage) error {
	if c.URL == "" || c.Token == "" {
		return errors.New("shipping URL and bearer token required")
	}
	if !json.Valid(payload) || len(payload) == 0 {
		return errors.New("invalid outgoing payload")
	}
	attempts := c.Attempts
	if attempts < 1 {
		attempts = 1
	}
	httpClient := c.HTTP
	if httpClient == nil {
		httpClient = &http.Client{Timeout: 5 * time.Second}
	}
	var lastErr error
	for attempt := 1; attempt <= attempts; attempt++ {
		req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.URL, bytes.NewReader(payload))
		if err != nil {
			return err
		}
		req.Header.Set("Authorization", "Bearer "+c.Token)
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Accept", "application/json")
		response, err := httpClient.Do(req)
		delay := time.Duration(0)
		retry := false
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			lastErr = fmt.Errorf("ingest transport error: %w", err)
			retry = true
		} else {
			_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4096))
			_ = response.Body.Close()
			if response.StatusCode >= 200 && response.StatusCode < 300 {
				return nil
			}
			retry = response.StatusCode == 429 || response.StatusCode >= 500
			if retry {
				delay = retryAfter(response.Header.Get("Retry-After"), time.Now())
			}
			lastErr = &HTTPError{Status: response.StatusCode, Retryable: retry, RetryAfter: delay}
		}
		if !retry {
			return lastErr
		}
		if attempt == attempts {
			break
		}
		backoff := time.Duration(1<<min(attempt-1, 6)) * 250 * time.Millisecond
		capDelay := c.MaxBackoff
		if capDelay <= 0 {
			capDelay = 30 * time.Second
		}
		if backoff > capDelay {
			backoff = capDelay
		}
		backoff = time.Duration(rand.Int63n(int64(backoff) + 1))
		// A long server cooldown belongs in the durable spool, not a blocked
		// HTTP attempt loop. The worker persists Retry-After and processes other
		// batches instead of sleeping here for minutes.
		if delay > capDelay {
			break
		}
		if delay > backoff {
			backoff = delay
		}
		if c.Wait != nil {
			err = c.Wait(ctx, backoff)
		} else {
			err = sleep(ctx, backoff)
		}
		if err != nil {
			return err
		}
	}
	return lastErr
}
func retryAfter(header string, now time.Time) time.Duration {
	header = strings.TrimSpace(header)
	const maxDelay = 24 * time.Hour
	if seconds, err := strconv.ParseInt(header, 10, 64); err == nil && seconds >= 0 {
		// Prevent time.Duration overflow when the receiver sends a huge value.
		if seconds >= int64(maxDelay/time.Second) {
			return maxDelay
		}
		return time.Duration(seconds) * time.Second
	}
	if date, err := http.ParseTime(header); err == nil && date.After(now) {
		if duration := date.Sub(now); duration < maxDelay {
			return duration
		}
		return maxDelay
	}
	return 0
}
func sleep(ctx context.Context, delay time.Duration) error {
	timer := time.NewTimer(delay)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}
