#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="simple-coming-soon-mode"
ZIP_PATH="${ROOT_DIR}/${PLUGIN_SLUG}.zip"

cd "$ROOT_DIR"

echo "Building ${ZIP_PATH}..."
rm -f "$ZIP_PATH"

zip -r "$ZIP_PATH" . \
    -x "*.git*" "*.DS_Store" "*${PLUGIN_SLUG}.zip" "package.sh" ".idea*" ".vscode*" \
    >/dev/null

echo "Done. Zip created at ${ZIP_PATH}"
