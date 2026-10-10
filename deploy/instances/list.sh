#!/usr/bin/env bash
# Read-only VPS inventory; no access to any customer's invoices or DB contents.
set -Eeuo pipefail
BASE=/srv/lumeron/instances
CHECK=0 PHP_VERSION=8.3
while (($#)); do
  case "$1" in
    --check) CHECK=1; shift;;
    --php-version) (($#>=2)) || exit 2; PHP_VERSION="$2"; shift 2;;
    *) echo "Uso: bash deploy/instances/list.sh [--check] [--php-version 8.3]" >&2; exit 2;;
  esac
done
[[ "$PHP_VERSION" =~ ^8\.[34]$ ]] || exit 2
[[ -d "$BASE" ]] || { echo "Nenhuma instalação provisionada nesta VPS."; exit 0; }
if ((CHECK)); then ((EUID==0)) || { echo "Verificações exigem root." >&2; exit 1; }; fi
printf '%-20s %-32s %-10s %s\n' "INSTALAÇÃO" "ENDEREÇO" "WORKER" "RELEASE"
for path in "$BASE"/*; do
  [[ -d "$path" && -L "$path/current" && -f "$path/shared/.env" ]] || continue
  id="$(basename "$path")"
  [[ "$id" =~ ^[a-z][a-z0-9_]{1,18}$ ]] || continue
  url="$(sed -n 's/^APP_URL=//p' "$path/shared/.env" | head -n1)"
  worker="$(systemctl is-active "lumeron-fiscal-$id" 2>/dev/null || true)"
  release="$(basename "$(readlink "$path/current")")"
  printf '%-20s %-32s %-10s %s\n' "$id" "$url" "$worker" "$release"
  if ((CHECK)); then
    echo "---- Verificação: $id ----"
    ( cd "$path/current" && runuser -u "lum_$id" -- "/usr/bin/php$PHP_VERSION" artisan lumeron:instance-check ) || true
  fi
done
