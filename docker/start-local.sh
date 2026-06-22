#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

load_env_file() {
  local env_file="${1}"
  local line key value

  [ -f "${env_file}" ] || return 0

  while IFS= read -r line || [ -n "${line}" ]; do
    line="${line%$'\r'}"
    [[ "${line}" =~ ^[[:space:]]*$ ]] && continue
    [[ "${line}" =~ ^[[:space:]]*# ]] && continue
    line="${line#export }"
    key="${line%%=*}"
    value="${line#*=}"

    if [[ "${key}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] && [ -z "${!key+x}" ]; then
      export "${key}=${value}"
    fi
  done < "${env_file}"
}

load_env_file .env

: "${CONNECTLIBRARY_WP_PORT:=8080}"
: "${CONNECTLIBRARY_ADMIN_USER:=admin}"
: "${CONNECTLIBRARY_ADMIN_PASSWORD:=admin}"
: "${CONNECTLIBRARY_ADMIN_EMAIL:=dev@example.test}"
: "${CONNECTLIBRARY_SITE_TITLE:=ConnectLibrary Local}"

COMPOSE=(docker compose -f compose.yaml)

"${COMPOSE[@]}" up -d db wordpress

printf 'Waiting for WordPress container HTTP health...\n'
for i in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:${CONNECTLIBRARY_WP_PORT}/wp-admin/install.php" >/dev/null 2>&1 || \
     curl -fsS "http://127.0.0.1:${CONNECTLIBRARY_WP_PORT}/" >/dev/null 2>&1; then
    break
  fi
  sleep 2
  if [ "$i" = 60 ]; then
    echo 'Timed out waiting for local WordPress HTTP.' >&2
    "${COMPOSE[@]}" ps
    exit 1
  fi
done

if ! "${COMPOSE[@]}" run --rm wpcli core is-installed >/dev/null 2>&1; then
  "${COMPOSE[@]}" run --rm wpcli core install \
    --url="http://localhost:${CONNECTLIBRARY_WP_PORT}" \
    --title="${CONNECTLIBRARY_SITE_TITLE}" \
    --admin_user="${CONNECTLIBRARY_ADMIN_USER}" \
    --admin_password="${CONNECTLIBRARY_ADMIN_PASSWORD}" \
    --admin_email="${CONNECTLIBRARY_ADMIN_EMAIL}" \
    --skip-email
fi

"${COMPOSE[@]}" run --rm wpcli core version
printf 'Local WordPress is ready at http://localhost:%s\n' "${CONNECTLIBRARY_WP_PORT}"
