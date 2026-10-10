#!/usr/bin/env bash
# One CNPJ = one dedicated MySQL schema/user, Linux user, PHP-FPM pool and worker.
# Never mutates the server unless --apply is supplied.
set -Eeuo pipefail
umask 077

ID="" CNPJ="" DOMAIN="" SOURCE="" APPLY=0 PHP_VERSION="8.3" MYSQL_CNF="/root/.my.cnf"
usage() { echo "Uso: bash deploy/instances/provision.sh --id academia_ba --cnpj 00000000000000 --domain academia.exemplo.com.br --source /opt/lumeron/source [--php-version 8.3] [--mysql-admin-cnf /root/.my.cnf] [--apply]"; }
while (($#)); do
  case "$1" in
    --id|--cnpj|--domain|--source|--php-version|--mysql-admin-cnf)
      option="$1"; (($# >= 2)) || { usage; exit 2; }
      case "$option" in
        --id) ID="$2";; --cnpj) CNPJ="$2";; --domain) DOMAIN="$2";;
        --source) SOURCE="$2";; --php-version) PHP_VERSION="$2";;
        --mysql-admin-cnf) MYSQL_CNF="$2";;
      esac
      shift 2;;
    --apply) APPLY=1; shift;;
    *) usage; exit 2;;
  esac
done
[[ "$ID" =~ ^[a-z][a-z0-9_]{1,18}$ ]] || { echo "ID inválido." >&2; exit 2; }
[[ "$CNPJ" =~ ^[0-9]{14}$ ]] || { echo "CNPJ deve ter 14 dígitos." >&2; exit 2; }
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && "$DOMAIN" == *.* ]] || { echo "Domínio inválido." >&2; exit 2; }
[[ "$PHP_VERSION" =~ ^8\.[34]$ ]] || { echo "Versão PHP inválida." >&2; exit 2; }
[[ -f "$SOURCE/composer.json" ]] || { echo "Source não contém Laravel." >&2; exit 2; }
[[ -f "$SOURCE/composer.lock" ]] || { echo "Falta composer.lock versionado: provisionamento bloqueado para segurança." >&2; exit 2; }
BASE="/srv/lumeron/instances/$ID"; USER="lum_$ID"; DB="lum_$ID"
STAMP="$(date -u +%Y%m%d%H%M%S)-$(openssl rand -hex 3)"
RELEASE="$BASE/releases/$STAMP"
POOL="/etc/php/$PHP_VERSION/fpm/pool.d/lumeron-$ID.conf"
SITE="/etc/nginx/sites-available/lumeron-$ID.conf"
WORKER="/etc/systemd/system/lumeron-fiscal-$ID.service"
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
CERTKEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
echo "Instalação $ID | CNPJ $CNPJ | https://$DOMAIN | Banco $DB | PHP $PHP_VERSION"
if ((APPLY==0)); then echo "Simulação: nada foi alterado. Acrescente --apply quando aprovado."; exit 0; fi
((EUID==0)) || { echo "Somente root na VPS." >&2; exit 1; }
[[ ! -e "$BASE" && ! -L "$BASE" ]] || { echo "Instalação existente. Use update.sh." >&2; exit 1; }
! id "$USER" &>/dev/null || { echo "Usuário já existente." >&2; exit 1; }
[[ -r "$CERT" && -r "$CERTKEY" ]] || { echo "TLS ausente. Obtenha certificado antes." >&2; exit 1; }
[[ -r "$MYSQL_CNF" ]] || { echo "Credenciais administrativas MySQL ausentes." >&2; exit 1; }
[[ -x "/usr/bin/php$PHP_VERSION" && -d "/etc/php/$PHP_VERSION/fpm/pool.d" ]] || { echo "PHP CLI/FPM não instalado." >&2; exit 1; }
for binary in mysql rsync composer nginx runuser; do command -v "$binary" >/dev/null || { echo "Falta $binary." >&2; exit 1; }; done
[[ ! -e "$POOL" && ! -e "$SITE" && ! -e "$WORKER" ]] || { echo "Configuração da instância já existe." >&2; exit 1; }
mysql --defaults-extra-file="$MYSQL_CNF" -N -e "SELECT 1" >/dev/null
[[ -z "$(mysql --defaults-extra-file="$MYSQL_CNF" -N -e "SHOW DATABASES LIKE '$DB'")" ]] || { echo "Banco já existe." >&2; exit 1; }

