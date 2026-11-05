#!/usr/bin/env bash
set -euo pipefail
for slot in 1 2; do
  file="storage/app/private/whatsapp-web-agente-${slot}.pid"
  if [[ ! -f "$file" ]]; then echo "Agente $slot sem PID salvo"; continue; fi
  pid="$(cat "$file")"
  if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
    kill -TERM "$pid"
    echo "Agente $slot: parada solicitada ao PID $pid"
  else
    echo "Agente $slot: processo ja estava desligado"
  fi
  rm -f "$file"
done
