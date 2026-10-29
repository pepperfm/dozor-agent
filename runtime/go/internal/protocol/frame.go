package protocol

import (
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strconv"
	"strings"
)

const MaxFrameBytes = 1_048_576

var ErrInvalidFrame = errors.New("invalid ingest frame")

// Decode reads precisely one PHP SDK v1 frame: <length>:v1:<token-hash>:<payload>.
// The length counts bytes after the leading colon, not UTF-8 codepoints.
func Decode(reader io.Reader, expectedHash string) ([]json.RawMessage, bool, error) {
	var prefix [1]byte
	digits := make([]byte, 0, 8)
	for {
		if _, err := io.ReadFull(reader, prefix[:]); err != nil {
			return nil, false, fmt.Errorf("read frame length: %w", err)
		}
		if prefix[0] == ':' {
			break
		}
		if prefix[0] < '0' || prefix[0] > '9' || len(digits) == 8 {
			return nil, false, ErrInvalidFrame
		}
		digits = append(digits, prefix[0])
	}
	if len(digits) == 0 {
		return nil, false, ErrInvalidFrame
	}
	length, err := strconv.Atoi(string(digits))
	if err != nil || length == 0 || length > MaxFrameBytes {
		return nil, false, ErrInvalidFrame
	}
	body := make([]byte, length)
	if _, err := io.ReadFull(reader, body); err != nil {
		return nil, false, fmt.Errorf("read frame: %w", err)
	}
	parts := strings.SplitN(string(body), ":", 3)
	if len(parts) != 3 || parts[0] != "v1" || expectedHash == "" ||
		subtle.ConstantTimeCompare([]byte(parts[1]), []byte(expectedHash)) != 1 {
		return nil, false, ErrInvalidFrame
	}
	if parts[2] == "PING" {
		return nil, true, nil
	}
	if !json.Valid([]byte(parts[2])) {
		return nil, false, ErrInvalidFrame
	}
	var records []json.RawMessage
	if err := json.Unmarshal([]byte(parts[2]), &records); err != nil || records == nil {
		return nil, false, ErrInvalidFrame
	}
	for _, record := range records {
		if len(record) == 0 || record[0] != '{' {
			return nil, false, ErrInvalidFrame
		}
	}
	return records, false, nil
}
