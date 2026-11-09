#!/usr/bin/env bash
# EC_WA_RECOVERY_V83_1: diagnostico com tempo limite, sem tocar em credenciais.
# Nao faz consultas WhatsApp, nao envia mensagens e nao apaga as sessoes.
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

fail() { printf '\nERRO: %s\n' "$*" >&2; exit 1; }

if [[ ! -f artisan || ! -f integrations/whatsapp-web/pairing-daemon.mjs ]]; then
  fail 'Raiz do Prospector ou pairing-daemon.mjs nao encontrados.'
fi
command -v node >/dev/null 2>&1 || fail 'Node.js nao encontrado no WSL.'
[[ -x vendor/bin/sail ]] || fail 'vendor/bin/sail indisponivel.'
command -v timeout >/dev/null 2>&1 || fail 'Comando GNU timeout indisponivel no WSL.'
command -v docker >/dev/null 2>&1 || fail 'Comando docker indisponivel no WSL. Verifique Docker Desktop / WSL integration.'

printf '[1/4] Docker Desktop / Engine: aguardando no maximo 10 segundos...\n'
docker_error="$(mktemp)"
sail_error="$(mktemp)"
trap 'rm -f "$docker_error" "$sail_error"' EXIT
if ! timeout --signal=TERM --kill-after=3s 10s docker info --format '{{.ServerVersion}}' >"$docker_error" 2>&1; then
  printf 'Docker nao respondeu. Ultimas linhas do diagnostico:\n' >&2
  tail -n 8 "$docker_error" >&2
  fail 'Inicie Docker Desktop no Windows e ative Settings > Resources > WSL Integration para sua distribuicao Ubuntu. Confirme com: docker info'
fi
printf 'Docker ativo: %s\n' "$(head -n 1 "$docker_error")"

printf '[2/4] Laravel no Sail: aguardando no maximo 18 segundos...\n'
if ! timeout --signal=TERM --kill-after=3s 18s ./vendor/bin/sail artisan --version >"$sail_error" 2>&1; then
  printf 'Laravel nao respondeu; ultimas linhas:\n' >&2
  tail -n 10 "$sail_error" >&2
  fail 'Confirme os containers com: ./vendor/bin/sail ps ; se estiverem parados, inicie: ./vendor/bin/sail up -d ; depois execute novamente a recuperacao.'
fi
printf 'Laravel respondeu: %s\n' "$(tail -n 1 "$sail_error")"

printf '[3/4] Node e bibliotecas: validando caminhos sem iniciar conexoes...\n'
printf 'Node: %s\n' "$(node --version)"
for package in '@whiskeysockets/baileys' 'qrcode' 'pino'; do
  if ! (cd integrations/whatsapp-web && timeout --signal=TERM --kill-after=2s 8s node --input-type=module -e 'import.meta.resolve(process.argv[1]);' "$package" >/dev/null 2>&1); then
    fail "Dependencia $package ausente/indisponivel. Execute: cd integrations/whatsapp-web && npm ci"
  fi
  printf '  %s: OK\n' "$package"
done

printf '[4/4] Conector Node: conferindo processo existente...\n'
if command -v systemctl >/dev/null 2>&1 && \
   timeout --signal=TERM --kill-after=2s 5s systemctl --user is-active --quiet prospector-whatsapp-conector.service 2>/dev/null; then
  printf 'Servico systemd --user ativo; nao sera iniciada outra instancia.\n'
  echo 'Atualize a tela. O heartbeat pode levar alguns segundos para aparecer.'
  exit 0
fi

mkdir -p storage/logs storage/app/private
chmod 700 storage/app/private
pid_file='storage/app/private/whatsapp-web-conector.pid'
if [[ -f "$pid_file" ]]; then
  old_pid="$(cat "$pid_file" 2>/dev/null || true)"
  if [[ "$old_pid" =~ ^[0-9]+$ ]] && kill -0 "$old_pid" 2>/dev/null; then
    old_args="$(ps -p "$old_pid" -o args= 2>/dev/null || true)"
    if [[ "$old_args" == *'pairing-daemon.mjs'* ]]; then
      printf 'Conector ja ativo (PID %s). Nao iniciando outro.\n' "$old_pid"
      echo 'Se a tela seguir offline apos 60s, confira: tail -n 40 storage/logs/whatsapp-web-conector.log'
      exit 0
    fi
    printf 'PID anterior %s pertence a outro processo. Removendo apenas PID obsoleto.\n' "$old_pid"
  fi
  rm -f "$pid_file"
fi

bash integrations/whatsapp-web/iniciar-conector.sh
sleep 3
new_pid="$(cat "$pid_file" 2>/dev/null || true)"
if [[ ! "$new_pid" =~ ^[0-9]+$ ]] || ! kill -0 "$new_pid" 2>/dev/null; then
  fail 'Conector encerrou apos iniciar. Veja: tail -n 40 storage/logs/whatsapp-web-conector.log'
fi
printf 'Conector iniciado (PID %s). Aguarde a indicacao "Conector Ubuntu ativo" na tela.\n' "$new_pid"
echo 'Depois clique Conectar / reconectar na conta 1 e depois na conta 2.'
echo 'Use Gerar novo QR apenas se a sessao salva nao reconectar.'
echo 'Nenhuma consulta, mensagem ou exclusao de credenciais foi executada.'
