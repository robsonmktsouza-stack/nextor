#!/usr/bin/env bash
# One CNPJ -> one MySQL database, Linux user, private state; shared immutable code.
# The existing Lumeron Control --managed arguments remain supported.
set -Eeuo pipefail
umask 077
SELF="$(cd -- "$(dirname -- "$0")" && pwd)"
source "$SELF/lib.sh"
ID= CNPJ= DOMAIN= SOURCE= BASE_DOMAIN=lumeron.com.br PHP_VERSION=8.3 MYSQL_CNF=/root/.my.cnf
RELEASE_SHA= TLS_CERT_NAME= COMPANY_NAME= ADMIN_NAME= ADMIN_EMAIL= ADMIN_LOGIN=
CONTROL_PATH= CONTROL_URL= MANAGED=0 APPLY=0
usage() { echo 'Uso: provision.sh --id c_2 --cnpj 00000000000000 --domain empresa.lumeron.com.br --source /opt/lumeron/source [--release SHA] [--managed ...] [--apply]'; }
while (($#)); do
  case "$1" in
    --id|--cnpj|--domain|--source|--release|--base-domain|--php-version|--mysql-admin-cnf|--company-name|--admin-name|--admin-email|--admin-login|--control-path|--control-url|--tls-cert-name)
      option="$1"; (($#>=2)) || { usage; exit 2; }
      case "$option" in
        --id) ID="$2";; --cnpj) CNPJ="$2";; --domain) DOMAIN="$2";;
        --source) SOURCE="$2";; --release) RELEASE_SHA="$2";;
        --base-domain) BASE_DOMAIN="$2";; --php-version) PHP_VERSION="$2";;
        --mysql-admin-cnf) MYSQL_CNF="$2";;
        --company-name) COMPANY_NAME="$2";; --admin-name) ADMIN_NAME="$2";;
        --admin-email) ADMIN_EMAIL="$2";; --admin-login) ADMIN_LOGIN="$2";;
        --control-path) CONTROL_PATH="$2";; --control-url) CONTROL_URL="$2";;
        --tls-cert-name) TLS_CERT_NAME="$2";;
      esac
      shift 2;;
    --managed) MANAGED=1; shift;;
    --apply) APPLY=1; shift;;
    *) usage; exit 2;;
  esac
done
lm_valid_id "$ID" || { echo 'ID inválido.' >&2; exit 2; }
[[ "$CNPJ" =~ ^[0-9]{14}$ ]] || { echo 'CNPJ deve ter 14 dígitos.' >&2; exit 2; }
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && "$DOMAIN" == *.* ]] ||
  { echo 'Domínio inválido.' >&2; exit 2; }
[[ "$BASE_DOMAIN" =~ ^[a-z0-9]+([.-][a-z0-9]+)*\.[a-z]{2,}$ ]] ||
  { echo 'Domínio base inválido.' >&2; exit 2; }
[[ "$DOMAIN" == *."$BASE_DOMAIN" ]] || { echo 'Fora do domínio contratado.' >&2; exit 2; }
SUBDOMAIN="$(printf '%s' "$DOMAIN" | sed "s/\.$BASE_DOMAIN$//")"
[[ "$SUBDOMAIN" =~ ^[a-z][a-z0-9-]{1,38}$ && "$SUBDOMAIN" != *--* ]] ||
  { echo 'Subdomínio inválido.' >&2; exit 2; }
[[ ! "$SUBDOMAIN" =~ ^(www|login|admin|api)$ ]] || { echo 'Subdomínio reservado.' >&2; exit 2; }
[[ "$PHP_VERSION" =~ ^8\.[34]$ ]] || { echo 'PHP inválido.' >&2; exit 2; }
[[ -f "$SOURCE/composer.lock" ]] || { echo 'Fonte sem composer.lock.' >&2; exit 2; }
[[ -z "$TLS_CERT_NAME" || "$TLS_CERT_NAME" =~ ^[a-zA-Z0-9_.-]{1,100}$ ]] ||
  { echo 'Nome de certificado inválido.' >&2; exit 2; }