install -d -m 755 /srv/lumeron /srv/lumeron/instances "$BASE" "$BASE/releases"
install -d -m 700 "$BASE/shared" "$BASE/shared/storage"
install -d -m 755 "$BASE/uploads"
install -d -m 700 "$BASE/shared/storage/app/private"
install -d -m 700 "$BASE/shared/storage/framework/cache/data" "$BASE/shared/storage/framework/sessions"
install -d -m 700 "$BASE/shared/storage/framework/views" "$BASE/shared/storage/logs" "$BASE/shared/acbr"
useradd --system --user-group --home-dir "$BASE/shared" --shell /usr/sbin/nologin "$USER"
chown -R "$USER:$USER" "$BASE/shared" "$BASE/uploads"
install -d -m 755 "$RELEASE" "$RELEASE/public" "$RELEASE/bootstrap/cache"
rsync -a --delete --exclude='/.env' --exclude='/.git/' --exclude='/storage/' --exclude='/vendor/' --exclude='/public/uploads/' "$SOURCE/" "$RELEASE/"
ln -s "$BASE/shared/storage" "$RELEASE/storage"
ln -s "$BASE/uploads" "$RELEASE/public/uploads"
chown -R "$USER:$USER" "$RELEASE"

DB_PASSWORD="$(openssl rand -hex 32)"
APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
mysql --defaults-extra-file="$MYSQL_CNF" <<SQL
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$USER'@'127.0.0.1';
SQL
cat >"$BASE/shared/.env" <<ENV
APP_NAME=Lumeron-$ID
APP_ENV=production
APP_DEBUG=false
APP_KEY=$APP_KEY
APP_URL=https://$DOMAIN
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR
LOG_CHANNEL=stack
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB
DB_USERNAME=$USER
DB_PASSWORD=$DB_PASSWORD
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
LUMERON_INSTANCE_CNPJ=$CNPJ
MAIL_MAILER=log
ACBr_NFE_LIBRARY_PATH=
ACBr_NFE_SCHEMAS_PATH=
ACBr_NFE_CONFIG_PATH=$BASE/shared/acbr/config.ini
ENV
chown "$USER:$USER" "$BASE/shared/.env"
chmod 600 "$BASE/shared/.env"
ln -s "$BASE/shared/.env" "$RELEASE/.env"
install -d -m 700 /root/lumeron
cat >"/root/lumeron/$ID.mysql.cnf" <<ENV
[client]
host=127.0.0.1
user=$USER
password=$DB_PASSWORD
ENV
chmod 600 "/root/lumeron/$ID.mysql.cnf"
unset DB_PASSWORD APP_KEY

cd "$RELEASE"
runuser -u "$USER" -- composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" artisan migrate --force
runuser -u "$USER" -- "/usr/bin/php$PHP_VERSION" artisan optimize
ln -s "$RELEASE" "$BASE/current"

cat >"$POOL" <<ENV
[lumeron_$ID]
user = $USER
group = $USER
listen = /run/php/php$PHP_VERSION-fpm-lumeron-$ID.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 15s
pm.max_requests = 500
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
    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
    access_log /var/log/nginx/lumeron-$ID.access.log;
    error_log /var/log/nginx/lumeron-$ID.error.log;
}
ENV
cat >"$WORKER" <<ENV
[Unit]
Description=Lumeron $ID - fiscal queue
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
User=$USER
Group=$USER
WorkingDirectory=$BASE/current
ExecStart=/usr/bin/php$PHP_VERSION $BASE/current/artisan queue:work database --queue=fiscal,default --sleep=2 --tries=1 --timeout=180 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=240
KillSignal=SIGTERM
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
[Install]
WantedBy=multi-user.target
ENV
ln -s "$SITE" "/etc/nginx/sites-enabled/lumeron-$ID.conf"
systemctl daemon-reload
"/usr/sbin/php-fpm$PHP_VERSION" -t
nginx -t
systemctl reload "php$PHP_VERSION-fpm"
systemctl reload nginx
echo "Instância criada: https://$DOMAIN"
echo "Crie o administrador: cd $BASE/current && runuser -u $USER -- /usr/bin/php$PHP_VERSION artisan erp:make-admin"
echo "Cadastre exatamente o CNPJ contratado, configure certificado A1, CSC e a biblioteca ACBr Linux."
echo "Verifique: cd $BASE/current && runuser -u $USER -- /usr/bin/php$PHP_VERSION artisan lumeron:instance-check --fiscal"
echo "Após validar: systemctl enable --now lumeron-fiscal-$ID"
echo "Worker FISCAL deliberadamente desativado até a configuração fiscal ser concluída."
