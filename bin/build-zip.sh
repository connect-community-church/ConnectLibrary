#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${ROOT_DIR}/build/package"
PACKAGE_DIR="${BUILD_DIR}/connectlibrary"
ZIP_PATH="${DIST_DIR}/connectlibrary.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${PACKAGE_DIR}" "${DIST_DIR}"

copy_path() {
  local source="$1"
  if [[ -e "${ROOT_DIR}/${source}" ]]; then
    mkdir -p "${PACKAGE_DIR}/$(dirname "${source}")"
    cp -R "${ROOT_DIR}/${source}" "${PACKAGE_DIR}/${source}"
  fi
}

copy_path "connectlibrary.php"
copy_path "README.md"
copy_path "docs"
copy_path "includes"
copy_path "assets"
copy_path "languages"

rm -f "${ZIP_PATH}"

if command -v zip >/dev/null 2>&1; then
  (
    cd "${BUILD_DIR}"
    zip -qr "${ZIP_PATH}" connectlibrary
  )
elif command -v python3 >/dev/null 2>&1; then
  python3 - "${BUILD_DIR}" "${ZIP_PATH}" <<'PY'
import os
import sys
import zipfile

build_dir, zip_path = sys.argv[1], sys.argv[2]
root = os.path.join(build_dir, 'connectlibrary')
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
    for current, dirs, files in os.walk(root):
        dirs.sort()
        for filename in sorted(files):
            path = os.path.join(current, filename)
            archive.write(path, os.path.relpath(path, build_dir))
PY
else
  echo "Error: build requires either zip or python3." >&2
  exit 1
fi

rm -rf "${BUILD_DIR}"
echo "Created ${ZIP_PATH}"
