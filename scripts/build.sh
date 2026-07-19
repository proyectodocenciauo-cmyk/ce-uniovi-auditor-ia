#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
PLUGIN_ZIP="$DIST/CE-IA-Auditor-0.10.1.zip"
SOURCE_ZIP="$DIST/ce-uniovi-auditor-ia-0.10.1-source.zip"

mkdir -p "$DIST"
rm -f "$PLUGIN_ZIP" "$SOURCE_ZIP"

(
  cd "$ROOT/wordpress"
  zip -q -r "$PLUGIN_ZIP" ce-ia-auditor \
    -x '*/.DS_Store' '*/__MACOSX/*'
)

(
  cd "$ROOT"
  zip -q -r "$SOURCE_ZIP" . \
    -x 'dist/*' '.git/*' '*/__pycache__/*' '*.pyc' '*/build/*' '*/dist/*' '*.egg-info/*' '.venv/*' '.env' '.env.*' '**/.env' '**/.env.*'
)

printf '%s\n' "Creados:" "$PLUGIN_ZIP" "$SOURCE_ZIP"

