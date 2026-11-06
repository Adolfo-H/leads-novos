#!/usr/bin/env bash
# V7 - instalação reversível e não invasiva. Uso: ./instalar.sh /caminho/do/repositorio
set -Eeuo pipefail
ROOT="${1:-$PWD}"
ROOT="$(cd "$ROOT" && pwd)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "$ROOT/artisan" && -d "$ROOT/resources/views" ]] || { echo 'ERRO: pasta não parece ser um Laravel (artisan + resources/views).'; exit 1; }
DEST="$ROOT/public/assets"; mkdir -p "$DEST"
# Detecta layout real que contém <head>, evitando inserir elementos fora da raiz Livewire.
mapfile -d '' ALL_LAYOUTS < <(find "$ROOT/resources/views" -type f -name '*.blade.php' -print0)
CANDIDATES=()
for f in "${ALL_LAYOUTS[@]}"; do
  if grep -Eiq '<head([[:space:]>])' "$f" && grep -Eiq '</head[[:space:]]*>' "$f"; then CANDIDATES+=("$f"); fi
done
# Se possível escolhe pelo caminho de layout conhecido.
LAYOUT=''
for prefer in 'resources/views/components/layouts/app.blade.php' 'resources/views/layouts/app.blade.php' 'resources/views/components/layouts/app/sidebar.blade.php'; do
  f="$ROOT/$prefer"
  if [[ -f "$f" ]] && grep -Eiq '</head[[:space:]]*>' "$f"; then LAYOUT="$f"; break; fi
done
if [[ -z "$LAYOUT" && ${#CANDIDATES[@]} -eq 1 ]]; then LAYOUT="${CANDIDATES[0]}"; fi
if [[ -z "$LAYOUT" ]]; then
  echo 'ERRO SEGURO: não foi possível determinar com certeza o layout que contém </head>.'
  echo 'Layouts candidatos:'
  printf ' - %s\n' "${CANDIDATES[@]:-nenhum}"
  echo 'Nenhum arquivo foi alterado. Ajuste a seleção no script depois de conferir a estrutura.'
  exit 2
fi
if grep -q 'ECWA-VERIFY-UI-V7' "$LAYOUT"; then echo 'V7 já instalado neste layout. Nenhuma alteração feita.'; exit 0; fi
BACKUPDIR="$ROOT/.ecwa-ui-backups/v7-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUPDIR"
cp -p "$LAYOUT" "$BACKUPDIR/layout.blade.php"
if [[ -f "$DEST/whatsapp-verificacao-v7.css" ]]; then cp -p "$DEST/whatsapp-verificacao-v7.css" "$BACKUPDIR/previous.css"; fi
if [[ -f "$DEST/whatsapp-verificacao-v7.js" ]]; then cp -p "$DEST/whatsapp-verificacao-v7.js" "$BACKUPDIR/previous.js"; fi
printf '%s\n' "${LAYOUT#"$ROOT/"}" > "$BACKUPDIR/layout.path"
cp "$HERE/public/assets/whatsapp-verificacao-v7.css" "$DEST/whatsapp-verificacao-v7.css"
cp "$HERE/public/assets/whatsapp-verificacao-v7.js" "$DEST/whatsapp-verificacao-v7.js"
python3 - "$LAYOUT" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
needle='</head>'
if needle not in s:
    # Mantemos a verificação segura mesmo quando layout usa caixa alta.
    raise SystemExit('Não foi encontrado </head> em caixa padrão; cancela a instalação.')
injection='''    {{-- ECWA-VERIFY-UI-V7: início (estilos apenas na rota verificação WhatsApp) --}}
    <link rel="stylesheet" href="{{ asset('assets/whatsapp-verificacao-v7.css') }}?v=7.0.0">
    <script src="{{ asset('assets/whatsapp-verificacao-v7.js') }}?v=7.0.0" defer></script>
    {{-- ECWA-VERIFY-UI-V7: fim --}}
'''
p.write_text(s.replace(needle,injection+needle,1))
PY
printf '%s\n' "$BACKUPDIR" > "$ROOT/.ecwa-ui-v7-last-backup"
echo 'Instalado com segurança. Layout:' "${LAYOUT#"$ROOT/"}"
echo 'Backup:' "$BACKUPDIR"
if command -v php >/dev/null 2>&1; then (cd "$ROOT" && php artisan view:clear >/dev/null 2>&1) || true; fi
cat <<'MSG'
Próximos passos:
  1) Abra /contatos/verificacao-whatsapp e pressione Ctrl+Shift+R.
  2) Confira os componentes e as ações normais de verificação.
  3) Se não refletir: ./vendor/bin/sail artisan view:clear (ou php artisan view:clear).
  4) Para desfazer: ./reverter.sh /caminho/do/repositorio
Não há migrations, alterações de dados nem envio de mensagens.
MSG
