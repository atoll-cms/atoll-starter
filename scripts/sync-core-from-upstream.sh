#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL_CORE_PATH="${LOCAL_CORE_PATH:-$ROOT_DIR/core}"

MODE="sync"
if [[ "${1:-}" == "--check" ]]; then
  MODE="check"
  shift
fi

UPSTREAM_CORE_PATH="${UPSTREAM_CORE_PATH:-${1:-}}"
if [[ -z "$UPSTREAM_CORE_PATH" ]]; then
  echo "Usage: $(basename "$0") [--check] <path-to-atoll-core>" >&2
  exit 2
fi

if [[ ! -d "$UPSTREAM_CORE_PATH" ]]; then
  echo "Upstream core directory not found: $UPSTREAM_CORE_PATH" >&2
  exit 2
fi

if [[ ! -f "$UPSTREAM_CORE_PATH/src/bootstrap.php" ]]; then
  echo "Upstream path does not look like atoll-core root: $UPSTREAM_CORE_PATH" >&2
  exit 2
fi

if [[ ! -d "$LOCAL_CORE_PATH" ]]; then
  echo "Local core directory not found: $LOCAL_CORE_PATH" >&2
  exit 2
fi

COMMON_EXCLUDES=(
  "--exclude=.git/"
  "--exclude=vendor/"
  "--exclude=node_modules/"
  "--exclude=cache/"
  "--exclude=dist/"
  "--exclude=.DS_Store"
)

if [[ "$MODE" == "check" ]]; then
  TMP_RAW="$(mktemp)"
  TMP_FILTERED="$(mktemp)"
  trap 'rm -f "$TMP_RAW" "$TMP_FILTERED"' EXIT

  rsync -rcn --delete "${COMMON_EXCLUDES[@]}" "$UPSTREAM_CORE_PATH/" "$LOCAL_CORE_PATH/" >"$TMP_RAW"

  grep -vE '^(sending incremental file list|sent |total size is |$)' "$TMP_RAW" >"$TMP_FILTERED" || true

  if [[ -s "$TMP_FILTERED" ]]; then
    echo "Core drift detected between upstream and bundled core:" >&2
    cat "$TMP_FILTERED" >&2
    echo >&2
    echo "To sync locally:" >&2
    echo "  ./scripts/sync-core-from-upstream.sh $UPSTREAM_CORE_PATH" >&2
    exit 1
  fi

  echo "Bundled core is in sync with upstream: $UPSTREAM_CORE_PATH"
  exit 0
fi

rsync -a --delete "${COMMON_EXCLUDES[@]}" "$UPSTREAM_CORE_PATH/" "$LOCAL_CORE_PATH/"
echo "Bundled core synced from: $UPSTREAM_CORE_PATH"
