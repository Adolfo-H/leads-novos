#!/usr/bin/env bash
# Instala timer systemd de autorrecuperação do gateway/ngrok, opt-in do usuário.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ "$(ps -p 1 -o comm= | tr -d ' ')" != systemd ]]; then
  echo 'WSL sem systemd. Consulte o README antes de configurar reinício automático.' >&2
  exit 1
fi
SERVICE="/etc/systemd/system/prospector-webhook-watch-v90.service"
TIMER="/etc/systemd/system/prospector-webhook-watch-v90.timer"
if sudo test -e "$SERVICE" || sudo test -e "$TIMER"; then
  echo 'Unidade V90 já existe; preservada. Confira systemctl status prospector-webhook-watch-v90.timer.' >&2
  exit 1
fi
USER_NAME="$(id -un)"
TMP1="$(mktemp)";TMP2="$(mktemp)"
trap 'rm -f "$TMP1" "$TMP2"' EXIT
cat > "$TMP1" <<EOF
[Unit]
Description=Prospector - recuperação conservadora do gateway de webhook HubSpot
After=network.target

[Service]
Type=oneshot
User=$USER_NAME
WorkingDirectory=$ROOT
Environment=HOME=$HOME
ExecStart=/usr/bin/env bash $ROOT/integrations/ops/webhook-watch-v90.sh
TimeoutStartSec=90
EOF
cat > "$TMP2" <<EOF
[Unit]
Description=Prospector - verifica gateway e túnel a cada 2 minutos

[Timer]
OnBootSec=90s
OnUnitActiveSec=120s
AccuracySec=15s
Persistent=true
Unit=prospector-webhook-watch-v90.service

[Install]
WantedBy=timers.target
EOF
sudo install -m 0644 "$TMP1" "$SERVICE"
sudo install -m 0644 "$TMP2" "$TIMER"
sudo systemctl daemon-reload
sudo systemctl enable --now prospector-webhook-watch-v90.timer
sudo systemctl start prospector-webhook-watch-v90.service
sudo systemctl --no-pager status prospector-webhook-watch-v90.timer | head -15 || true
echo 'Vigilância instalada. Continua necessária a disponibilidade do WSL/Docker e do túnel externo.'
