#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo 'Run as root (sudo)' >&2
  exit 1
fi

version="${DOZOR_VERSION:?Set DOZOR_VERSION to a published release tag (for example v1.0.0)}"
case "${version}" in v[0-9]* ) ;; *) echo "Invalid version tag" >&2; exit 1;; esac
case "$(uname -s)" in Linux) platform=linux;; Darwin) platform=darwin;; *) echo 'Unsupported OS' >&2; exit 1;; esac
case "$(uname -m)" in x86_64|amd64) arch=amd64;; aarch64|arm64) arch=arm64;; *) echo 'Unsupported CPU' >&2; exit 1;; esac

base="https://github.com/pepperfm/dozor-agent/releases/download/${version}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
curl -fsSL --retry 3 -o "$tmp/dozor-agent-${platform}-${arch}" "$base/dozor-agent-${platform}-${arch}"
curl -fsSL --retry 3 -o "$tmp/SHA256SUMS" "$base/SHA256SUMS"
(
  cd "$tmp"
  # Checksums for all release binaries are validated before installation.
  grep "  dozor-agent-${platform}-${arch}$" SHA256SUMS | sha256sum -c -
)
install -m 0755 "$tmp/dozor-agent-${platform}-${arch}" /usr/local/bin/dozor-agent
if [[ "$platform" == linux ]]; then
  if ! getent group dozor >/dev/null; then groupadd --system dozor; fi
  if ! id dozor >/dev/null 2>&1; then
    useradd --system --gid dozor --home-dir /var/lib/dozor-agent --no-create-home --shell /usr/sbin/nologin dozor
  fi
  install -d -m 0700 -o dozor -g dozor /var/lib/dozor-agent
  install -d -m 0700 -o root -g root /etc/dozor-agent
fi
echo "Installed dozor-agent ${version} (${platform}/${arch})."
echo "Configure /etc/dozor-agent/agent.env then install/enable the systemd unit."
