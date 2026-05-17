#!/bin/zsh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_SCRIPT="$SCRIPT_DIR/sort_contracts.php"

if [[ ! -f "$PHP_SCRIPT" ]]; then
  echo "No se encuentra $PHP_SCRIPT" >&2
  exit 1
fi

/usr/bin/env php "$PHP_SCRIPT" "$@"
