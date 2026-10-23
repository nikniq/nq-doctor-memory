#!/usr/bin/env bash
# Checks cx.sh's config_value parser against real `php artisan config:show`
# output, which surrounds the "key .. value" line with blank lines.
#   bash tests/shell/config-value.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

eval "$(sed -n '/^config_value() {/,/^}/p' cx.sh)"

sample=$'\n  database.default ................................ sqlite\n\n'
got=$(printf '%s' "$sample" | config_value)
[ "$got" = "sqlite" ] || { echo "✗ expected 'sqlite', got '$got'"; exit 1; }

live=$(php artisan config:show database.default | config_value)
[ -n "$live" ] && [[ "$live" != *$'\n'* ]] || { echo "✗ live value '$live'"; exit 1; }

echo "✓ config_value parses config:show output ($live)"
