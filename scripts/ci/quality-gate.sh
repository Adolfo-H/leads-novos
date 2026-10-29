#!/usr/bin/env bash
# Prospector A20 — validação técnica local, sem acionar sincronizações ou envios.
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
cd "$ROOT"
if [[ ! -f artisan || ! -x ./vendor/bin/sail ]]; then
    echo "ERRO: execute no projeto Laravel com Laravel Sail instalado." >&2
    exit 1
fi
export PYTHONDONTWRITEBYTECODE=1
shopt -s nullglob
email_tests=(tools/email/test_*.py)
if (( ${#email_tests[@]} < 3 )) || [[ ! -f tools/receita/test_release_manifest.py ]]; then
    echo 'ERRO: suites Python obrigatorias ausentes; nao considerar CI verde.' >&2
    exit 1
fi

echo '=== Python e-mail: testes simulados, sem IMAP real ==='
python3 -B -m unittest discover -s tools/email -p 'test_*.py' -v

echo '=== Receita: testes no container (sem publicar base) ==='
./vendor/bin/sail exec receita-data python -B -m unittest discover -s /app -p 'test_*.py'

echo '=== Assets Vite: build ==='
./vendor/bin/sail npm run build

echo '=== Rotas Laravel: carregamento ==='
./vendor/bin/sail artisan route:list --json > /dev/null

echo '=== CI Laravel: Pint, PHPStan e testes ==='
./vendor/bin/sail composer ci:check

echo 'A20 QUALITY GATE APROVADO — validação técnica; não é homologação de produção.'
