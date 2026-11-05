#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$(dirname "$(dirname "${BASH_SOURCE[0]}")")")"
if [[ ! -f artisan || ! -f integrations/whatsapp-web/pairing-daemon.mjs ]]; then
  echo 'Execute dentro do Prospector, com V13.1 instalada.' >&2; exit 1
fi
if ! command -v node >/dev/null 2>&1; then echo 'Node.js não encontrado no Ubuntu.' >&2; exit 1; fi
mkdir -p storage/app/private storage/logs
chmod 700 storage/app/private
pidfile='storage/app/private/whatsapp-web-conector.pid'
if [[ -f "$pidfile" ]] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
  echo "Conector já ativo (PID $(cat "$pidfile"))."; exit 0
fi
nohup node integrations/whatsapp-web/pairing-daemon.mjs >>storage/logs/whatsapp-web-conector.log 2>&1 </dev/null &
echo "$!" > "$pidfile"
echo "Conector iniciado (PID $!). Volte à Central e clique em Conectar."
echo 'Nenhum QR ou consulta é iniciada apenas por ligar o conector.'
