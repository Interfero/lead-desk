#!/bin/bash
set -euo pipefail

# CRM env
cd /var/www/lead-control
grep -q '^APP_URL=' .env && sed -i 's|^APP_URL=.*|APP_URL=https://lead-control.space|' .env || echo 'APP_URL=https://lead-control.space' >> .env
grep -q '^ASSET_URL=' .env && sed -i 's|^ASSET_URL=.*|ASSET_URL=https://lead-control.space/crm|' .env || echo 'ASSET_URL=https://lead-control.space/crm' >> .env
grep -q '^HUB_PUBLIC_URL=' .env && sed -i 's|^HUB_PUBLIC_URL=.*|HUB_PUBLIC_URL=https://lead-control.space|' .env || echo 'HUB_PUBLIC_URL=https://lead-control.space' >> .env
grep -q '^DESK_PUBLIC_URL=' .env && sed -i 's|^DESK_PUBLIC_URL=.*|DESK_PUBLIC_URL=https://lead-control.space/desk|' .env || echo 'DESK_PUBLIC_URL=https://lead-control.space/desk' >> .env

# Desk/Hub env
cd /var/www/lead-desk
grep -q '^APP_URL=' .env && sed -i 's|^APP_URL=.*|APP_URL=https://lead-control.space|' .env || echo 'APP_URL=https://lead-control.space' >> .env
grep -q '^CRM1_BASE_URL=' .env && sed -i 's|^CRM1_BASE_URL=.*|CRM1_BASE_URL=https://lead-control.space|' .env || echo 'CRM1_BASE_URL=https://lead-control.space' >> .env
grep -q '^SESSION_DOMAIN=' .env && sed -i 's|^SESSION_DOMAIN=.*|SESSION_DOMAIN=|' .env || true
# keep same DESK_API_TOKEN as CRM
TOKEN=$(grep '^DESK_API_TOKEN=' /var/www/lead-control/.env | cut -d= -f2-)
grep -q '^CRM1_API_TOKEN=' .env && sed -i "s|^CRM1_API_TOKEN=.*|CRM1_API_TOKEN=${TOKEN}|" .env || echo "CRM1_API_TOKEN=${TOKEN}" >> .env
grep -q '^DESK_API_TOKEN=' .env && sed -i "s|^DESK_API_TOKEN=.*|DESK_API_TOKEN=${TOKEN}|" .env || echo "DESK_API_TOKEN=${TOKEN}" >> .env

# nginx
cp /var/www/lead-desk/deploy/nginx-hub-desk-crm.conf /etc/nginx/sites-available/lead-control
ln -sfn /etc/nginx/sites-available/lead-control /etc/nginx/sites-enabled/lead-control
# disable separate desk host to avoid confusion (keep 8088 file but remove if conflicts)
rm -f /etc/nginx/sites-enabled/lead-desk || true

nginx -t
systemctl reload nginx

cd /var/www/lead-control && php artisan optimize:clear && php artisan route:list --path=crm 2>/dev/null | head -20
cd /var/www/lead-desk && php artisan optimize:clear && php artisan route:list 2>/dev/null | head -25
systemctl restart php8.2-fpm

echo DONE
