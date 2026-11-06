#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="${1:-$PWD}"; ROOT="$(cd "$ROOT" && pwd)"
MANIFEST="$ROOT/.ecwa-ui-v7-last-backup"
[[ -f "$MANIFEST" ]] || { echo 'Backup da V7 não encontrado; abortando para proteger seu sistema.'; exit 1; }
BACKUPDIR="$(cat "$MANIFEST")"
[[ -f "$BACKUPDIR/layout.path" && -f "$BACKUPDIR/layout.blade.php" ]] || { echo 'Backup incompleto; abortando.';exit 1; }
TARGET="$ROOT/$(cat "$BACKUPDIR/layout.path")"
[[ -f "$TARGET" ]] || { echo 'Layout alvo não encontrado. Sem alterações.';exit 1; }
cp -p "$BACKUPDIR/layout.blade.php" "$TARGET"
for ext in css js; do
  CURRENT="$ROOT/public/assets/whatsapp-verificacao-v7.$ext"
  if [[ -f "$BACKUPDIR/previous.$ext" ]];then cp -p "$BACKUPDIR/previous.$ext" "$CURRENT"; else rm -f "$CURRENT";fi
done
rm -f "$MANIFEST"
if command -v php >/dev/null 2>&1; then (cd "$ROOT" && php artisan view:clear >/dev/null 2>&1) || true; fi
echo 'V7 revertida. Layout restaurado a partir do backup:' "$BACKUPDIR"
