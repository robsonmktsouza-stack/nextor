#!/usr/bin/env bash
# Update ONE isolated CNPJ to an existing approved shared code release.
# Rollback is CODE ONLY, NEVER automatically restore migrations or fiscal data.
set -Eeuo pipefail
umask 077
SELF="$(cd -- "$(dirname -- "$0")" && pwd)"
source "$SELF/lib.sh"
ID= SOURCE= RELEASE_SHA= PHP_VERSION=8.3 CONTROL_PATH= CONTROL_URL=
APPLY=0 ROLLBACK=0 ALLOW_ROLLBACK=0
usage() { echo 'Uso: update.sh --id c_2 [--release SHA] [--source /opt/lumeron/source] [--control-path ... --control-url https://...] [--rollback --allow-code-only-rollback] [--apply]'; }
while (($#)); do
  case "$1" in
    --id|--source|--release|--php-version|--control-path|--control-url)
      option="$1"; (($#>=2)) || { usage; exit 2; }
      case "$option" in
        --id) ID="$2";; --source) SOURCE="$2";; --release) RELEASE_SHA="$2";;
        --php-version) PHP_VERSION="$2";; --control-path) CONTROL_PATH="$2";;
        --control-url) CONTROL_URL="$2";;
      esac
      shift 2;;
    --rollback) ROLLBACK=1; shift;;
    --allow-code-only-rollback) ALLOW_ROLLBACK=1; shift;;
    --apply) APPLY=1; shift;;
    *) usage; exit 2;;
  esac
