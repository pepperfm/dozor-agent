package transform

import (
	"bytes"
	"encoding/json"
)

// orderedObject is used only for PHP fields whose insertion order affects
// generated metadata. Ordinary trace maps remain plain Go maps.
type orderedObject struct {
	keys   []string
	values obj
}

func encodeNoHTML(value any) ([]byte, error) {
	var buffer bytes.Buffer
	encoder := json.NewEncoder(&buffer)
	encoder.SetEscapeHTML(false)
	if err := encoder.Encode(value); err != nil {
		return nil, err
	}
	return bytes.TrimSuffix(buffer.Bytes(), []byte("\n")), nil
}

func (o orderedObject) MarshalJSON() ([]byte, error) {
	var buffer bytes.Buffer
	buffer.WriteByte('{')
	for index, key := range o.keys {
		if index > 0 {
			buffer.WriteByte(',')
		}
		k, err := encodeNoHTML(key)
		if err != nil {
			return nil, err
		}
		v, err := encodeNoHTML(o.values[key])
		if err != nil {
			return nil, err
		}
		buffer.Write(k)
		buffer.WriteByte(':')
		buffer.Write(v)
	}
	buffer.WriteByte('}')
	return buffer.Bytes(), nil
}

func decodeOrdered(raw json.RawMessage) (any, error) {
	if len(raw) == 0 {
		return nil, nil
	}
	switch raw[0] {
	case '{':
		decoder := json.NewDecoder(bytes.NewReader(raw))
		if _, err := decoder.Token(); err != nil {
			return nil, err
		}
		ordered := orderedObject{keys: []string{}, values: obj{}}
		for decoder.More() {
			keyToken, err := decoder.Token()
			if err != nil {
				return nil, err
			}
			key := keyToken.(string)
			var nested json.RawMessage
			if err := decoder.Decode(&nested); err != nil {
				return nil, err
			}
			value, err := decodeOrdered(nested)
			if err != nil {
				return nil, err
			}
			if _, exists := ordered.values[key]; !exists {
				ordered.keys = append(ordered.keys, key)
			}
			ordered.values[key] = value
		}
		if _, err := decoder.Token(); err != nil {
			return nil, err
		}
		return ordered, nil
	case '[':
		var rawItems []json.RawMessage
		if err := json.Unmarshal(raw, &rawItems); err != nil {
			return nil, err
		}
		items := make([]any, 0, len(rawItems))
		for _, item := range rawItems {
			v, err := decodeOrdered(item)
			if err != nil {
				return nil, err
			}
			items = append(items, v)
		}
		return items, nil
	default:
		var value any
		err := json.Unmarshal(raw, &value)
		return value, err
	}
}

func restoreOrderedFields(raw json.RawMessage, records []obj) error {
	var items []json.RawMessage
	if err := json.Unmarshal(raw, &items); err != nil {
		return err
	}
	for index, item := range items {
		var source objRaw
		if err := json.Unmarshal(item, &source); err != nil {
			return err
		}
		payloadRaw, exists := source["payload"]
		if !exists {
			continue
		}
		var fields objRaw
		if err := json.Unmarshal(payloadRaw, &fields); err != nil {
			// Original validation rejects non-object payloads later.
			continue
		}
		payload := asObj(get(records[index], "payload"))
		for _, key := range []string{"headers", "request_headers", "response_headers", "request_payload"} {
			fieldRaw, exists := fields[key]
			if !exists {
				continue
			}
			value, err := decodeOrdered(fieldRaw)
			if err != nil {
				return err
			}
			payload[key] = value
		}
	}
	return nil
}

type objRaw = map[string]json.RawMessage
