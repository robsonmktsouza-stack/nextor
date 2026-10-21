#!/usr/bin/env bash
# Explicitly prepare immutable code from an approved Git tag or commit.
# A push to develop never changes code used by a tenant.
set -Eeuo pipefail
umask 077
SELF="$(cd -- "$(dirname -- "$0")" && pwd)"
source "$SELF/lib.sh"
SOURCE= REF= APPLY=0 APPROVE_DEFAULT=0
usage() { echo "Uso: release.sh --source /opt/lumeron/source --approved-ref v1.2.0 [--approve-default] [--apply]"; }
while (($#)); do
  case "$1" in
    --source|--approved-ref)
      (($# >= 2)) || { usage; exit 2; }
      if [[ "$1" == --source ]]; then SOURCE="$2"; else REF="$2"; fi
      shift 2;;
    --approve-default) APPROVE_DEFAULT=1; shift;;
    --apply) APPLY=1; shift;;
    *) usage; exit 2;;
  esac
done
[[ -n "$SOURCE" && -n "$REF" && -d "$SOURCE/.git" ]] || { usage; exit 2; }
[[ "$REF" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([.-][a-zA-Z0-9.-]+)?$ ||
   "$REF" =~ ^[0-9a-f]{40}$ ]] ||
  lm_die 'Use tag imutável vX.Y.Z ou SHA de 40 dígitos explicitamente aprovado.'
lm_require git
SHA="$(git -C "$SOURCE" rev-parse --verify "$REF^{commit}")" ||
  lm_die 'Referência Git inexistente.'
lm_valid_sha "$SHA" || lm_die 'Git não retornou SHA válido.'
if [[ "$REF" != "$SHA" ]]; then
  [[ "$(git -C "$SOURCE" rev-parse --verify "refs/tags/$REF^{commit}")" == "$SHA" ]] ||
    lm_die 'Referência não é tag aprovada.'
fi
git -C "$SOURCE" cat-file -e "$SHA:composer.lock" ||
  lm_die 'Release sem composer.lock versionado.'
echo "Release aprovado: $REF -> $SHA"
echo 'Origem: Git archive imutável; Composer --no-dev --no-scripts; root-owned.'
if ((APPLY==0)); then echo 'Simulação: nada foi alterado.'; exit 0; fi
lm_root
lm_require tar composer runuser install stat find flock
exec 8>/run/lumeron-code.lock
flock -x 8
[[ ! -e "$LM_CODE/$SHA" && ! -L "$LM_CODE/$SHA" ]] ||
  lm_die 'Release já existe e é imutável. Não reconstruir.'
if ! id lumeron_build >/dev/null 2>&1; then
  useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin lumeron_build
fi
install -d -m 755 "$LM_ROOT" "$LM_ROOT/code" "$LM_CODE"
STAGING="$(mktemp -d "$LM_CODE/.staging-$SHA-XXXXXXXX")"
trap 'rm -rf --one-file-system "$STAGING"' EXIT
chown lumeron_build:lumeron_build "$STAGING"
# Archive extraction runs unprivileged: a tagged Git symlink cannot trick
# a root tar process into writing outside the release staging directory.
git -C "$SOURCE" archive --format=tar "$SHA" |
  runuser -u lumeron_build -- tar -x -C "$STAGING" --no-same-owner --no-same-permissions
[[ -f "$STAGING/artisan" && -f "$STAGING/composer.lock" ]] ||
  lm_die 'Arquivo Git não contém aplicação Laravel.'
[[ ! -f "$STAGING/.env" && ! -e "$STAGING/vendor" ]] ||
  lm_die 'Fonte contém segredos ou vendor versionado.'
chown -R lumeron_build:lumeron_build "$STAGING"
runuser -u lumeron_build -- env -i PATH=/usr/local/bin:/usr/bin:/bin HOME="$STAGING" \
  composer --working-dir="$STAGING" install --no-dev --no-interaction --prefer-dist --no-progress \
  --optimize-autoloader --no-scripts
# Shared code NEVER contains a Laravel cache, .env, storage, uploads or credentials.
rm -rf "$STAGING/storage" "$STAGING/bootstrap/cache" "$STAGING/public/uploads"
mkdir -p "$STAGING/bootstrap"
[[ ! -e "$STAGING/.env" && ! -e "$STAGING/storage" && ! -e "$STAGING/bootstrap/cache" ]] ||
  lm_die 'Release contém estado individual.'
printf '%s\n' "$SHA" > "$STAGING/.lumeron-code-sha"
printf '%s\n' "$REF" > "$STAGING/.lumeron-approved-ref"
chown -R root:root "$STAGING"
find "$STAGING" -type d -exec chmod 755 {} +
find "$STAGING" -type f -exec chmod go-w {} +
mv -T "$STAGING" "$LM_CODE/$SHA"
trap - EXIT
echo "Release imutável criado: $SHA"
if ((APPROVE_DEFAULT)); then
  printf '%s\n' "$SHA" > "$LM_ROOT/code/.approved-next"
  chown root:root "$LM_ROOT/code/.approved-next"
  chmod 600 "$LM_ROOT/code/.approved-next"
  mv -fT "$LM_ROOT/code/.approved-next" "$LM_APPROVED"
  echo "Versão padrão de NOVAS INSTALAÇÕES aprovada: $SHA"
fi
