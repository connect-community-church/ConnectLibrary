#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${SCRIPT_DIR}"

COMPOSE=(docker compose -f compose.yaml)

bash "${ROOT_DIR}/bin/build-zip.sh"
"${COMPOSE[@]}" config >/dev/null
bash "${SCRIPT_DIR}/start-local.sh"

printf 'Running PHP syntax checks in Docker PHP...\n'
while IFS= read -r -d '' file; do
  rel="${file#${ROOT_DIR}/}"
  "${COMPOSE[@]}" run --rm --entrypoint php wordpress -l "/workspace/connectlibrary/${rel}" </dev/null
done < <(
  find "${ROOT_DIR}" \
    -path "${ROOT_DIR}/.git" -prune -o \
    -path "${ROOT_DIR}/build" -prune -o \
    -path "${ROOT_DIR}/dist" -prune -o \
    -path "${ROOT_DIR}/node_modules" -prune -o \
    -path "${ROOT_DIR}/vendor" -prune -o \
    -name '*.php' -print0 | sort -z
)

"${COMPOSE[@]}" run --rm wpcli plugin install /workspace/connectlibrary/dist/connectlibrary.zip --force --activate
"${COMPOSE[@]}" run --rm wpcli plugin status connectlibrary
curl -fsS "http://127.0.0.1:${CONNECTLIBRARY_WP_PORT:-8080}/wp-json/" >/dev/null
"${COMPOSE[@]}" run --rm wpcli plugin deactivate connectlibrary
"${COMPOSE[@]}" run --rm wpcli plugin status connectlibrary
printf 'ConnectLibrary Docker smoke test passed.\n'
