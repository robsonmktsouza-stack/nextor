#!/usr/bin/env bash
# Read-only inventory, never opens tenant databases, HMAC credentials or A1.
set -Eeuo pipefail
SELF="$(cd -- "$(dirname -- "$0")" && pwd)"
source "$SELF/lib.sh"
CHECK=0 PHP_VERSION=8.3
while (($#)); do
  case "$1" in
    --check) CHECK=1; shift;;
    --php-version) (($#>=2)) || exit 2; PHP_VERSION="$2"; shift 2;;
    *) echo 'Uso: list.sh [--check] [--php-version 8.3]' >&2; exit 2;;
  esac
done
[[ "$PHP_VERSION" =~ ^8\.[34]$ ]] || exit 2
[[ -d "$LM_INSTANCES" ]] || { echo 'Nenhuma instalação encontrada.'; exit 0; }
if ((CHECK)); then lm_root; fi
printf '%-21s %-31s %-13s %-42s %s\n' 'CNPJ/ID' 'URL' 'FILAS' 'SHA APROVADO' 'RUNTIME'
for base in "$LM_INSTANCES"/*; do
  [[ -d "$base" && ! -L "$base" && -L "$base/current" && -f "$base/shared/.env" ]] || continue
  id="$(basename "$base")"
  lm_valid_id "$id" || continue
  runtime="$(lm_safe_active "$base" current)"
  sha='legado'
  [[ ! -f "$runtime/.lumeron-instance-code" ]] ||
    sha="$(cat "$runtime/.lumeron-instance-code")"
  url="$(sed -n 's/^APP_URL=//p' "$base/shared/.env" | head -n1)"
  queue="$(systemctl is-active "lumeron-fiscal-$id.timer" 2>/dev/null || true)"
  printf '%-21s %-31s %-13s %-42s %s\n' "$id" "$url" "$queue" "$sha" "$(basename "$runtime")"
  if ((CHECK)); then
    echo "---- Instância $id ----"
    lm_tenant_php "$id" "$PHP_VERSION" "$runtime" lumeron:instance-check || true
  fi
done
