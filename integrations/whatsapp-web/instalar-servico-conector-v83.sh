#!/usr/bin/env bash
# OPCIONAL: habilita reinício automático do conector de pareamento por systemd --user.
# Não inicia as consultas nem gera QR até um gestor solicitar na tela.
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
if ! command -v systemctl >/dev/null 2>&1 || ! systemctl --user show-environment >/dev/null 2>&1; then
  echo 'ERRO: systemd --user não disponível neste WSL. Use recuperar-conector-v83.sh quando iniciar o WSL.' >&2
  exit 1
fi
node="$(command -v node || true)"
if [[ -z "$node" || ! -x "$node" ]]; then
  echo 'ERRO: Node.js não encontrado.' >&2
  exit 1
fi
pidfile='storage/app/private/whatsapp-web-conector.pid'
if [[ -f "$pidfile" ]]; then
  pid="$(cat "$pidfile" 2>/dev/null || true)"
  if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
    args="$(ps -p "$pid" -o args= 2>/dev/null || true)"
    if [[ "$args" == *'pairing-daemon.mjs'* ]]; then
      echo "ERRO: conector manual PID $pid já está ativo." >&2
      echo 'Não iniciarei outro processo concorrente. Pare o processo manual com segurança antes de instalar o serviço.' >&2
      exit 1
    fi
  fi
fi
mkdir -p "$HOME/.config/systemd/user" storage/logs
unit="$HOME/.config/systemd/user/prospector-whatsapp-conector.service"
if [[ -e "$unit" ]]; then
  echo "ERRO: $unit já existe. Confira o conteúdo antes de substituí-lo." >&2
  exit 1
fi
cat > "$unit" <<EOF
[Unit]
Description=Prospector - Conector WhatsApp (somente pareamento solicitado pelo gestor)
After=default.target

[Service]
Type=simple
WorkingDirectory=$root
ExecStart=$node $root/integrations/whatsapp-web/pairing-daemon.mjs
Restart=always
RestartSec=25
Environment=TERM=dumb
StandardOutput=append:$root/storage/logs/whatsapp-web-conector.log
StandardError=append:$root/storage/logs/whatsapp-web-conector.log

[Install]
WantedBy=default.target
EOF
systemctl --user daemon-reload
systemctl --user enable --now prospector-whatsapp-conector.service
systemctl --user --no-pager status prospector-whatsapp-conector.service | head -18
printf '\nServiço instalado em %s\n' "$unit"
echo 'Ao iniciar o WSL com systemd --user, o conector será reiniciado. Contas/QR exigem solicitação do gestor.'