done
lm_valid_id "$ID" || { echo 'ID inválido.' >&2; exit 2; }
[[ "$PHP_VERSION" =~ ^8\.[34]$ ]] || { echo 'PHP inválido.' >&2; exit 2; }
[[ -z "$CONTROL_PATH" && -z "$CONTROL_URL" || -n "$CONTROL_PATH" && "$CONTROL_URL" == https://* ]] ||
  { echo 'Control exige caminho e HTTPS.' >&2; exit 2; }
if ((ROLLBACK)); then
  ((ALLOW_ROLLBACK)) || lm_die 'Rollback de código exige --allow-code-only-rollback (migrações não são revertidas).'
  [[ -z "$RELEASE_SHA" && -z "$CONTROL_PATH" ]] || lm_die 'Rollback não recebe --release nem novo vínculo.'
fi
BASE="$LM_INSTANCES/$ID"; USER="lum_$ID"
[[ -L "$BASE/current" && -f "$BASE/shared/.env" ]] || lm_die 'Instância não provisionada.'
CURRENT="$(lm_safe_active "$BASE" current)"
[[ -f "$CURRENT/.lumeron-instance-code" ]] ||
  lm_die 'Instalação no layout legado. Exige migração assistida antes de usar releases deduplicados.'
OLD_SHA="$(cat "$CURRENT/.lumeron-instance-code")"
if ((ROLLBACK)); then
  [[ -L "$BASE/previous" ]] || lm_die 'Nenhuma versão anterior disponível para rollback.'
  TARGET="$(lm_safe_active "$BASE" previous)"
  SHA="$(cat "$TARGET/.lumeron-instance-code")"
else
  SHA="$RELEASE_SHA"
  if ((APPLY)); then SHA="$(lm_selected_sha "$SHA")"; fi
fi
echo "CNPJ $ID: origem=$OLD_SHA destino=$SHA (apenas esta instância)"
if ((APPLY==0)); then echo 'Simulação: nenhuma alteração.'; exit 0; fi
lm_root
lm_require mysql mysqldump gzip runuser systemctl flock
exec 8>/run/lumeron-code.lock
flock -s 8
[[ -r "/root/lumeron/$ID.mysql.cnf" ]] || lm_die 'Credencial exclusiva de backup ausente.'
exec 9>"/run/lumeron-update-$ID.lock"
flock -n 9 || lm_die 'Outra atualização em andamento.'
[[ -z "$SOURCE" || -d "$SOURCE" ]] || lm_die 'Origem indicada não existe.'
if ((!ROLLBACK)); then
  CODE="$(lm_code_path "$SHA")"
  if [[ -n "$CONTROL_PATH" ]]; then
    [[ -f "$CONTROL_PATH/artisan" && -f "$CODE/app/Console/Commands/CentralLicenseIdentity.php" ]] ||
      lm_die 'Integração Control ausente no release selecionado.'
  fi
  [[ "$SHA" != "$OLD_SHA" ]] || lm_die 'Cliente já usa esse release.'
  STAMP="$(date -u +%Y%m%d%H%M%S)-$(openssl rand -hex 3)"
  TARGET="$BASE/releases/$STAMP"
  lm_stage_runtime "$SHA" "$BASE" "$ID" "$TARGET"
  lm_tenant_php "$ID" "$PHP_VERSION" "$TARGET" package:discover
  lm_tenant_php "$ID" "$PHP_VERSION" "$TARGET" optimize
fi

FISCAL_RUNNING=0 FAST_RUNNING=0 SCHEDULE_RUNNING=0 MAINTENANCE=0 SWITCHED=0
# A failed activation must not overwrite the operator's existing rollback point.
PREVIOUS_POINTER=''
if [[ -L "$BASE/previous" ]]; then
  lm_safe_active "$BASE" previous >/dev/null
  PREVIOUS_POINTER="$(readlink "$BASE/previous")"
fi
cleanup() {
  local code="$?"
  if ((code!=0 && SWITCHED)); then
    lm_atomic_current "$BASE" "$CURRENT" || true
    if [[ -n "$PREVIOUS_POINTER" ]]; then
      ln -s "$PREVIOUS_POINTER" "$BASE/.previous-next"
      mv -Tf "$BASE/.previous-next" "$BASE/previous"
    else
      rm -f "$BASE/previous"
    fi
    systemctl reload "php$PHP_VERSION-fpm" || true
    echo '[ERRO] Código retornou ao release anterior. Banco pode ter migrations aplicadas; avalie compatibilidade.' >&2
  fi
  if ((MAINTENANCE)); then
    lm_tenant_php "$ID" "$PHP_VERSION" "$BASE/current" up || true
  fi
  if ((FISCAL_RUNNING)); then systemctl start "lumeron-fiscal-$ID.timer" || true; fi
  if ((FAST_RUNNING)); then systemctl start "lumeron-fiscal-fast-$ID.service" || true; fi
  if ((SCHEDULE_RUNNING)); then systemctl start "lumeron-schedule-$ID.timer" || true; fi
}
trap cleanup EXIT
if systemctl is-active --quiet "lumeron-fiscal-$ID.timer"; then
  FISCAL_RUNNING=1; systemctl stop "lumeron-fiscal-$ID.timer"
fi
# Persistent fast queue is stopped gracefully (TimeoutStopSec=240, job timeout=180).
if systemctl is-active --quiet "lumeron-fiscal-fast-$ID.service"; then
  FAST_RUNNING=1; systemctl stop "lumeron-fiscal-fast-$ID.service"
fi
if systemctl is-active --quiet "lumeron-schedule-$ID.timer"; then
  SCHEDULE_RUNNING=1; systemctl stop "lumeron-schedule-$ID.timer"
fi
# Don't interrupt an ongoing SEFAZ transmission. Operator must retry when idle.
if systemctl is-active --quiet "lumeron-fiscal-$ID.service" ||
   systemctl is-active --quiet "lumeron-schedule-$ID.service"; then
  lm_die 'Fiscal ou agendador ainda executando. Aguarde conclusão; nenhum processo será morto.'
fi
lm_tenant_php "$ID" "$PHP_VERSION" "$CURRENT" down --retry=30
MAINTENANCE=1
BACKUPS="/var/backups/lumeron/$ID"
install -d -m 700 "$BACKUPS"
STAMP="$(date -u +%Y%m%d%H%M%S)-$(openssl rand -hex 3)"
mysqldump --defaults-extra-file="/root/lumeron/$ID.mysql.cnf" --single-transaction \
  --quick --routines --triggers "lum_$ID" | gzip > "$BACKUPS/$STAMP.sql.gz"
[[ -s "$BACKUPS/$STAMP.sql.gz" ]] || lm_die 'Backup vazio; operação bloqueada.'
chmod 600 "$BACKUPS/$STAMP.sql.gz"
if ((!ROLLBACK)); then
  # All migrations run using the tenant's own .env and MySQL user.
  # Schema changes must be backward-compatible for a code-only rollback.
  lm_tenant_php "$ID" "$PHP_VERSION" "$TARGET" migrate --force
  if [[ -n "$CONTROL_PATH" ]]; then
    (cd "$CONTROL_PATH" && "/usr/bin/php$PHP_VERSION" artisan control:bind-local \
      "$TARGET" --url="$CONTROL_URL" --file-owner="$USER")
    lm_tenant_php "$ID" "$PHP_VERSION" "$TARGET" lumeron:license-sync
  fi
fi
ln -s "releases/$(basename "$CURRENT")" "$BASE/.previous-next"
mv -Tf "$BASE/.previous-next" "$BASE/previous"
lm_atomic_current "$BASE" "$TARGET"
SWITCHED=1
systemctl reload "php$PHP_VERSION-fpm"
lm_tenant_php "$ID" "$PHP_VERSION" "$BASE/current" up
MAINTENANCE=0
SWITCHED=0
if ((FISCAL_RUNNING)); then
  systemctl start "lumeron-fiscal-$ID.timer"; FISCAL_RUNNING=0
fi
if ((SCHEDULE_RUNNING)); then
  systemctl start "lumeron-schedule-$ID.timer"; SCHEDULE_RUNNING=0
fi
if ((FAST_RUNNING)); then
  systemctl start "lumeron-fiscal-fast-$ID.service"; FAST_RUNNING=0
fi
trap - EXIT
echo "Código de $ID agora usa $SHA. Banco, APP_KEY, dados fiscais e filas preservados."
echo "Backup individual preservado em /var/backups/lumeron/$ID (não substitui backup externo)."
if ((ROLLBACK)); then echo 'Rollback apenas de código: confira migrações e compatibilidade do schema.'; fi
