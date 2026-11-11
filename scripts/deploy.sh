#!/usr/bin/env bash
# Publica o Treinr no servidor (treinr.space) pelo SSH do Cloudflare Tunnel.
#   bash scripts/deploy.sh
# Mantém no servidor: .env, storage/ (fotos, logs) e vendor/ (reinstalado só se o composer.lock mudar).
set -euo pipefail
cd "$(dirname "$0")/.."

HOST=questo@ssh.treinr.space
KEY="$HOME/.ssh/questo_servidor"
CLOUDFLARED="${CLOUDFLARED:-/c/dev/cloudflared/cloudflared.exe}"
APP=/var/www/treinr
SSH_OPTS=(-i "$KEY" -o BatchMode=yes -o "ProxyCommand=$CLOUDFLARED access ssh --hostname %h")
PKG="$(mktemp -d)/treinr.tar.gz"

echo "1/4 Compilando as telas"
npm run build >/dev/null

echo "2/4 Empacotando"
tar -czf "$PKG" \
    --exclude='bootstrap/cache/*.php' --exclude='storage' --exclude='public/hot' --exclude='resources/js' \
    app bootstrap config database lang public resources routes artisan composer.json composer.lock

echo "3/4 Enviando"
ssh "${SSH_OPTS[@]}" "$HOST" 'mkdir -p ~/treinr-deploy'
scp "${SSH_OPTS[@]}" "$PKG" "$HOST:treinr-deploy/treinr.tar.gz"

echo "4/4 Atualizando o servidor"
ssh "${SSH_OPTS[@]}" "$HOST" "bash -s" <<REMOTE
set -euo pipefail
cd $APP
OLD_LOCK=\$(md5sum composer.lock | cut -d' ' -f1)
php artisan down --retry=15 >/dev/null || true
# Troca o código (arquivos removidos no projeto somem também); .env, storage e vendor ficam.
rm -rf app bootstrap/app.php bootstrap/providers.php config database lang public resources routes
tar -xzf ~/treinr-deploy/treinr.tar.gz
if [ "\$(md5sum composer.lock | cut -d' ' -f1)" != "\$OLD_LOCK" ]; then
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
else
    composer dump-autoload --optimize --no-dev --no-interaction >/dev/null
fi
php artisan migrate --force --no-ansi
php artisan optimize --no-ansi >/dev/null
php artisan up >/dev/null
echo "Publicado: \$(date '+%d/%m/%Y %H:%M')"
REMOTE
rm -f "$PKG"
