#!/bin/bash
set -euo pipefail

TOKEN=$(openssl rand -hex 32)
DESK_URL="http://194.67.92.69:8088"
CRM_URL="https://lead-control.space"

# --- lead-desk .env ---
cd /var/www/lead-desk
php artisan key:generate --force >/dev/null 2>&1 || true

# Keep existing APP_KEY if set
set_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${val}|" .env
  else
    echo "${key}=${val}" >> .env
  fi
}

set_env APP_NAME "LeadDesk"
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "${DESK_URL}"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE lead_desk
set_env DB_USERNAME lc
# password already known on server — pull from CRM1 env
CRM_DB_PASS=$(grep '^DB_PASSWORD=' /var/www/lead-control/.env | cut -d= -f2-)
set_env DB_PASSWORD "${CRM_DB_PASS}"
set_env SESSION_DRIVER file
set_env CACHE_STORE file
set_env QUEUE_CONNECTION sync
set_env CRM1_BASE_URL "${CRM_URL}"
set_env CRM1_API_TOKEN "${TOKEN}"
set_env CRM1_SSO_URL "${CRM_URL}/desk-sso"
set_env DESK_API_TOKEN "${TOKEN}"

# --- CRM1 env ---
cd /var/www/lead-control
if grep -q '^DESK_API_TOKEN=' .env; then
  sed -i "s|^DESK_API_TOKEN=.*|DESK_API_TOKEN=${TOKEN}|" .env
else
  echo "DESK_API_TOKEN=${TOKEN}" >> .env
fi
if grep -q '^DESK_PUBLIC_URL=' .env; then
  sed -i "s|^DESK_PUBLIC_URL=.*|DESK_PUBLIC_URL=${DESK_URL}|" .env
else
  echo "DESK_PUBLIC_URL=${DESK_URL}" >> .env
fi

# --- migrate desk ---
cd /var/www/lead-desk
# drop default sqlite migrations noise: run only our migration after ensuring users table from laravel
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\DeskSeeder --force
php artisan desk:sync --full || true

# schedule already in routes/console.php from deploy

chown -R www-data:www-data /var/www/lead-desk/storage /var/www/lead-desk/bootstrap/cache

# --- nginx ---
cp /var/www/lead-desk/deploy/nginx-desk-temp.conf /etc/nginx/sites-available/lead-desk
ln -sfn /etc/nginx/sites-available/lead-desk /etc/nginx/sites-enabled/lead-desk
nginx -t
systemctl reload nginx

# open firewall if ufw
if command -v ufw >/dev/null; then
  ufw allow 8088/tcp || true
fi

cd /var/www/lead-control
php artisan optimize:clear
systemctl restart php8.2-fpm

echo "DESK_URL=${DESK_URL}"
echo "TOKEN_SET=yes"
echo "DONE"