if ((MANAGED)); then
  [[ -n "$COMPANY_NAME" && -n "$ADMIN_NAME" && "$ADMIN_EMAIL" == *@* &&
     "$ADMIN_LOGIN" =~ ^[a-z][a-z0-9._-]{2,49}$ &&
     "$CONTROL_URL" == https://* && -f "$CONTROL_PATH/artisan" ]] ||
     { echo 'Identidade gerenciada ou URL Control inválida.' >&2; exit 2; }
fi
BASE="$LM_INSTANCES/$ID"; USER="lum_$ID"; DB="$USER"
POOL="/etc/php/$PHP_VERSION/fpm/pool.d/lumeron-$ID.conf"
SITE="/etc/nginx/sites-available/lumeron-$ID.conf"
FISCAL="/etc/systemd/system/lumeron-fiscal-$ID.service"
FISCAL_TIMER="/etc/systemd/system/lumeron-fiscal-$ID.timer"
FISCAL_FAST="/etc/systemd/system/lumeron-fiscal-fast-$ID.service"
SCHEDULE="/etc/systemd/system/lumeron-schedule-$ID.service"
TIMER="/etc/systemd/system/lumeron-schedule-$ID.timer"
TLS_DIR="$DOMAIN"
[[ -z "$TLS_CERT_NAME" ]] || TLS_DIR="$TLS_CERT_NAME"
CERT="/etc/letsencrypt/live/$TLS_DIR/fullchain.pem"
CERTKEY="/etc/letsencrypt/live/$TLS_DIR/privkey.pem"
echo "Instalação $ID | CNPJ $CNPJ | https://$DOMAIN | Banco MySQL $DB"
if ((APPLY==0)); then
  echo 'Simulação: nada foi alterado. É exigido um release previamente aprovado.'
  exit 0
fi
lm_root
lm_require mysql git cp runuser systemctl nginx openssl flock
exec 8>/run/lumeron-code.lock
flock -s 8
[[ -d "$SOURCE" ]] || lm_die 'Fonte inexistente.'
SHA="$(lm_selected_sha "$RELEASE_SHA")"
CODE="$(lm_code_path "$SHA")"
[[ ! -e "$BASE" && ! -L "$BASE" ]] || lm_die 'Instalação existente. Use update.sh.'
! id "$USER" >/dev/null 2>&1 || lm_die 'Usuário Unix já existe.'
[[ -r "$CERT" && -r "$CERTKEY" && -r "$MYSQL_CNF" ]] || lm_die 'TLS ou MySQL administrativo indisponível.'
[[ -x "/usr/bin/php$PHP_VERSION" && -d "/etc/php/$PHP_VERSION/fpm/pool.d" ]] ||
  lm_die 'PHP CLI/FPM indisponível.'
[[ ! -e "$POOL" && ! -e "$SITE" && ! -e "$FISCAL" && ! -e "$FISCAL_TIMER" && ! -e "$FISCAL_FAST" && ! -e "$SCHEDULE" && ! -e "$TIMER" ]] ||
  lm_die 'Configuração pré-existente. Abortado.'
mysql --defaults-extra-file="$MYSQL_CNF" -N -e 'SELECT 1' >/dev/null
[[ -z "$(mysql --defaults-extra-file="$MYSQL_CNF" -N -e "SHOW DATABASES LIKE '$DB'")" ]] ||
  lm_die 'Banco já existente; abortado.'
if ((MANAGED)); then
  [[ -f "$CODE/app/Console/Commands/BootstrapManagedAccount.php" ]] ||
    lm_die 'Release não oferece primeiro acesso gerenciado.'
fi
install -d -m 755 "$LM_INSTANCES"
# Per instance lock; never run concurrent provisioning and updates.
exec 9>"/run/lumeron-provision-$ID.lock"
flock -n 9 || lm_die 'Já existe operação de instalação em andamento.'
useradd --system --user-group --home-dir "$BASE/shared" --shell /usr/sbin/nologin "$USER"
lm_init_storage "$ID"
STAMP="$(date -u +%Y%m%d%H%M%S)-$(openssl rand -hex 3)"
RUNTIME="$BASE/releases/$STAMP"
PASSWORD="$(openssl rand -hex 32)"
KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
mysql --defaults-extra-file="$MYSQL_CNF" <<SQL
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$USER'@'127.0.0.1' IDENTIFIED BY '$PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$USER'@'127.0.0.1';
SQL
cat >"$BASE/shared/.env" <<ENV
APP_NAME=Lumeron-$ID
APP_ENV=production
APP_DEBUG=false
APP_KEY=$KEY
APP_URL=https://$DOMAIN
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB
DB_USERNAME=$USER
DB_PASSWORD=$PASSWORD
SESSION_DRIVER=file
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=lumeron_$ID
CACHE_STORE=file
CACHE_PREFIX=lumeron_$ID
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=300
FILESYSTEM_DISK=local
LUMERON_INSTANCE_ID=$ID
LUMERON_INSTANCE_SUBDOMAIN=$SUBDOMAIN
LUMERON_INSTANCE_CNPJ=$CNPJ
LUMERON_BASE_DOMAIN=$BASE_DOMAIN
LUMERON_LOGIN_PORTAL_HOST=login.$BASE_DOMAIN
LUMERON_LOGIN_PORTAL=false
MAIL_MAILER=log
ACBr_NFE_LIBRARY_PATH=
ACBr_NFE_SCHEMAS_PATH=
ACBr_NFE_CONFIG_PATH=$BASE/shared/acbr/config.ini
ENV
chown "$USER:$USER" "$BASE/shared/.env"
chmod 600 "$BASE/shared/.env"
install -d -m 700 /root/lumeron
cat >"/root/lumeron/$ID.mysql.cnf" <<ENV
[client]
host=127.0.0.1
user=$USER
password=$PASSWORD
ENV
chmod 600 "/root/lumeron/$ID.mysql.cnf"
unset KEY PASSWORD
lm_stage_runtime "$SHA" "$BASE" "$ID" "$RUNTIME"
lm_tenant_php "$ID" "$PHP_VERSION" "$RUNTIME" package:discover --ansi
lm_tenant_php "$ID" "$PHP_VERSION" "$RUNTIME" migrate --force
if ((MANAGED)); then
  lm_tenant_php "$ID" "$PHP_VERSION" "$RUNTIME" erp:bootstrap-managed \
    --cnpj="$CNPJ" --company-name="$COMPANY_NAME" --admin-name="$ADMIN_NAME" \
    --admin-email="$ADMIN_EMAIL" --admin-login="$ADMIN_LOGIN"
  (cd "$CONTROL_PATH" && "/usr/bin/php$PHP_VERSION" artisan control:bind-local "$RUNTIME" \
    --url="$CONTROL_URL" --file-owner="$USER")
  lm_tenant_php "$ID" "$PHP_VERSION" "$RUNTIME" lumeron:license-sync
fi
lm_tenant_php "$ID" "$PHP_VERSION" "$RUNTIME" optimize
lm_atomic_current "$BASE" "$RUNTIME"
cat >"$POOL" <<ENV
[lumeron_$ID]
user = $USER
group = $USER
listen = /run/php/php$PHP_VERSION-fpm-lumeron-$ID.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
clear_env = yes
pm = ondemand
pm.max_children = 3
pm.process_idle_timeout = 10s
pm.max_requests = 500
php_admin_flag[log_errors] = on
php_admin_value[error_log] = $BASE/shared/storage/logs/php-fpm.log
ENV
cat >"$SITE" <<ENV
server {
    listen 80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}
server {
    listen 443 ssl;
    server_name $DOMAIN;
    root $BASE/current/public;
    index index.php;
    ssl_certificate $CERT;
    ssl_certificate_key $CERTKEY;
    add_header X-Content-Type-Options nosniff always;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php$PHP_VERSION-fpm-lumeron-$ID.sock;
    }
    location ^~ /uploads/ {
        add_header Content-Disposition attachment always;
        add_header X-Content-Type-Options nosniff always;
        try_files \$uri =404;
    }
    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
    access_log /var/log/nginx/lumeron-$ID.access.log;
    error_log /var/log/nginx/lumeron-$ID.error.log;
}
ENV
cat >"$FISCAL" <<ENV
[Unit]
Description=Lumeron $ID - fiscal queue (batch)
After=network-online.target
[Service]
Type=oneshot
User=$USER
Group=$USER
WorkingDirectory=$BASE/current
ExecStart=/usr/bin/php$PHP_VERSION $BASE/current/artisan queue:work database --queue=fiscal,default --stop-when-empty --sleep=1 --tries=1 --timeout=180 --max-time=240
TimeoutStartSec=300
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=$BASE/shared $BASE/uploads $BASE/releases
ENV
cat >"$FISCAL_FAST" <<ENV
[Unit]
Description=Lumeron $ID - dedicated low latency fiscal queue (opt-in)
After=network-online.target
[Service]
Type=simple
User=$USER
Group=$USER
WorkingDirectory=$BASE/current
ExecStart=/usr/bin/php$PHP_VERSION $BASE/current/artisan queue:work database --queue=fiscal,default --sleep=1 --tries=1 --timeout=180 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=240
KillSignal=SIGTERM
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=$BASE/shared $BASE/uploads $BASE/releases
[Install]
WantedBy=multi-user.target
ENV
cat >"$FISCAL_TIMER" <<ENV
[Unit]
Description=Lumeron $ID - fiscal queue wakeup (opt-in)
[Timer]
OnBootSec=30s
OnUnitInactiveSec=10s
Unit=lumeron-fiscal-$ID.service
[Install]
WantedBy=timers.target
ENV
cat >"$SCHEDULE" <<ENV
[Unit]
Description=Lumeron $ID - scheduler (per-tenant database)
[Service]
Type=oneshot
User=$USER
Group=$USER
WorkingDirectory=$BASE/current
ExecStart=/usr/bin/php$PHP_VERSION $BASE/current/artisan schedule:run
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=$BASE/shared $BASE/uploads $BASE/releases
ENV
cat >"$TIMER" <<ENV
[Unit]
Description=Lumeron $ID - scheduler timer
[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true
Unit=lumeron-schedule-$ID.service
[Install]
WantedBy=timers.target
ENV
ln -s "$SITE" "/etc/nginx/sites-enabled/lumeron-$ID.conf"
systemctl daemon-reload
"/usr/sbin/php-fpm$PHP_VERSION" -t
nginx -t
systemctl reload "php$PHP_VERSION-fpm"
systemctl reload nginx
echo "Instalação preparada: https://$DOMAIN | code=$SHA"
echo 'Fiscal batch/timer, fiscal fast/persistente e scheduler DESATIVADOS até validação.'
echo 'Não habilite NFC-e sem ACBr Linux/A1/CSC e validação fiscal real.'
