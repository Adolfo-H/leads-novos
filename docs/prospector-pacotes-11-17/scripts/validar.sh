#!/usr/bin/env bash
set -Eeuo pipefail

STAGE="${1:-}"
if [[ ! "$STAGE" =~ ^(11|12|13|14|15|16|17|all)$ ]]; then
  echo 'Uso: bash docs/prospector-pacotes-11-17/scripts/validar.sh {11|12|13|14|15|16|17|all}' >&2
  exit 2
fi
if ! git rev-parse --show-toplevel >/dev/null 2>&1; then
  echo 'Entre no repositório do Prospector.' >&2; exit 2
fi
cd "$(git rev-parse --show-toplevel)"
[[ -x ./vendor/bin/sail ]] || { echo 'Laravel Sail ausente. Instale as dependências existentes primeiro.' >&2; exit 2; }

echo "===== VALIDANDO PACOTE $STAGE — SEM ESCRITAS EXTERNAS ====="
run() { printf '\n>>> '; printf '%q ' "$@"; printf '\n'; "$@"; }

# Pint em modo somente leitura e PHPStan nos pacotes que alteram PHP.
case "$STAGE" in
  11) TESTS=(tests/Feature/Companies tests/Feature/Integrity) ;;
  12) TESTS=(tests/Feature/HubSpot tests/Feature/Security) ;;
  13) TESTS=(tests/Feature/Campaigns tests/Feature/Contacts) ;;
  14) TESTS=(tests/Feature/Prospecting tests/Feature/Imports) ;;
  15) TESTS=(tests/Feature/Infrastructure tests/Feature/HubSpot) ;;
  16) TESTS=(tests/Feature/Security tests/Feature/Contacts tests/Feature/Campaigns) ;;
  17|all) TESTS=() ;;
esac

if [[ "$STAGE" == 'all' || "$STAGE" == '17' ]]; then
  run ./vendor/bin/sail composer ci:check
else
  run ./vendor/bin/sail bin pint --test
  run ./vendor/bin/sail composer types:check
  run ./vendor/bin/sail artisan test "${TESTS[@]}"
fi

if [[ "$STAGE" == '14' || "$STAGE" == 'all' || "$STAGE" == '17' ]]; then
  if docker compose ps --services --status running | grep -qx 'receita-data'; then
    run docker compose exec -T receita-data python -m unittest discover -s /app -p 'test_*.py'
  else
    echo 'PENDÊNCIA: receita-data não está rodando; testes Python da Receita não foram executados.'
  fi
fi

if [[ "$STAGE" == '15' || "$STAGE" == 'all' || "$STAGE" == '17' ]]; then
  run bash -n start.sh
  run docker compose config --quiet
  if [[ -d tools/ops ]]; then
    while IFS= read -r -d '' file; do run bash -n "$file"; done < <(find tools/ops -type f -name '*.sh' -print0)
  fi
fi

if [[ "$STAGE" == '16' || "$STAGE" == 'all' || "$STAGE" == '17' ]]; then
  run ./vendor/bin/sail npm run build
  echo 'PENDÊNCIA OPCIONAL: executar npm audit e composer audit em ambiente com acesso de rede.'
fi

run git diff --check
printf '\n===== PACOTE %s: VALIDAÇÕES EXECUTADAS =====\n' "$STAGE"
echo 'ATENÇÃO: testes externos de produção, aprovação de migration e homologação manual NÃO foram executados.'
