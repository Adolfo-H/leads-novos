#!/usr/bin/env bash
set -euo pipefail

cd "${1:-$PWD}"

if [[ ! -f artisan || ! -f composer.json ]] || ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo 'ERRO: execute na raiz do projeto Prospector ExportControl.' >&2
  exit 1
fi

remote="$(git remote get-url origin)"
if [[ "$remote" != *'Adolfo-H/leads-novos'* ]]; then
  echo "ERRO: origin inesperado: $remote" >&2
  exit 1
fi

branch="$(git branch --show-current)"
if [[ -z "$branch" ]]; then
  echo 'ERRO: HEAD detached; selecione sua branch antes de commitar.' >&2
  exit 1
fi

if ! grep -Fq 'ec-ui-dark-v74.css?v=74.16' resources/views/partials/head.blade.php; then
  echo 'ERRO: a referência A24.16 não está ativa no head. Confirme a instalação do A24.16 primeiro.' >&2
  exit 1
fi

if ! git diff --cached --quiet; then
  echo 'ERRO: já existem arquivos no stage. Revise com git diff --cached --stat antes de executar.' >&2
  exit 1
fi

if git ls-files --error-unmatch .env >/dev/null 2>&1; then
  echo 'ERRO: .env está versionado neste repositório. Corrija isso antes do push.' >&2
  exit 1
fi

echo '===== BRANCH E ALTERAÇÕES LOCAIS ====='
git status -sb

echo '===== STAGE: ARQUIVOS JÁ VERSIONADOS ====='
git add -u

echo '===== STAGE: NOVOS ARQUIVOS DO PROJETO A24 ====='
new_files=(
  'app/Providers/ProspectorPrivateHttpsServiceProvider.php'
  'config/prospector_private_https.php'
  'public/css/ec-appearance-modes-v65.css'
  'public/js/ec-appearance-modes-v65.js'
  'public/css/ec-theme-contrast-black-v66.css'
  'public/css/ec-dark-contrast-v67.css'
  'public/css/ec-dark-fixes-v68.css'
  'public/css/ec-dark-repair-v69.css'
  'public/js/ec-dark-repair-v69.js'
  'public/css/ec-dark-components-v70.css'
  'public/css/ec-dark-final-v71.css'
  'public/js/ec-dark-final-v71.js'
  'public/css/ec-ui-polish-v72.css'
  'public/css/ec-ui-dark-v73.css'
  'public/css/ec-ui-dark-v74.css'
  'public/js/ec-ui-hardfix-v74.js'
  'tests/Feature/Access/AccessAdminThrottleTest.php'
  'tests/Unit/ProspectorUiA2411Test.php'
  'tests/Unit/ProspectorUiA2412Test.php'
  'tests/Unit/ProspectorUiA2413Test.php'
  'tests/Unit/ProspectorUiA2414Test.php'
  'tests/Unit/ProspectorUiA2415Test.php'
  'tests/Unit/ProspectorUiA2416Test.php'
)
for file in "${new_files[@]}"; do
  if [[ -f "$file" ]]; then
    git add -- "$file"
  fi
done

if git diff --cached --quiet; then
  echo 'Nenhuma alteração nova encontrada para commit.'
  exit 0
fi

echo '===== VERIFICANDO ARQUIVOS SENSÍVEIS ====='
bad_paths="$(git diff --cached --name-only | grep -Ei '(^|/)(\.env($|\.)|vendor/|node_modules/|storage/|\.exportcontrol_backups/|backups?/)|\.(zip|7z|rar|bak|sqlite|sqlite3|log|pem|key|p12|pfx)$' || true)"
if [[ -n "$bad_paths" ]]; then
  echo "ERRO: arquivos potencialmente sensíveis no stage:" >&2
  printf '%s\n' "$bad_paths" >&2
  echo 'Nada foi commitado ou enviado. Revise com git restore --staged <arquivo>.' >&2
  exit 1
fi

git diff --cached --check

echo '===== RESUMO DO QUE SERÁ COMMITADO ====='
git diff --cached --stat
echo '===== NOMES DOS ARQUIVOS PREPARADOS ====='
git diff --cached --name-only

echo '===== CI COMPLETO ====='
./vendor/bin/sail composer ci:check

echo '===== CONFIRMAÇÃO FINAL ====='
read -r -p "CI passou. Fazer commit e push na branch '$branch'? [s/N] " answer
if [[ "$answer" != 's' && "$answer" != 'S' ]]; then
  echo 'Cancelado sem commit/push. Os arquivos permanecem preparados no Git.'
  exit 0
fi

git commit -m 'chore: checkpoint HTTPS, acesso e refinamentos visuais A24'
git push -u origin "$branch"

echo '===== CHECKPOINT CONCLUÍDO ====='
git status -sb
git log -1 --oneline
