#!/usr/bin/env bash
# Immutable hardlink release support. Never load tenant-controlled .env.
set -Eeuo pipefail
umask 077
LM_ROOT=/srv/lumeron
LM_CODE=$LM_ROOT/code/releases
LM_INSTANCES=$LM_ROOT/instances
LM_APPROVED=$LM_ROOT/code/approved-default
lm_die() { printf '[ERRO] %s\n' "$*" >&2; exit 1; }
lm_valid_id() { [[ "$1" =~ ^[a-z][a-z0-9_]{1,18}$ ]]; }
lm_valid_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
lm_root() { ((EUID==0)) || lm_die 'Somente root autorizado.'; }
lm_require() { local exe; for exe in "$@"; do command -v "$exe" >/dev/null || lm_die "Dependência ausente: $exe"; done; }
lm_code_path() {
  local sha="$1" folder
  lm_valid_sha "$sha" || lm_die 'SHA de release inválido.'
  folder="$LM_CODE/$sha"
  [[ -d "$folder" && ! -L "$folder" && -f "$folder/.lumeron-code-sha" ]] ||
    lm_die "Release imutável não preparado: $sha"
  [[ "$(cat "$folder/.lumeron-code-sha")" == "$sha" ]] ||
    lm_die 'Manifesto do release compartilhado inválido.'
  [[ "$(stat -c '%u' "$folder")" == 0 && "$(stat -c '%u' "$folder/artisan")" == 0 ]] ||
    lm_die 'Release compartilhado não pertence ao root.'
  [[ $(( 8#$(stat -c '%a' "$folder/artisan") & 8#22 )) == 0 ]] ||
    lm_die 'Código compartilhado gravável por outros usuários.'
  printf '%s\n' "$folder"
}
lm_approved_sha() {
  [[ -f "$LM_APPROVED" && ! -L "$LM_APPROVED" && "$(stat -c '%u' "$LM_APPROVED")" == 0 ]] ||
    lm_die 'Nenhuma versão padrão foi explicitamente aprovada.'
  local sha; sha="$(cat "$LM_APPROVED")"
  lm_code_path "$sha" >/dev/null
  printf '%s\n' "$sha"
}
lm_selected_sha() {
  local sha="$1"
  [[ -n "$sha" ]] || sha="$(lm_approved_sha)"
  lm_code_path "$sha" >/dev/null
  printf '%s\n' "$sha"
}
lm_safe_active() {
  local base="$1" pointer="$2" link target
  [[ -L "$base/$pointer" ]] || lm_die 'Instância sem ponteiro de release.'
  link="$(readlink "$base/$pointer")"
  [[ "$link" == releases/* && "$link" != */../* && "$link" != *//* ]] ||
    lm_die 'Ponteiro de release fora da instalação.'
  target="$(realpath -e "$base/$pointer")"
  [[ "$target" == "$base/releases/"* && -f "$target/.lumeron-instance-code" ]] ||
    lm_die 'Ponteiro de release inválido.'
  printf '%s\n' "$target"
}
lm_stage_runtime() {
  # Hardlinked code makes __DIR__ resolve to the tenant runtime path.
  # Every byte remains shared on disk, yet APP_KEY and caches are separate.
  local sha="$1" base="$2" id="$3" runtime="$4" code user
  lm_valid_id "$id" || lm_die 'ID inválido.'
  code="$(lm_code_path "$sha")"; user="lum_$id"
  [[ "$runtime" == "$base/releases/"* && ! -e "$runtime" && ! -L "$runtime" ]] ||
    lm_die 'Destino de runtime existente ou inseguro.'
  [[ -f "$base/shared/.env" && -d "$base/shared/storage" ]] ||
    lm_die 'Dados privados da instância ausentes.'
  install -d -m 755 "$runtime"
  if ! cp -al "$code/." "$runtime/"; then
    rm -rf --one-file-system "$runtime"
    lm_die 'Hardlinks indisponíveis: releases e instâncias devem usar o mesmo filesystem.'
  fi
  [[ ! -e "$runtime/.env" && ! -e "$runtime/storage" && ! -e "$runtime/bootstrap/cache" ]] ||
    lm_die 'Release contém estado compartilhado. Bloqueado.'
  ln -s "$base/shared/.env" "$runtime/.env"
  ln -s "$base/shared/storage" "$runtime/storage"
  ln -s "$base/uploads" "$runtime/public/uploads"
  install -d -m 700 -o "$user" -g "$user" "$runtime/bootstrap/cache"
  printf '%s\n' "$sha" > "$runtime/.lumeron-instance-code"
  chmod 644 "$runtime/.lumeron-instance-code"
  [[ "$(stat -c %u "$runtime/artisan")" == 0 ]] ||
    lm_die 'Código inesperadamente gravável pela instância.'
}
lm_tenant_php() {
  local id="$1" version="$2" runtime="$3"
  shift 3
  lm_valid_id "$id" || lm_die 'ID inválido.'
  [[ "$version" =~ ^8\.[34]$ ]] || lm_die 'PHP inválido.'
  [[ -d "$runtime" && -f "$runtime/.lumeron-instance-code" ]] ||
    lm_die 'Runtime ausente.'
  runuser -u "lum_$id" -- env -i PATH=/usr/local/bin:/usr/bin:/bin HOME="$LM_INSTANCES/$id/shared" \
    "/usr/bin/php$version" "$runtime/artisan" "$@"
}
lm_atomic_current() {
  local base="$1" target="$2"
  [[ "$target" == "$base/releases/"* && -f "$target/.lumeron-instance-code" ]] ||
    lm_die 'Destino fora da instalação.'
  ln -s "releases/$(basename "$target")" "$base/.current-next"
  mv -Tf "$base/.current-next" "$base/current"
}
lm_code_referenced() {
  local sha="$1" marker
  for marker in "$LM_INSTANCES"/*/releases/*/.lumeron-instance-code; do
    [[ -f "$marker" && ! -L "$marker" ]] || continue
    if [[ "$(cat "$marker")" == "$sha" ]]; then return 0; fi
  done
  return 1
}
lm_init_storage() {
  local id="$1" base="$LM_INSTANCES/$1" user="lum_$1"
  install -d -m 755 "$LM_INSTANCES" "$base" "$base/releases"
  install -d -m 700 "$base/shared" "$base/shared/storage"
  install -d -m 700 "$base/shared/storage/app/private" "$base/shared/storage/app/public" \
    "$base/shared/storage/framework/cache/data" "$base/shared/storage/framework/sessions" \
    "$base/shared/storage/framework/views" "$base/shared/storage/logs" "$base/shared/acbr"
  install -d -m 750 -o "$user" -g www-data "$base/uploads"
  chown -R "$user:$user" "$base/shared"
}
