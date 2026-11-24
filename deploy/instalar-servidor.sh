#!/usr/bin/env bash
# =============================================================================
# NotaPix - instalação no servidor (VPS Ubuntu 22.04/24.04), rodar UMA vez como root.
#
#   bash instalar-servidor.sh notapix.digitabrasil.com.br seu@email.com.br git@github.com:USUARIO/notapix.git
#
# O código vem do GitHub (repositório privado, com "chave de implantação" só de leitura).
# Antes: enviar o .env do seu computador para /root/.env.origem (e-mail, responsável técnico...).
# Instala Nginx, PHP 8.5, PostgreSQL, fila (Supervisor), agendador (cron), backup diário
# e o certificado HTTPS (Let's Encrypt). Não mexe no RustDesk (portas 21115-21119).
# =============================================================================
set -euo pipefail

DOMINIO="${1:-}"
EMAIL="${2:-}"
REPO="${3:-}"
APP=/var/www/notapix
CHAVE_DEPLOY=/root/.ssh/notapix_deploy
PHPV=8.5

azul()  { echo -e "\n\033[1;34m==> $*\033[0m"; }
verde() { echo -e "\033[1;32m$*\033[0m"; }
aviso() { echo -e "\033[1;33m$*\033[0m"; }
erro()  { echo -e "\033[1;31m$*\033[0m"; exit 1; }

[ -n "$DOMINIO" ] && [ -n "$EMAIL" ] && [ -n "$REPO" ] || erro "Uso: bash instalar-servidor.sh notapix.seudominio.com.br seu@email.com.br git@github.com:USUARIO/notapix.git"
[ "$(id -u)" = "0" ] || erro "Rode como root (ou com sudo)."
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || aviso "Atenção: feito para Ubuntu; este servidor é ${PRETTY_NAME:-desconhecido}."

# Portas 80/443 precisam estar livres (ou já com o Nginx)
if ss -ltnp 2>/dev/null | grep -E ':(80|443)\s' | grep -vq nginx; then
    ss -ltnp | grep -E ':(80|443)\s'
    erro "As portas 80/443 já estão em uso por outro programa (acima). Me mande esta tela."
fi

export DEBIAN_FRONTEND=noninteractive

# -----------------------------------------------------------------------------
azul "1/9 Memória extra (swap de 2 GB, ajuda o Composer)"
if ! swapon --show | grep -q .; then
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    verde "Swap criado."
else
    verde "Já existe swap."
fi

# -----------------------------------------------------------------------------
azul "2/9 Instalando Nginx, PHP $PHPV, PostgreSQL, Node e utilitários (demora alguns minutos)"
apt-get update -q
apt-get install -y -q software-properties-common ca-certificates curl unzip git cron
if ! apt-cache policy | grep -q ondrej/php; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
fi
apt-get install -y -q nginx postgresql postgresql-contrib supervisor certbot python3-certbot-nginx \
    php$PHPV-fpm php$PHPV-cli php$PHPV-pgsql php$PHPV-curl php$PHPV-xml php$PHPV-soap \
    php$PHPV-mbstring php$PHPV-zip php$PHPV-gd php$PHPV-intl php$PHPV-bcmath

