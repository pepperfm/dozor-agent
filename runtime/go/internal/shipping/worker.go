package shipping

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"github.com/pepperfm/dozor-agent/runtime/go/internal/spool"
	"math/rand"
	"sync/atomic"
	"time"
)

type Transformer interface {
	Transform(context.Context, json.RawMessage) (json.RawMessage, error)
}
type Stats struct {
	DeliveredBatches atomic.Uint64
	FailedUploads    atomic.Uint64
	DeadLetters      atomic.Uint64
}
type Worker struct {
	OnEvent      func(kind string)
	Stats        *Stats
	Queue        *spool.Queue
	Transport    *Client
	Transformer  Transformer
	MaxAttempts  int
	PollInterval time.Duration
	RetryBase    time.Duration
	RetryCap     time.Duration
}

func (w *Worker) Run(ctx context.Context) error {
	if w.Queue == nil || w.Transport == nil || w.Transformer == nil {
		return errors.New("shipping worker requires durable spool, transport and validated transformer")
	}
	if err := w.Queue.Recover(); err != nil {
		return fmt.Errorf("recover spool: %w", err)
	}
	for {
		if ctx.Err() != nil {
			return nil
		}
		claimed, err := w.Queue.Claim(time.Now())
		if err != nil {
			return err
		}
		if claimed == nil {
			interval := w.PollInterval
			if interval <= 0 {
				interval = 200 * time.Millisecond
			}
			timer := time.NewTimer(interval)
			select {
			case <-ctx.Done():
				timer.Stop()
				return nil
			case <-timer.C:
				continue
			}
		}
		if err := w.process(ctx, claimed); err != nil {
			// If storage fails, leave the processing file intact; Recover will requeue it.
			return err
		}
	}
}
func (w *Worker) emit(kind string) {
	if w.OnEvent != nil {
		w.OnEvent(kind)
	}
}

// Persist dead letters before reporting them. The total is an observation,
// not a promise, so failed disk moves must never increment it.
func (w *Worker) abandon(item *spool.Claimed, reason, event string) error {
	if err := w.Queue.Abandon(item, reason); err != nil {
		return err
	}
	if w.Stats != nil {
		w.Stats.DeadLetters.Add(1)
	}
	w.emit(event)
	return nil
}
func (w *Worker) process(ctx context.Context, item *spool.Claimed) error {
	if item.Envelope.ID == "" || len(item.Envelope.Payload) == 0 || !json.Valid(item.Envelope.Payload) {
		return w.abandon(item, "corrupt", "deadletter-corrupt")
	}
	// Raw records are arrays; outbound payloads are objects. Do not upload arrays.
	if item.Envelope.Payload[0] == '[' {
		outbound, err := w.Transformer.Transform(ctx, item.Envelope.Payload)
		if err != nil {
			if ctx.Err() != nil {
				return w.Queue.Release(item)
			}
			return w.abandon(item, "transform", "deadletter-transform")
		}
		if !json.Valid(outbound) || len(outbound) == 0 || outbound[0] != '{' {
			return w.abandon(item, "invalid-outbound", "deadletter-invalid")
		}
		item.Envelope.Payload = outbound
		if err = w.Queue.Save(item); err != nil {
			return err
		}
	} else if item.Envelope.Payload[0] != '{' {
		return w.abandon(item, "invalid-payload", "deadletter-invalid")
	}
	err := w.Transport.Upload(ctx, item.Envelope.Payload)
	if err == nil {
		if e := w.Queue.Complete(item); e != nil {
			return e
		}
		if w.Stats != nil {
			w.Stats.DeliveredBatches.Add(1)
		}
		w.emit("uploaded-202")
		return nil
	}
	if ctx.Err() != nil {
		return w.Queue.Release(item)
	}
	if w.Stats != nil {
		w.Stats.FailedUploads.Add(1)
	}
	var httpErr *HTTPError
	if errors.As(err, &httpErr) && !httpErr.Retryable {
		return w.abandon(item, "http-permanent", "deadletter-http")
	}
	attempt := item.Envelope.Attempt + 1
	maxAttempts := w.MaxAttempts
	if maxAttempts < 1 {
		maxAttempts = 8
	}
	if attempt >= maxAttempts {
		return w.abandon(item, "max-attempts", "deadletter-retries")
	}
	base := w.RetryBase
	if base <= 0 {
		base = 500 * time.Millisecond
	}
	capDelay := w.RetryCap
	if capDelay <= 0 {
		capDelay = 30 * time.Second
	}
	backoff := base * time.Duration(1<<min(attempt-1, 10))
	if backoff > capDelay {
		backoff = capDelay
	}
	backoff = time.Duration(rand.Int63n(int64(backoff) + 1))
	if errors.As(err, &httpErr) && httpErr.RetryAfter > backoff {
		// Respect remote throttling even when it exceeds our local jitter cap.
		// The worker persists the future deadline and moves to other batches.
		backoff = httpErr.RetryAfter
	}
	item.Envelope.Attempt = attempt
	item.Envelope.LastFailedAt = float64(time.Now().UnixNano()) / 1e9
	item.Envelope.NextAttemptAt = float64(time.Now().Add(backoff).UnixNano()) / 1e9
	if err = w.Queue.Save(item); err != nil {
		return err
	}
	w.emit("upload-retry")
	return w.Queue.Release(item)
}
