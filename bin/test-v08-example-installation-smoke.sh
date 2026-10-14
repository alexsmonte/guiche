#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="$(command -v php8.4 || command -v php)"
TMP="$(mktemp -d)"

cleanup() {
    rm -rf "$TMP"
}
trap cleanup EXIT

cd "$TMP"

env \
    GUICHE_CLINICAL_MODE=fake \
    GUICHE_CLINIC_COMPANY_ID=1 \
    GUICHE_CLINIC_NAME="Clínica Exemplo" \
    GUICHE_AGENDAMENTO_API_URL= \
    GUICHE_AGENDAMENTO_API_TOKEN= \
    OPENAI_API_KEY= \
    DB_DSN= \
    DB_DATABASE= \
    DB_USERNAME= \
    DB_PASSWORD= \
    "$PHP_BIN" "$ROOT/examples/clinica-piloto/fake-smoke.php"

echo "OK: exemplo executa em Fake Mode mesmo com cwd externo ao repositório."
