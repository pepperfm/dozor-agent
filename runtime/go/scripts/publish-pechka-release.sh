#!/usr/bin/env bash
# Publish verified Linux binaries to GitHub Releases, without GitHub Actions.
# Run from a clean local dozor-agent checkout on macOS or Linux.
set -euo pipefail

tag="${1:?Использование: ./scripts/publish-pechka-release.sh v0.2.0-pechka.1}"
repo="${DOZOR_RELEASE_REPO:-pepperfm/dozor-agent}"

command -v gh >/dev/null 2>&1 || { echo "Требуется GitHub CLI (gh auth login)." >&2; exit 2; }
command -v go >/dev/null 2>&1 || { echo "Требуется Go 1.23+ для сборки." >&2; exit 2; }

cd "$(dirname "${BASH_SOURCE[0]}")/.."
root="$(git rev-parse --show-toplevel)"
[ -z "$(git -C "$root" status --porcelain)" ] || {
  echo "Git checkout не чистый. Сначала зафиксируйте изменения." >&2
  exit 2
}
[ "$(git -C "$root" branch --show-current)" = "master" ] || {
  echo "Релиз собирается только с master." >&2
  exit 2
}
commit="$(git -C "$root" rev-parse HEAD)"
git -C "$root" fetch origin master --quiet
[ "$commit" = "$(git -C "$root" rev-parse origin/master)" ] || {
  echo "Локальный master отличается от origin/master. Выполните git pull." >&2
  exit 2
}
if git -C "$root" ls-remote --exit-code --tags origin "refs/tags/$tag" >/dev/null 2>&1; then
  echo "Тег $tag уже опубликован. Используйте новый тег релиза." >&2
  exit 2
fi
if gh release view "$tag" -R "$repo" >/dev/null 2>&1; then
  echo "Релиз $tag уже существует — не перезаписываем опубликованные бинарники." >&2
  exit 2
fi

go test ./...
output="dist/pechka/$tag"
bash scripts/build-pechka.sh "$output"
output="$(cd "$output" && pwd)"

gh release create "$tag" \
  "$output/dozor-agent-linux-amd64" \
  "$output/dozor-agent-linux-arm64" \
  "$output/SHA256SUMS" \
  -R "$repo" --target "$commit" \
  --title "Dozor Agent $tag" \
  --notes "Linux Go Agent binaries (amd64/arm64) for the Pechka-managed installer; hashes in SHA256SUMS."

amd64="$(awk '$2 == "dozor-agent-linux-amd64" { print $1 }' "$output/SHA256SUMS")"
arm64="$(awk '$2 == "dozor-agent-linux-arm64" { print $1 }' "$output/SHA256SUMS")"
[ "${#amd64}" -eq 64 ] && [ "${#arm64}" -eq 64 ] || {
  echo "Ошибка контрольных сумм в сборке." >&2; exit 2
}

printf '\nДобавьте в .env Печки (НЕ в .env наблюдаемого сайта):\n'
printf 'PECHKA_DOZOR_AGENT_FEATURE_ENABLED=true\n'
printf 'PECHKA_DOZOR_AGENT_AMD64_URL=https://github.com/%s/releases/download/%s/dozor-agent-linux-amd64\n' "$repo" "$tag"
printf 'PECHKA_DOZOR_AGENT_AMD64_SHA256=%s\n' "$amd64"
printf 'PECHKA_DOZOR_AGENT_ARM64_URL=https://github.com/%s/releases/download/%s/dozor-agent-linux-arm64\n' "$repo" "$tag"
printf 'PECHKA_DOZOR_AGENT_ARM64_SHA256=%s\n' "$arm64"
printf 'PECHKA_DOZOR_SDK_SHA=%s\n' "$commit"
printf '\nРелиз: https://github.com/%s/releases/tag/%s\n' "$repo" "$tag"
