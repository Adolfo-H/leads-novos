#!/usr/bin/env bash
set -Eeuo pipefail

if ! git rev-parse --show-toplevel >/dev/null 2>&1; then
  echo 'ERRO: execute este comando dentro do repositório Git do Prospector.' >&2
  exit 2
fi
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
EXPECTED='docs/prospector-pacotes-11-17/00_LEIA_PRIMEIRO.md'
if [[ ! -f "$EXPECTED" || ! -f artisan ]]; then
  echo 'ERRO: pasta da entrega não localizada no projeto Laravel.' >&2
  exit 2
fi

echo '===== PROSPECTOR — PRÉ-VOO (SOMENTE LEITURA) ====='
printf 'Branch: '; git branch --show-current
printf 'Commit: '; git rev-parse --short HEAD 2>/dev/null || echo '(repositório sem commit inicial)'
printf 'Remoto: '; git remote get-url origin | sed -E 's#(https?://)[^@/]+@#\1***@#' || true
printf 'Alterações fora da documentação desta entrega:\n'
STATUS="$(git status --porcelain=v1 --untracked-files=all | grep -vE '^\?\? docs/prospector-pacotes-11-17(/|$)' || true)"
if [[ -n "$STATUS" ]]; then
  echo "$STATUS"
  echo 'ATENÇÃO: há outras mudanças locais. Não sobrescrever nem fazer reset automaticamente.'
else
  echo 'Nenhuma outra mudança aparente.'
fi

echo; echo '===== FERRAMENTAS ====='
for tool in bash git python3 docker; do
  if command -v "$tool" >/dev/null 2>&1; then
    printf '%-12s OK\n' "$tool"
  else
    printf '%-12s AUSENTE\n' "$tool"
  fi
done
[[ -x ./vendor/bin/sail ]] && echo 'Laravel Sail: disponível' || echo 'Laravel Sail: ausente; confira as dependências antes de testar.'

echo; echo '===== CAMINHOS CRÍTICOS ====='
for f in \
 app/Services/IcpScoringService.php \
 app/Services/HubSpotLeadSyncService.php \
 app/Services/Campaigns/CampaignManager.php \
 app/Jobs/SendEmailCampaignRecipient.php \
 tools/receita/api.py \
 tools/receita/prospect_query.py \
 compose.yaml \
 start.sh; do
  [[ -f "$f" ]] && echo "OK  $f" || echo "VERIFICAR  $f"
done

echo; echo '===== DIFF CHECK ====='
git diff --check
printf '\nPré-voo concluído. Nenhum token, dado empresarial ou credencial foi exibido.\n'
