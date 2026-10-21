#!/usr/bin/env bash
# Retention of CODE ONLY: never delete MySQL dumps, fiscal XML, A1, CSC,
# sessions, cache, uploads, .env, or other customer-owned files.
set -Eeuo pipefail
umask 077
SELF="$(cd -- "$(dirname -- "$0")" && pwd)"
source "$SELF/lib.sh"
APPLY=0 DAYS=30
usage() { echo 'Uso: prune.sh [--min-age-days 30] [--apply] (padrão é simulação)'; }
while (($#)); do
  case "$1" in
    --min-age-days) (($#>=2)) || exit 2; DAYS="$2"; shift 2;;
    --apply) APPLY=1; shift;;
    *) usage; exit 2;;
  esac
done
[[ "$DAYS" =~ ^[0-9]+$ && "$DAYS" -ge 7 ]] ||
  lm_die 'Retenção mínima obrigatória de sete dias; recomendado 30.'
if ((APPLY)); then lm_root; fi
if ((APPLY)); then
  lm_require flock realpath stat
  exec 8>/run/lumeron-code.lock
  flock -x 8
fi
now="$(date +%s)"
min_age="$((DAYS * 86400))"
echo "Limpeza segura: somente snapshots de código com mais de $DAYS dias."
# Never prune any active/previous pointer, regardless of age. Do not touch legacy layouts.
for base in "$LM_INSTANCES"/*; do
  [[ -d "$base" && ! -L "$base" && -d "$base/releases" ]] || continue
  id="$(basename "$base")"
  lm_valid_id "$id" || continue
  [[ -L "$base/current" ]] || continue
  current="$(lm_safe_active "$base" current)"
  previous=''
  [[ ! -L "$base/previous" ]] || previous="$(lm_safe_active "$base" previous)"
  for runtime in "$base/releases"/*; do
    [[ -d "$runtime" && ! -L "$runtime" && -f "$runtime/.lumeron-instance-code" ]] || continue
    [[ "$runtime" != "$current" && "$runtime" != "$previous" ]] || continue
    stamp="$(stat -c '%Y' "$runtime")"
    ((now-stamp>=min_age)) || continue
    # The global lock excludes concurrent provision/update. Leave data and backups alone.
    echo "Snapshot antigo, sem ponteiro ativo: $id / $(basename "$runtime")"
    if ((APPLY)); then
      # remove ONLY tenant runtime code snapshots with verified path and marker
      [[ "$runtime" == "$base/releases/"* ]] || lm_die 'Snapshot fora do diretório do cliente.'
      rm -rf --one-file-system -- "$runtime"
    fi
  done
done
approved=''
[[ ! -f "$LM_APPROVED" ]] || approved="$(cat "$LM_APPROVED")"
for path in "$LM_CODE"/*; do
  [[ -d "$path" && ! -L "$path" && -f "$path/.lumeron-code-sha" ]] || continue
  sha="$(basename "$path")"
  lm_valid_sha "$sha" || continue
  [[ "$(cat "$path/.lumeron-code-sha")" == "$sha" ]] || continue
  [[ "$sha" != "$approved" ]] || continue
  lm_code_referenced "$sha" && continue
  stamp="$(stat -c '%Y' "$path")"
  ((now-stamp>=min_age)) || continue
  echo "Código compartilhado sem qualquer referência: $sha"
  if ((APPLY)); then
    # Re-evaluate under exclusive global lock immediately before deletion.
    lm_code_referenced "$sha" && continue
    [[ "$(stat -c '%u' "$path")" == 0 ]] || lm_die 'Código não pertence ao root.'
    rm -rf --one-file-system -- "$path"
  fi
done
echo 'Backups, XMLs, certificados, .env e dados dos clientes não foram varridos.'
