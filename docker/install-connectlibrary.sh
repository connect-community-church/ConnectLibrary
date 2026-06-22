#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${SCRIPT_DIR}"

COMPOSE=(docker compose -f compose.yaml)
ZIP_PATH="${ROOT_DIR}/dist/connectlibrary.zip"

if [ ! -f "${ZIP_PATH}" ]; then
  bash "${ROOT_DIR}/bin/build-zip.sh"
fi

"${COMPOSE[@]}" run --rm wpcli plugin install /workspace/connectlibrary/dist/connectlibrary.zip --force --activate
"${COMPOSE[@]}" run --rm wpcli plugin status connectlibrary
