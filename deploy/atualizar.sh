#!/usr/bin/env bash
# =============================================================================
# NotaPix - atualização no servidor, como root:   bash /var/www/notapix/deploy/atualizar.sh
#
# Busca a versão nova no GitHub, faz backup, põe o sistema em manutenção por alguns
# segundos, roda as migrações e reinicia a fila. O .env e os arquivos (storage) do
# servidor não são tocados.
# =============================================================================
set -euo pipefail

APP=/var/www/notapix
[ "$(id -u)" = "0" ] || { echo "Rode como root."; exit 1; }
[ -f "$APP/.env" ] || { echo "Sistema não instalado. Use instalar-servidor.sh."; exit 1; }

artisan() { sudo -u www-data php "$APP/artisan" "$@"; }
cd "$APP"
git config --global --add safe.directory "$APP" 2>/dev/null || true
git config core.fileMode false

ANTES=$(git rev-parse --short HEAD)
git fetch --quiet origin
if [ "$(git rev-parse HEAD)" = "$(git rev-parse '@{u}')" ] && [ "${1:-}" != "--forcar" ]; then
    echo "Já está na versão mais nova ($ANTES). Nada a fazer. (Use --forcar para refazer mesmo assim.)"
    exit 0
fi

echo "==> Backup antes de atualizar"
/usr/local/bin/notapix-backup

echo "==> Manutenção"
artisan down --retry=15 || true
trap 'artisan up || true' EXIT   # se algo falhar, o sistema volta ao ar

echo "==> Código novo"
git pull --ff-only --quiet
git log --oneline "$ANTES"..HEAD | head -20

export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
if [ -f package-lock.json ]; then npm ci --no-audit --no-fund --silent; else npm install --no-audit --no-fund --silent; fi
npm run build >/dev/null
rm -rf node_modules
chown -R www-data:www-data "$APP"

echo "==> Banco de dados"
artisan migrate --force

echo "==> Caches e fila"
artisan optimize:clear >/dev/null
artisan optimize
artisan queue:restart
systemctl reload php8.5-fpm

echo
echo "Atualizado: $ANTES -> $(git rev-parse --short HEAD)"
