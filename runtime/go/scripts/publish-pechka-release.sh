#!/usr/bin/env bash
# Local release from an already-pushed, clean master commit.
# No GitHub Actions or server-side Go compiler needed.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
command -v bun >/dev/null || { echo "Нужен Bun (порт SPP S3 publisher)" >&2; exit 2; }
command -v go >/dev/null || { echo "Нужен Go 1.23+" >&2; exit 2; }

root="$(git rev-parse --show-toplevel)"
if [ -n "$(git -C "$root" status --porcelain)" ]; then
  echo "Сначала зафиксируйте рабочее дерево в Git." >&2; exit 2
fi

export DOZOR_RELEASE_SHA="$(git -C "$root" rev-parse HEAD)"
[ "$(git -C "$root" branch --show-current)" = "master" ] || {
  echo "Собирать S3 release можно только из master." >&2; exit 2
}
git -C "$root" fetch origin master --quiet
[ "$DOZOR_RELEASE_SHA" = "$(git -C "$root" rev-parse origin/master)" ] || {
  echo "Сначала синхронизируйте локальный master с origin/master." >&2; exit 2
}

go test ./...
bun test scripts/publish-s3.test.mjs
bash scripts/build-pechka.sh dist/pechka
bun scripts/publish-s3.mjs
