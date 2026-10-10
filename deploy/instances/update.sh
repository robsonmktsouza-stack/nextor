#!/usr/bin/env bash
# Per-instance update: new release, DB backup, migration, atomic symlink switch.
# Updates ONE CNPJ and never resets its database, APP_KEY, certificates or storage.
set -Eeuo pipefail
umask 077
ID="" SOURCE="" APPLY=0 PHP_VERSION="8.3"
usage() { echo "Uso: bash deploy/instances/update.sh --id academia_ba --source /opt/lumeron/source [--php-version 8.3] [--apply]"; }
while (($#)); do
  case "$1" in
    --id|--source|--php-version)
      option="$1"; (($#>=2)) || { usage; exit 2; }
      case "$option" in --id) ID="$2";; --source) SOURCE="$2";; --php-version) PHP_VERSION="$2";; esac
      shift 2;;
    --apply) APPLY=1; shift;;
    *) usage; exit 2;;
  esac
done
[[ "$ID" =~ ^[a-z][a-z0-9_]{1,18}$ ]] || { echo "ID inválido." >&2; exit 2; }
[[ "$PHP_VERSION" =~ ^8\.[34]$ ]] || { echo "PHP inválido." >&2; exit 2; }
[[ -f "$SOURCE/composer.lock" ]] || { echo "Falta composer.lock versionado. Atualização bloqueada." >&2; exit 2; }
BASE="/srv/lumeron/instances/$ID"; APP="$BASE/current"
USER="lum_$ID"; MYSQL_CNF="/root/lumeron/$ID.mysql.cnf"
[[ -L "$APP" && -f "$BASE/shared/.env" ]] || { echo "Instância não provisionada." >&2; exit 1; }
echo "Atualização apenas da instalação $ID ($APP)"
if ((APPLY==0)); then echo "Simulação: nada foi alterado. Use --apply quando aprovado."; exit 0; fi
((EUID==0)) || { echo "Execute como root." >&2; exit 1; }
[[ -r "$MYSQL_CNF" ]] || { echo "Credenciais de backup do banco indisponíveis." >&2; exit 1; }
for binary in composer rsync mysqldump gzip runuser; do command -v "$binary" >/dev/null || { echo "Falta $binary." >&2; exit 1; }; done
STAMP="$(date -u +%Y%m%d%H%M%S)-$(openssl rand -hex 3)"
RELEASE="$BASE/releases/$STAMP"
install -d -m 755 "$RELEASE"
rsync -a --delete --exclude='/.env' --exclude='/.git/' --exclude='/storage/' --exclude='/vendor/' --exclude='/public/uploads/' "$SOURCE/" "$RELEASE/"
install -d -m 755 "$RELEASE/public" "$RELEASE/bootstrap/cache"
ln -s "$BASE/shared/storage" "$RELEASE/storage"
ln -s "$BASE/shared/uploads" "$RELEASE/public/uploads"
ln -s "$BASE/shared/.env" "$RELEASE/.env"
chown -R "$USER:$USER" "$RELEASE"
cd "$RELEASE"
runuser -u "$USER" -- composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" artisan optimize

WAS_RUNNING=0
MAINTENANCE=0
cleanup() {
  if ((MAINTENANCE)); then
    runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" "$APP/artisan" up || true
  fi
  if ((WAS_RUNNING)); then systemctl start "lumeron-fiscal-$ID" || true; fi
}
trap cleanup EXIT
if systemctl is-active --quiet "lumeron-fiscal-$ID"; then
  WAS_RUNNING=1
  systemctl stop "lumeron-fiscal-$ID"
fi
runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" "$APP/artisan" down --retry=30
MAINTENANCE=1
# Snapshot database before applying irreversible migrations.
BACKUPS="/var/backups/lumeron/$ID"
install -d -m 700 "$BACKUPS"
mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --quick --routines --triggers "lum_$ID" | gzip > "$BACKUPS/$STAMP.sql.gz"
test -s "$BACKUPS/$STAMP.sql.gz" || { echo "Backup vazio; atualização bloqueada." >&2; exit 1; }
echo "Backup criado: $BACKUPS/$STAMP.sql.gz (somente root)."
# Laravel migration must be backward compatible with the old version during rollout.
runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" artisan migrate --force
runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" artisan optimize
ln -s "$RELEASE" "$BASE/.current-next"
mv -Tf "$BASE/.current-next" "$APP"
systemctl reload "php$PHP_VERSION-fpm"
runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" "$APP/artisan" up
MAINTENANCE=0
if ((WAS_RUNNING)); then systemctl start "lumeron-fiscal-$ID"; WAS_RUNNING=0; fi
trap - EXIT
echo "Atualizado $ID. Release anterior preservado para reversão de CÓDIGO; restauração do banco exige plano próprio."
echo "Não reutilize o backup em outra empresa; configure cópia externa cifrada e política de retenção."
