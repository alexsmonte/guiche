#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="$(command -v php8.4 || command -v php || true)"
[[ -n "$PHP_BIN" ]] || {
    echo "ERRO: PHP CLI não encontrado." >&2
    exit 1
}

printf '\n%s\n' \
"GUICHÊ v0.8.0 — CLINICAL ACCEPTANCE" \
"=========================================="

echo
echo "--- PHPUnit clínico v0.8"
"$PHP_BIN" vendor/bin/phpunit \
    --configuration phpunit.xml.dist \
    --filter 'ClinicalExample' \
    tests/Integration

echo
echo "--- Fase 03 / Config + Fake Mode"
"$PHP_BIN" bin/test-v08-phase03-config-adapters-fake-mode.php

env \
    GUICHE_CLINICAL_MODE=fake \
    GUICHE_CLINIC_COMPANY_ID=1 \
    GUICHE_CLINIC_NAME="Clínica Exemplo" \
    DB_PROCEDURE_CONSULTAR_PACIENTE=fake_consultar_paciente \
    GUICHE_AGENDAMENTO_API_URL= \
    OPENAI_API_KEY= \
    DB_DATABASE= \
    "$PHP_BIN" examples/clinica-piloto/fake-smoke.php

echo
echo "--- Fase 04 / Clinical Flow + Write Safety"
"$PHP_BIN" bin/test-v08-phase04-clinical-flow-write-safety.php

echo
echo "--- Safety Layer v0.4 / regression"
"$PHP_BIN" bin/test-clinical-safety-v04.php

echo
echo "OK: GUICHÊ v0.8.0 — CLINICAL ACCEPTANCE COMPLETO."

# GUICHE_V08_PHASE06
printf '\n--- Fase 06 / Documentation + Isolation\n'
"$PHP_BIN" bin/test-v08-phase06-documentation-isolation.php
bash bin/test-v08-example-installation-smoke.sh
