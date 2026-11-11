#!/usr/bin/env bash
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
echo '=== Contêineres gateway/ngrok ==='
timeout -k 2s 12s docker compose --profile background ps campaigns-unsubscribe-gateway ngrok 2>&1 || true
echo '=== Monitoramento ==='
systemctl is-active prospector-webhook-watch-v90.timer 2>/dev/null || true
echo '=== Últimos eventos do timer ==='
sudo journalctl -u prospector-webhook-watch-v90.service -n 12 --no-pager 2>/dev/null || true
echo '=== Nginx (sem expor segredos) ==='
timeout -k 2s 12s docker compose --profile background exec -T campaigns-unsubscribe-gateway nginx -t 2>&1 || true
echo '=== Status do monitor Laravel, sem disparar webhook ==='
timeout -k 3s 20s ./vendor/bin/sail artisan hubspot:tunnel-health-refresh 2>&1 || true
