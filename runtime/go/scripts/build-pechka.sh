#!/usr/bin/env bash
# Сборка минимальных Linux-бинарников для Печки без GitHub Actions.
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
  sha256sum dozor-agent-linux-amd64 dozor-agent-linux-arm64 > SHA256SUMS
  sha256sum -c SHA256SUMS
)
echo "Готово: $out"
echo "Опубликуйте бинарники по HTTPS и скопируйте SHA256 в конфигурацию Печки:"
cat "$out/SHA256SUMS"
