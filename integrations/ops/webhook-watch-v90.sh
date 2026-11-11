#!/usr/bin/env bash
# Auto-recuperação conservadora: sobe somente gateway público e ngrok se estiverem ausentes.
# Não altera assinatura, URL, Nginx, Laravel, filas, banco ou campanhas.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
log(){ printf '[%s] WEBHOOK-v90: %s\n' "$(date -Is)" "$*"; }
if ! timeout -k 2s 12s docker info >/dev/null 2>&1; then
  log 'Docker ainda indisponível. Próxima execução do timer tentará novamente.'
  exit 0
fi
if ! timeout -k 2s 12s docker compose --profile background config -q >/dev/null 2>&1; then
  log 'Configuração do Docker Compose inválida. Nenhum serviço alterado.'
  exit 1
fi
APP_ID="$(timeout -k 2s 12s docker compose --profile background ps -q laravel.test 2>/dev/null || true)"
if [[ -z "$APP_ID" ]] || [[ "$(docker inspect -f '{{.State.Running}}' "$APP_ID" 2>/dev/null)" != true ]]; then
  log 'Laravel ainda parado; gateway não será reiniciado até aplicação voltar.'
  exit 0
fi
GATE_ID="$(timeout -k 2s 12s docker compose --profile background ps -q campaigns-unsubscribe-gateway 2>/dev/null || true)"
if [[ -z "$GATE_ID" ]] || [[ "$(docker inspect -f '{{.State.Running}}' "$GATE_ID" 2>/dev/null)" != true ]]; then
  log 'Gateway ausente ou parado; iniciando apenas gateway.'
  timeout -k 3s 45s docker compose --profile background up -d --no-deps campaigns-unsubscribe-gateway
else
  HEALTH="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$GATE_ID" 2>/dev/null || true)"
  if [[ "$HEALTH" == 'unhealthy' ]]; then
    log 'Gateway não saudável; Docker restart apenas deste container.'
    timeout -k 3s 35s docker compose --profile background restart campaigns-unsubscribe-gateway
  fi
fi
NGROK_ID="$(timeout -k 2s 12s docker compose --profile background ps -q ngrok 2>/dev/null || true)"
if [[ -z "$NGROK_ID" ]] || [[ "$(docker inspect -f '{{.State.Running}}' "$NGROK_ID" 2>/dev/null)" != true ]]; then
  log 'Ngrok ausente ou parado; iniciando somente túnel existente.'
  timeout -k 3s 45s docker compose --profile background up -d --no-deps ngrok
fi
log 'Gateway e túnel verificados. Nenhum POST de teste, sem alterar eventos existentes.'
