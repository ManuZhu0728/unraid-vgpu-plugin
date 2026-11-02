#!/bin/bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -n "${PHP_BIN:-}" ]]; then
    PHP="$PHP_BIN"
elif command -v php >/dev/null 2>&1; then
    PHP="$(command -v php)"
elif [[ -x "$ROOT/.tools/php/root/usr/bin/php8.4" ]]; then
    PHP="$ROOT/.tools/php/root/usr/bin/php8.4"
else
    echo 'PHP CLI is needed for hardware-free tests (not for driver compilation).' >&2
    exit 1
fi
export PHP_BIN="$PHP"
while IFS= read -r -d '' file; do
    "$PHP" -n -l "$file" >/dev/null
done < <(find "$ROOT/source/usr/local/emhttp/plugins/unraid-vgpu-manager" -type f \( -name '*.php' -o -name '*.page' \) -print0)
for test_file in "$ROOT"/tests/test_*.php; do
    "$PHP" -n "$test_file"
done
python3 -m unittest discover -s "$ROOT/tests" -p 'test_*.py' -v
echo 'All hardware-free tests passed; no real GPU or Unraid service was used.'
