#!/usr/bin/env bash
# Apenas leitura: nao faz staging, commit, push, fetch, limpeza ou operacoes da aplicacao.
set -euo pipefail
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
printf '\n=== Estado Git (sem conteudo de credenciais) ===\n'
git status --short --branch
printf '\n=== Whitespace das alteracoes ===\n'
git diff --check
git diff --cached --check
printf '\n=== Estatisticas ===\n'
git diff --stat
git diff --cached --stat
printf '\n=== Checagem de nomes sensiveis no staging ===\n'
python3 - <<'PY'
import subprocess
from pathlib import PurePosixPath

raw = subprocess.check_output(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z'])
blocked = []
for item in raw.split(b'\0'):
    if not item:
        continue
    path = item.decode('utf-8', 'surrogateescape')
    p = PurePosixPath(path)
    name = p.name.lower()
    sensitive = (
        (name.startswith('.env') and name != '.env.example')
        or name in {'auth.json', 'creds.json', 'id_rsa', 'id_ed25519'}
        or name.endswith(('.pem', '.p12', '.pfx', '.sqlite', '.sql'))
        or any(x in p.parts for x in {'.git', '.prospector-patch-backups', '.exportcontrol_backups'})
        or path.startswith(('storage/app/private/', 'storage/app/backups/', 'tools/receita/data/'))
        or 'Zone.Identifier' in path
    )
    if sensitive:
        blocked.append(path)
if blocked:
    print('REVISAR: nomes potencialmente sensiveis preparados para inclusao:')
    for path in blocked:
        print(' -', path)
    raise SystemExit(1)
print('Nenhum nome bloqueado encontrado. Isto NAO substitui revisar o diff e procurar segredos no conteudo.')
PY
printf '\nNenhuma alteracao no Git foi executada. Revise cada caminho antes de preparar commits.\n'