if ! command -v node >/dev/null || [ "$(node -v | cut -d. -f1 | tr -d v)" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y -q nodejs
fi

if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Ajustes do PHP
for sapi in fpm cli; do
cat > /etc/php/$PHPV/$sapi/conf.d/99-notapix.ini <<'INI'
date.timezone = America/Sao_Paulo
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 120
expose_php = Off
INI
done
systemctl restart php$PHPV-fpm

# -----------------------------------------------------------------------------
azul "3/9 Banco de dados PostgreSQL"
DBPASS="$(openssl rand -hex 16)"
if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='notapix'" | grep -q 1; then
    sudo -u postgres psql -qc "ALTER ROLE notapix WITH LOGIN PASSWORD '$DBPASS'"
else
    sudo -u postgres psql -qc "CREATE ROLE notapix WITH LOGIN PASSWORD '$DBPASS'"
fi
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='notapix'" | grep -q 1; then
    sudo -u postgres createdb -O notapix -E UTF8 notapix
fi
verde "Banco 'notapix' pronto (acesso só de dentro do servidor)."

# -----------------------------------------------------------------------------
azul "4/9 Código do GitHub em $APP"
if [ ! -f "$CHAVE_DEPLOY" ]; then
    mkdir -p /root/.ssh && chmod 700 /root/.ssh
    ssh-keygen -t ed25519 -N "" -C "notapix-deploy@$(hostname)" -f "$CHAVE_DEPLOY" >/dev/null
fi
grep -q "notapix_deploy" /root/.ssh/config 2>/dev/null || cat >> /root/.ssh/config <<SSHCFG
Host github.com
    IdentityFile $CHAVE_DEPLOY
    IdentitiesOnly yes
SSHCFG
ssh-keyscan -t ed25519 github.com >> /root/.ssh/known_hosts 2>/dev/null
sort -u /root/.ssh/known_hosts -o /root/.ssh/known_hosts

until git ls-remote "$REPO" >/dev/null 2>&1; do
    echo
    aviso "O servidor ainda não tem acesso ao repositório. No GitHub, abra o repositório →"
    aviso "Settings → Deploy keys → Add deploy key, dê o nome 'VPS' e cole esta chave (sem marcar 'Allow write access'):"
    echo
    cat "$CHAVE_DEPLOY.pub"
    echo
    read -r -p "Depois de salvar no GitHub, tecle Enter para tentar de novo (ou Ctrl+C para sair)... " _
done
verde "Acesso ao GitHub OK."

git config --global --add safe.directory "$APP" 2>/dev/null || true
PRIMEIRA_VEZ=0
[ -f "$APP/.env" ] || PRIMEIRA_VEZ=1
if [ -d "$APP/.git" ]; then
    git -C "$APP" pull --ff-only
else
    mkdir -p "$(dirname "$APP")"
    [ -d "$APP" ] && [ -n "$(ls -A "$APP")" ] && erro "$APP já existe e não é do GitHub. Renomeie ou apague a pasta e rode de novo."
    git clone "$REPO" "$APP"
fi
cd "$APP"
git config core.fileMode false   # as permissões ajustadas no servidor não contam como alteração
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

azul "5/9 Configuração (.env)"
if [ "$PRIMEIRA_VEZ" = "1" ]; then
    if [ -f /root/.env.origem ]; then
        cp /root/.env.origem .env
        rm -f /root/.env.origem   # tinha senhas do seu computador: não fica largado
        verde "Usando o .env do seu computador como base (e-mail, responsável técnico...)."
    else
        cp .env.example .env
        aviso "Sem .env do computador: usando o .env.example. Revise depois: nano $APP/.env"
    fi
fi
definir() {  # definir CHAVE valor
    local chave="$1" valor="$2"
    if grep -q "^${chave}=" .env; then
        sed -i "s|^${chave}=.*|${chave}=${valor}|" .env
    else
        echo "${chave}=${valor}" >> .env
    fi
}
definir APP_ENV production
definir APP_DEBUG false
definir APP_URL "https://$DOMINIO"
definir LOG_CHANNEL daily
definir LOG_LEVEL warning
definir DB_CONNECTION pgsql
definir DB_HOST 127.0.0.1
definir DB_PORT 5432
definir DB_DATABASE notapix
definir DB_USERNAME notapix
definir DB_PASSWORD "$DBPASS"
definir QUEUE_CONNECTION database
definir SESSION_DRIVER database
definir CACHE_STORE database
definir SESSION_SECURE_COOKIE true
definir OPENSSL_BIN openssl
if [ "$PRIMEIRA_VEZ" = "1" ]; then
    definir ADMIN_EMAIL "$EMAIL"
    definir ADMIN_SENHA ""
fi
chmod 640 .env

# -----------------------------------------------------------------------------
azul "6/9 Dependências (Composer) e CSS/JavaScript (npm)"
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi
npm run build
rm -rf node_modules
chown -R www-data:www-data "$APP"
chmod -R ug+rwX storage bootstrap/cache

artisan() { sudo -u www-data php "$APP/artisan" "$@"; }

if [ "$PRIMEIRA_VEZ" = "1" ]; then
    artisan key:generate --force
fi
artisan migrate --force
if [ "$PRIMEIRA_VEZ" = "1" ]; then
    artisan db:seed --force | tee /root/notapix-admin.txt
    chmod 600 /root/notapix-admin.txt
fi
artisan cfop:importar || aviso "CFOP: rode depois 'php artisan cfop:importar'"
artisan classtrib:importar || aviso "cClassTrib: rode depois 'php artisan classtrib:importar'"
artisan ncm:importar || aviso "NCM: o site do Siscomex não respondeu. Rode depois 'php artisan ncm:importar'"
artisan storage:link >/dev/null 2>&1 || true
artisan optimize

# -----------------------------------------------------------------------------
azul "7/9 Nginx (site $DOMINIO)"
cat > /etc/nginx/sites-available/notapix <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name __DOMINIO__;
    root __APP__/public;
    index index.php;

    client_max_body_size 12M;
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php__PHPV__-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 180;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
sed -i "s|__DOMINIO__|$DOMINIO|g; s|__APP__|$APP|g; s|__PHPV__|$PHPV|g" /etc/nginx/sites-available/notapix
ln -sf /etc/nginx/sites-available/notapix /etc/nginx/sites-enabled/notapix
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# -----------------------------------------------------------------------------
azul "8/9 Fila (Supervisor), agendador (cron) e backup diário"
cat > /etc/supervisor/conf.d/notapix-fila.conf <<CONF
[program:notapix-fila]
command=php $APP/artisan queue:work --sleep=3 --tries=1 --max-time=3600 --timeout=170
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=180
stdout_logfile=$APP/storage/logs/fila.log
redirect_stderr=true
CONF
supervisorctl reread >/dev/null
supervisorctl update >/dev/null
supervisorctl restart notapix-fila >/dev/null || true

cat > /usr/local/bin/notapix-backup <<'BKP'
#!/usr/bin/env bash
# Backup diário: banco + arquivos (certificados, XML, PDF). Guarda 14 dias.
set -e
DESTINO=/var/backups/notapix
mkdir -p "$DESTINO"
DIA=$(date +%Y-%m-%d)
sudo -u postgres pg_dump -Fc notapix > "$DESTINO/banco-$DIA.dump"
tar -czf "$DESTINO/arquivos-$DIA.tar.gz" -C /var/www/notapix/storage app .
cp /var/www/notapix/.env "$DESTINO/env-$DIA"
chmod 600 "$DESTINO"/*
find "$DESTINO" -type f -mtime +14 -delete
BKP
chmod 700 /usr/local/bin/notapix-backup

cat > /etc/cron.d/notapix <<CRON
# NotaPix: agendador do Laravel (a cada minuto) e backup (todo dia às 2h30)
* * * * * www-data cd $APP && php artisan schedule:run >> /dev/null 2>&1
30 2 * * * root /usr/local/bin/notapix-backup >> /var/log/notapix-backup.log 2>&1
CRON
chmod 644 /etc/cron.d/notapix

if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    ufw allow 'Nginx Full' >/dev/null
    verde "Firewall (ufw): liberadas as portas 80 e 443."
fi

# -----------------------------------------------------------------------------
azul "9/9 Certificado HTTPS (Let's Encrypt)"
IP_SERVIDOR="$(curl -4 -s --max-time 10 https://api.ipify.org || true)"
IP_DOMINIO="$(getent ahostsv4 "$DOMINIO" | awk 'NR==1{print $1}' || true)"
if [ -n "$IP_SERVIDOR" ] && [ "$IP_SERVIDOR" = "$IP_DOMINIO" ]; then
    certbot --nginx -d "$DOMINIO" -m "$EMAIL" --agree-tos --non-interactive --redirect
    verde "HTTPS ativo."
else
    aviso "O domínio $DOMINIO ainda não aponta para este servidor (domínio: ${IP_DOMINIO:-nenhum}, servidor: ${IP_SERVIDOR:-?})."
    aviso "Crie o registro A no DNS e, quando propagar, rode:"
    aviso "  certbot --nginx -d $DOMINIO -m $EMAIL --agree-tos --non-interactive --redirect"
fi

# -----------------------------------------------------------------------------
echo
verde "============================================================"
verde " NotaPix instalado: https://$DOMINIO"
verde "============================================================"
if [ "$PRIMEIRA_VEZ" = "1" ]; then
    echo "Login do Administrador (anote e apague o arquivo depois):"
    grep -E "Administrador criado|Senha gerada" /root/notapix-admin.txt || true
    echo "(cópia em /root/notapix-admin.txt)"
fi
echo
echo "Fila: supervisorctl status notapix-fila | Logs: $APP/storage/logs | Backups: /var/backups/notapix"
