#!/usr/bin/env bash
# Standalone CI integration: same immutable bytes, different Laravel paths/DBs.
# Creates only temp test data; never connects to a VPS.
set -Eeuo pipefail
umask 077
REPO="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
command -v php >/dev/null
command -v openssl >/dev/null
[[ -f "$REPO/vendor/autoload.php" ]] || { echo 'Execute composer install antes deste teste.' >&2; exit 1; }
ROOT="$(mktemp -d)"
trap 'rm -rf --one-file-system "$ROOT"' EXIT
SHARED="$ROOT/code/shared"
mkdir -p "$SHARED"
cp -al "$REPO/." "$SHARED/"
rm -rf "$SHARED/.git" "$SHARED/storage" "$SHARED/bootstrap/cache" "$SHARED/public/uploads"
rm -f "$SHARED/.env"
setup() {
  local id="$1" cnpj="$2" home="$ROOT/instances/$1" runtime
  mkdir -p "$home/private/storage/app/private" "$home/private/storage/framework/cache/data" \
    "$home/private/storage/framework/sessions" "$home/private/storage/framework/views" \
    "$home/private/storage/logs" "$home/releases/r1"
  runtime="$home/releases/r1"
  cp -al "$SHARED/." "$runtime/"
  mkdir -p "$runtime/bootstrap/cache"
  ln -s "$home/private/storage" "$runtime/storage"
  touch "$home/private/database.sqlite"
  key="$(openssl rand -base64 32 | tr -d '\n')"
  cat >"$home/private/.env" <<ENV
APP_NAME=Lumeron_Test_$id
APP_ENV=testing
APP_DEBUG=false
APP_KEY=base64:$key
APP_URL=http://$id.test
DB_CONNECTION=sqlite
DB_DATABASE=$home/private/database.sqlite
SESSION_DRIVER=file
SESSION_COOKIE=$id
CACHE_STORE=file
CACHE_PREFIX=$id
QUEUE_CONNECTION=database
LOG_CHANNEL=single
MAIL_MAILER=log
LUMERON_INSTANCE_ID=c_$id
LUMERON_INSTANCE_CNPJ=$cnpj
LUMERON_INSTANCE_SUBDOMAIN=$id
LUMERON_BASE_DOMAIN=lumeron.com.br
ENV
  ln -s "$home/private/.env" "$runtime/.env"
  ln -s releases/r1 "$home/current"
  echo release_a > "$runtime/.lumeron-instance-code"
}
setup cliente_a 11222333000181
setup cliente_b 55652306000114
A="$ROOT/instances/cliente_a"
B="$ROOT/instances/cliente_b"
[[ "$(stat -c '%i' "$A/current/artisan")" == "$(stat -c '%i' "$B/current/artisan")" ]]
[[ "$(stat -c '%h' "$SHARED/artisan")" -ge 3 ]]
[[ "$(readlink -f "$A/current/.env")" != "$(readlink -f "$B/current/.env")" ]]
[[ "$(readlink -f "$A/current/storage")" != "$(readlink -f "$B/current/storage")" ]]
[[ "$(readlink -f "$A/current/bootstrap/cache")" != "$(readlink -f "$B/current/bootstrap/cache")" ]]
echo '[OK] Código compartilhado sem compartilhamento de estado.'
(cd "$A/current" && php artisan migrate --force --no-interaction >/dev/null &&
  php artisan optimize >/dev/null &&
  php artisan schedule:list >/dev/null &&
  php artisan queue:work database --stop-when-empty --max-time=5 >/dev/null)
(cd "$B/current" && php artisan migrate --force --no-interaction >/dev/null &&
  php artisan optimize >/dev/null &&
  php artisan schedule:list >/dev/null &&
  php artisan queue:work database --stop-when-empty --max-time=5 >/dev/null)
php -r '
$a=new PDO("sqlite:".$argv[1]); $b=new PDO("sqlite:".$argv[2]);
$a->exec("CREATE TABLE tenant_proof (v TEXT NOT NULL)");
$b->exec("CREATE TABLE tenant_proof (v TEXT NOT NULL)");
$a->exec("INSERT INTO tenant_proof VALUES (\"a\")");
$b->exec("INSERT INTO tenant_proof VALUES (\"b\")");
if($a->query("SELECT v FROM tenant_proof")->fetchColumn()!=="a"
|| $b->query("SELECT v FROM tenant_proof")->fetchColumn()!=="b")exit(1);
' "$A/private/database.sqlite" "$B/private/database.sqlite"
[[ -f "$A/current/bootstrap/cache/config.php" && -f "$B/current/bootstrap/cache/config.php" ]]
[[ "$A/current/bootstrap/cache/config.php" -nt "$SHARED/artisan" ]]
grep -Fq "$A/private/database.sqlite" "$A/current/bootstrap/cache/config.php"
grep -Fq "$B/private/database.sqlite" "$B/current/bootstrap/cache/config.php"
! grep -Fq "$B/private/database.sqlite" "$A/current/bootstrap/cache/config.php"
! grep -Fq "$A/private/database.sqlite" "$B/current/bootstrap/cache/config.php"
echo '[OK] Bancos, caches Laravel, CLI e agendadores isolados.'
# A targeted version switch cannot change B's immutable runtime.
NEW="$ROOT/code/new"
mkdir -p "$NEW"
cp -a "$SHARED/." "$NEW/"
printf 'version-2\n' > "$NEW/.lumeron-new-version"
mkdir -p "$A/releases/r2"
cp -al "$NEW/." "$A/releases/r2/"
mkdir -p "$A/releases/r2/bootstrap/cache"
ln -s "$A/private/storage" "$A/releases/r2/storage"
ln -s "$A/private/.env" "$A/releases/r2/.env"
echo release_b > "$A/releases/r2/.lumeron-instance-code"
ln -s releases/r1 "$A/previous"
ln -s releases/r2 "$A/.current-next"
mv -Tf "$A/.current-next" "$A/current"
[[ -f "$A/current/.lumeron-new-version" ]]
[[ ! -e "$B/current/.lumeron-new-version" ]]
[[ "$(readlink "$B/current")" == releases/r1 ]]
[[ -f "$A/previous/bootstrap/cache/config.php" ]]
[[ -f "$B/current/bootstrap/cache/config.php" ]]
[[ -f "$A/private/database.sqlite" && -f "$B/private/database.sqlite" ]]
# Simulated failure before switch: active runtime stays selected, and both
# databases/files persist. Retention must preserve current and previous.
mkdir "$A/releases/rbad"
[[ "$(readlink "$A/current")" == releases/r2 ]]
[[ -f "$A/previous/.lumeron-instance-code" ]]
echo '[OK] Atualização individual, versões simultâneas e recuperação sem perda de dados.'
