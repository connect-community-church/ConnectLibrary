#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

while IFS= read -r -d '' file; do
  rel="${file#${ROOT_DIR}/}"
  php -l "${file}" >/dev/null
  printf 'PHP lint OK: %s\n' "${rel}"
done < <(
  find "${ROOT_DIR}" \
    -path "${ROOT_DIR}/.git" -prune -o \
    -path "${ROOT_DIR}/build" -prune -o \
    -path "${ROOT_DIR}/dist" -prune -o \
    -path "${ROOT_DIR}/node_modules" -prune -o \
    -path "${ROOT_DIR}/vendor" -prune -o \
    -name '*.php' -print0 | sort -z
)