// Package envfile safely reads the Dozor subset of a Laravel .env file.
// It deliberately never sources shell code or expands user-provided variables.
package envfile

import (
	"bufio"
	"fmt"
	"os"
	"strings"
)

func FromArgs(args []string) (string, bool, error) {
	for i, arg := range args {
		if strings.HasPrefix(arg, "--env-file=") {
			path := strings.TrimPrefix(arg, "--env-file=")
			if path == "" {
				return "", false, fmt.Errorf("--env-file requires a path")
			}
			return path, true, nil
		}
		if arg == "--env-file" {
			if i+1 >= len(args) || strings.HasPrefix(args[i+1], "--") {
				return "", false, fmt.Errorf("--env-file requires a path")
			}
			return args[i+1], true, nil
		}
	}
	return "", false, nil
}

func LoadMissing(path string) error {
	file, err := os.Open(path)
	if err != nil {
		return err
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 4096), 1024*1024)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimPrefix(line, "export ")
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		key = strings.TrimSpace(key)
		if !(strings.HasPrefix(key, "DOZOR_") || key == "APP_ENV" || key == "APP_NAME") {
			continue
		}
		if !validKey(key) {
			return fmt.Errorf("invalid variable name in env-file")
		}
		if _, present := os.LookupEnv(key); present {
			continue
		}
		value = strings.TrimSpace(value)
		if len(value) > 0 && (value[0] == '"' || value[0] == '\'') {
			quote := value[0]
			if len(value) < 2 || value[len(value)-1] != quote {
				return fmt.Errorf("unterminated quoted value for %s", key)
			}
			value = value[1 : len(value)-1]
			if quote == '"' {
				value = strings.ReplaceAll(value, "\\n", "\n")
				value = strings.ReplaceAll(value, "\\\"", "\"")
			}
		} else if marker := strings.Index(value, " #"); marker >= 0 {
			value = strings.TrimSpace(value[:marker])
		}
		if err := os.Setenv(key, value); err != nil {
			return fmt.Errorf("invalid environment variable %s: %w", key, err)
		}
	}
	return scanner.Err()
}

func validKey(key string) bool {
	for _, r := range key {
		if r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || r == '_' {
			continue
		}
		return false
	}
	return key != ""
}
