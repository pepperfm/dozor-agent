package spool

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"syscall"
	"sync/atomic"
	"time"
)

var ErrCapacity = errors.New("spool capacity reached")
var ErrLocked = errors.New("spool already owned by another agent")

type Envelope struct {
	ID            string          `json:"id"`
	Attempt       int             `json:"attempt"`
	NextAttemptAt float64         `json:"next_attempt_at"`
	CreatedAt     float64         `json:"created_at"`
	Payload       json.RawMessage `json:"payload"`
	LastFailedAt  float64         `json:"last_failed_at,omitempty"`
}
type Queue struct {
	root     string
	max      int
	lock     *os.File
	mu       sync.Mutex
	rejected atomic.Uint64
}

func Open(root string, max int) (*Queue, error) {
	if max < 1 {
		return nil, errors.New("max spool batches must be positive")
	}
	for _, sub := range []string{"queue", "processing", "failed"} {
		if err := os.MkdirAll(filepath.Join(root, sub), 0700); err != nil {
			return nil, err
		}
	}
	f, err := os.OpenFile(filepath.Join(root, ".go-agent.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	if err = syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		f.Close()
		return nil, fmt.Errorf("%w: %v", ErrLocked, err)
	}
	return &Queue{root: root, max: max, lock: f}, nil
}
func (q *Queue) Close() error {
	q.mu.Lock()
	defer q.mu.Unlock()
	if q.lock == nil {
		return nil
	}
	err := syscall.Flock(int(q.lock.Fd()), syscall.LOCK_UN)
	e := q.lock.Close()
	q.lock = nil
	if err != nil {
		return err
	}
	return e
}
func (q *Queue) Count() (int, int, error) { q.mu.Lock(); defer q.mu.Unlock(); return q.count() }
func (q *Queue) count() (int, int, error) {
	a, e := filepath.Glob(filepath.Join(q.root, "queue", "*.json"))
	if e != nil {
		return 0, 0, e
	}
	b, e := filepath.Glob(filepath.Join(q.root, "failed", "*.json"))
	if e != nil {
		return 0, 0, e
	}
	c, e := filepath.Glob(filepath.Join(q.root, "processing", "*.json"))
	return len(a) + len(c), len(b), e
}
func id() string {
	var b [8]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b[:])
}
func syncDir(dir string) error {
	f, err := os.Open(dir)
	if err != nil {
		return err
	}
	defer f.Close()
	return f.Sync()
}
func writeAtomic(target string, contents []byte) error {
	dir := filepath.Dir(target)
	f, err := os.CreateTemp(dir, ".partial-*")
	if err != nil {
		return err
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	if err = f.Chmod(0600); err != nil {
		f.Close()
		return err
	}
	n, err := f.Write(contents)
	if err == nil && n != len(contents) {
		err = errors.New("short spool write")
	}
	if err == nil {
		err = f.Sync()
	}
	if e := f.Close(); err == nil {
		err = e
	}
	if err != nil {
		return err
	}
	if err = os.Rename(tmp, target); err != nil {
		return err
	}
	return syncDir(dir)
}

// Enqueue persists an immutable outbound/record payload before allowing TCP ACK.
func (q *Queue) Enqueue(payload json.RawMessage) (string, error) {
	ids, err := q.EnqueueMany([]json.RawMessage{payload})
	if err != nil {
		return "", err
	}
	return ids[0], nil
}

func (q *Queue) Rejected() uint64 {
	return q.rejected.Load()
}

// EnqueueMany reserves capacity for all chunks and ACK can only follow when all
// payloads were persisted. Partial errors are rolled back where possible.
func (q *Queue) EnqueueMany(payloads []json.RawMessage) ([]string, error) {
	if len(payloads) == 0 {
		return nil, errors.New("empty batch")
	}
	q.mu.Lock()
	defer q.mu.Unlock()
	pending, failed, err := q.count()
	if err != nil {
		return nil, err
	}
	if pending+failed+len(payloads) > q.max {
		q.rejected.Add(1)
		return nil, ErrCapacity
	}
	names := make([]string, 0, len(payloads))
	ids := make([]string, 0, len(payloads))
	for _, payload := range payloads {
		if !json.Valid(payload) {
			return nil, errors.New("invalid spool payload")
		}
	}
	for _, payload := range payloads {
		batchID := id()
		envelope := Envelope{ID: batchID, CreatedAt: float64(time.Now().UnixNano()) / 1e9, Payload: payload}
		body, err := json.Marshal(envelope)
		if err != nil {
			rollback(names)
			return nil, err
		}
		name := filepath.Join(q.root, "queue", fmt.Sprintf("%d-%s.json", time.Now().Unix(), batchID))
		if err = writeAtomic(name, append(body, '\n')); err != nil {
			rollback(names)
			return nil, err
		}
		names = append(names, name)
		ids = append(ids, batchID)
	}
	return ids, nil
}
func rollback(paths []string) {
	for _, path := range paths {
		_ = os.Remove(path)
	}
	if len(paths) > 0 {
		_ = syncDir(filepath.Dir(paths[0]))
	}
}

// Pending lists raw envelopes in lexical order. No upload worker exists in this stage.
func (q *Queue) Pending() ([]string, error) {
	q.mu.Lock()
	defer q.mu.Unlock()
	files, err := filepath.Glob(filepath.Join(q.root, "queue", "*.json"))
	sort.Strings(files)
	return files, err
}
func (q *Queue) Read(path string) (Envelope, error) {
	if filepath.Dir(path) != filepath.Join(q.root, "queue") {
		return Envelope{}, errors.New("invalid queue path")
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return Envelope{}, err
	}
	var e Envelope
	if err = json.Unmarshal(data, &e); err != nil {
		return e, err
	}
	if e.ID == "" || len(e.Payload) == 0 || !json.Valid(e.Payload) {
		return e, errors.New("corrupt envelope")
	}
	return e, nil
}
func (q *Queue) Fail(path, reason string) error {
	q.mu.Lock()
	defer q.mu.Unlock()
	if filepath.Dir(path) != filepath.Join(q.root, "queue") {
		return errors.New("invalid queue path")
	}
	reason = strings.Map(func(r rune) rune {
		if r >= 'a' && r <= 'z' || r == '-' {
			return r
		}
		return '-'
	}, reason)
	target := filepath.Join(q.root, "failed", fmt.Sprintf("%d-%s-%s", time.Now().UnixNano(), reason, filepath.Base(path)))
	if err := os.Rename(path, target); err != nil {
		return err
	}
	if err := syncDir(filepath.Dir(target)); err != nil {
		return err
	}
	return syncDir(filepath.Dir(path))
}

// CountAt inspects spool without claiming its lock so status works while daemon is active.
func CountAt(root string) (int, int, error) {
	pending, err := filepath.Glob(filepath.Join(root, "queue", "*.json"))
	if err != nil {
		return 0, 0, err
	}
	processing, err := filepath.Glob(filepath.Join(root, "processing", "*.json"))
	if err != nil {
		return 0, 0, err
	}
	failed, err := filepath.Glob(filepath.Join(root, "failed", "*.json"))
	if err != nil {
		return 0, 0, err
	}

	return len(pending) + len(processing), len(failed), nil
}
