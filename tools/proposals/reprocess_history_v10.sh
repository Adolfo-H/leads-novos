#!/usr/bin/env bash
set -euo pipefail
umask 077
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT="${PROSPECTOR_ROOT:-$(cd "$HERE/../.." && pwd)}"
cd "$PROJECT"
SAIL=./vendor/bin/sail
ROOT="$PROJECT/storage/app/private"
REL="proposals/history-values-v10/$(date +%Y%m%d-%H%M%S)-$$"
[[ -d "$ROOT" ]] || { echo 'Disco privado não encontrado.'; exit 1; }
mkdir -p "$ROOT/$REL"
export PYTHONDONTWRITEBYTECODE=1
printf '\n===== 1. EXPORTANDO HISTÓRICO (SOMENTE LEITURA) =====\n'
"$SAIL" php tools/proposals/history_scenarios_bridge_v10.php export "$REL/export.json"
printf '\n===== 2. REVISANDO TODAS AS PROPOSTAS IMPORTADAS =====\n'
python3 tools/proposals/history_scenarios_v10.py --export "$ROOT/$REL/export.json" --output "$ROOT/$REL/review.json" --root "$ROOT"
printf '\n===== 3. SIMULAÇÃO E INTEGRIDADE =====\n'
"$SAIL" php tools/proposals/history_scenarios_bridge_v10.php check "$REL/review.json"
echo "Relatórios privados: $ROOT/$REL"
echo 'Confira resumo.json, revisao.csv e pendencias.json. Nada foi gravado no banco.'
if [[ "${1:-}" == '--somente-simular' ]]; then exit 0; fi
read -rp 'Para aplicar os valores históricos corrigidos, digite ATUALIZAR_HISTORICO_V10: ' CONFIRM
if [[ "$CONFIRM" != 'ATUALIZAR_HISTORICO_V10' ]]; then echo 'Cancelado; dados anteriores preservados.'; exit 0; fi
printf '\n===== 4. GRAVAÇÃO COM BACKUP =====\n'
"$SAIL" php tools/proposals/history_scenarios_bridge_v10.php apply "$REL/review.json" "$CONFIRM"
if [[ -f "$ROOT/$REL/before.json" && -f "$ROOT/$REL/after.json" ]]; then
    printf '%s\n' "$REL/review.json" > "$ROOT/proposals/history-values-v10/last-applied.tmp.$$"
    mv -f "$ROOT/proposals/history-values-v10/last-applied.tmp.$$" "$ROOT/proposals/history-values-v10/last-applied.txt"
fi
"$SAIL" artisan view:clear
echo '===== V10 FINALIZADA ====='
echo "Resumo: $ROOT/$REL/resumo.json"
echo "Revisão: $ROOT/$REL/revisao.csv"
echo "Pendências: $ROOT/$REL/pendencias.json"
if [[ -f "$ROOT/$REL/before.json" ]]; then echo "Backup dos dados: $ROOT/$REL/before.json"; else echo "Nenhuma alteração de dados nesta execução."; fi
