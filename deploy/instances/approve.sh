#!/usr/bin/env bash
# Explicitly approve an already-built immutable artifact for NEW customers.
# NEVER updates existing tenant release pointers.
set -Eeuo pipefail
umask 077
SELF="$(cd -- "$(dirname -- "$0")" && pwd)"
source "$SELF/lib.sh"
SHA= APPLY=0
while (($#)); do
  case "$1" in
    --release) (($#>=2)) || exit 2; SHA="$2"; shift 2;;
    --apply) APPLY=1; shift;;
    *) echo 'Uso: approve.sh --release SHA [--apply]' >&2; exit 2;;
  esac
done
lm_valid_sha "$SHA" || lm_die 'Informe SHA completo do release preparado.'
echo "Definir versão padrão de NOVAS contas: $SHA"
if ((APPLY==0)); then echo 'Simulação: nada foi alterado.'; exit 0; fi
lm_root
lm_require flock
exec 8>/run/lumeron-code.lock
flock -x 8
lm_code_path "$SHA" >/dev/null
printf '%s\n' "$SHA" > "$LM_ROOT/code/.approved-next"
chown root:root "$LM_ROOT/code/.approved-next"
chmod 600 "$LM_ROOT/code/.approved-next"
mv -fT "$LM_ROOT/code/.approved-next" "$LM_APPROVED"
echo 'Aprovação concluída. Clientes existentes NÃO foram atualizados.'
