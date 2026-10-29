package state

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type Snapshot struct {
	Lifecycle struct {
		Status          string `json:"status"`
		StartedAt       string `json:"started_at,omitempty"`
		LastHeartbeatAt string `json:"last_heartbeat_at,omitempty"`
		StoppedAt       string `json:"stopped_at,omitempty"`
		UpdatedAt       string `json:"updated_at"`
	} `json:"lifecycle"`
	Context struct {
		Runtime        string `json:"runtime"`
		ListenOn       string `json:"listen_on"`
		SpoolPath      string `json:"spool_path,omitempty"`
		AppName        string `json:"app,omitempty"`
		ServerName     string `json:"server,omitempty"`
		Environment    string `json:"environment,omitempty"`
		ShipperEnabled bool   `json:"shipper_enabled"`
	} `json:"context"`
	Metrics struct {
		QueueDepth       int    `json:"queue_depth"`
		FailedQueueDepth int    `json:"failed_queue_depth"`
		UptimeSeconds    int64  `json:"uptime_seconds"`
		DeliveredBatches uint64 `json:"delivered_batches"`
		FailedUploads    uint64 `json:"failed_uploads"`
		ReceivedRecords  uint64 `json:"received_records"`
		RejectedClients  uint64 `json:"rejected_clients"`
	} `json:"metrics"`
}
type Writer struct {
	Path           string
	SpoolPath      string
	AppName        string
	ServerName     string
	Environment    string
	ShipperEnabled bool
	mu             sync.Mutex
	startedAt      time.Time
}

func (w *Writer) Mark(status, listen string, queued, failed int, received, rejected, delivered, uploads uint64) error {
	w.mu.Lock()
	defer w.mu.Unlock()
	now := time.Now().UTC()
	if w.startedAt.IsZero() {
		w.startedAt = now
	}
	snapshot := Snapshot{}
	snapshot.Lifecycle.Status = status
	snapshot.Lifecycle.StartedAt = w.startedAt.Format(time.RFC3339)
	snapshot.Lifecycle.LastHeartbeatAt = now.Format(time.RFC3339)
	snapshot.Lifecycle.UpdatedAt = now.Format(time.RFC3339)
	if status == "stopped" {
		snapshot.Lifecycle.StoppedAt = now.Format(time.RFC3339)
	}
	if w.ShipperEnabled {
		snapshot.Context.Runtime = "go"
	} else {
		snapshot.Context.Runtime = "go-ingest-only"
	}
	snapshot.Context.ListenOn = listen
	snapshot.Context.SpoolPath = w.SpoolPath
	snapshot.Context.AppName = w.AppName
	snapshot.Context.ServerName = w.ServerName
	snapshot.Context.Environment = w.Environment
	snapshot.Context.ShipperEnabled = w.ShipperEnabled
	snapshot.Metrics.QueueDepth = queued
	snapshot.Metrics.FailedQueueDepth = failed
	snapshot.Metrics.UptimeSeconds = int64(now.Sub(w.startedAt).Seconds())
	snapshot.Metrics.ReceivedRecords = received
	snapshot.Metrics.RejectedClients = rejected
	snapshot.Metrics.DeliveredBatches = delivered
	snapshot.Metrics.FailedUploads = uploads
	return w.write(snapshot)
}
func (w *Writer) write(snapshot Snapshot) error {
	if w.Path == "" {
		return errors.New("runtime state path required")
	}
	dir := filepath.Dir(w.Path)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return err
	}
	f, err := os.CreateTemp(dir, ".dozor-state-*")
	if err != nil {
		return err
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	if err = f.Chmod(0600); err != nil {
		f.Close()
		return err
	}
	err = json.NewEncoder(f).Encode(snapshot)
	if err == nil {
		err = f.Sync()
	}
	if e := f.Close(); err == nil {
		err = e
	}
	if err != nil {
		return err
	}
	if err = os.Rename(tmp, w.Path); err != nil {
		return err
	}
	folder, err := os.Open(dir)
	if err != nil {
		return err
	}
	defer folder.Close()
	return folder.Sync()
}
func Read(path string) (Snapshot, error) {
	var s Snapshot
	body, err := os.ReadFile(path)
	if err != nil {
		return s, err
	}
	err = json.Unmarshal(body, &s)
	return s, err
}
