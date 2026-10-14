#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="$(command -v php8.4 || command -v php)"
cd "$ROOT"

printf '\nGUICHÊ v0.8.0 — ACCEPTANCE CONSOLIDADO\n'
printf '%s\n\n' '=============================================='

printf '%s\n' '--- Fase 02 / Clinical Extraction'
"$PHP_BIN" bin/test-v08-phase02-clinical-extraction.php

printf '\n%s\n' '--- Fases 03–06 / Clinical Acceptance'
bash bin/test-v08-clinical-acceptance.sh

printf '\n%s\n' 'OK: GUICHÊ v0.8.0 — ACCEPTANCE CONSOLIDADO COMPLETO.'
