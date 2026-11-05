#!/usr/bin/env bash
set -euo pipefail
cd "$(pwd)"
if [[ ! -f artisan || ! -f integrations/whatsapp-web/agent.mjs ]]; then
  echo 'Entre na raiz do Prospector antes de executar.' >&2; exit 1
fi
if ! command -v node >/dev/null 2>&1; then echo 'Node.js nao encontrado.' >&2; exit 1; fi
mkdir -p storage/logs storage/app/private
chmod 700 storage/app/private
for slot in 1 2; do
  log="storage/logs/whatsapp-web-agente-${slot}.log"
  pid="storage/app/private/whatsapp-web-agente-${slot}.pid"
  if [[ -f "$pid" ]] && kill -0 "$(cat "$pid")" 2>/dev/null; then
    echo "Conta $slot: agente ja em execucao (PID $(cat "$pid"))"
    continue
  fi
  nohup node integrations/whatsapp-web/agent.mjs --session="$slot" >>"$log" 2>&1 </dev/null &
  echo "$!" > "$pid"
  echo "Conta $slot: iniciando agente no Ubuntu, PID $!, log $log"
done
echo 'Atualize a Central WhatsApp e confira o estado de cada conta.'
echo 'Se algum agente pedir novo QR, consulte storage/logs/whatsapp-web-agente-N.log.'
echo 'Para parar ambos: bash integrations/whatsapp-web/parar-agentes.sh'
