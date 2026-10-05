#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null
php tests/smoke.php
php tests/gateway_smoke.php
php tests/gateway_host_regression.php
php tests/fuzz_smoke.php
php tests/memory_smoke.php
bash tests/gateway_integration.sh
bash tests/integration.sh
