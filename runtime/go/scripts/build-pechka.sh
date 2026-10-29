#!/usr/bin/env bash
# Reproducible Linux artifacts for Pechka. Works on macOS and Linux.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
out="${1:-dist/pechka}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"

for arch in amd64 arm64; do
  echo "Собираем linux/$arch..."
  CGO_ENABLED=0 GOOS=linux GOARCH="$arch" go build -trimpath -ldflags='-s -w' -o "$out/dozor-agent-linux-$arch" ./cmd/dozor-agent
done

(
  cd "$out"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum dozor-agent-linux-amd64 dozor-agent-linux-arm64 > SHA256SUMS
    sha256sum -c SHA256SUMS
  else
    shasum -a 256 dozor-agent-linux-amd64 dozor-agent-linux-arm64 > SHA256SUMS
    shasum -a 256 -c SHA256SUMS
  fi
)
echo "Готово: $out"
cat "$out/SHA256SUMS"
