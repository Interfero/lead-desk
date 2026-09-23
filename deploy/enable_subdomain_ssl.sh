#!/bin/bash
set -euo pipefail

DOMAIN=desk.lead-control.space
DESK_URL="https://${DOMAIN}"

echo "Waiting for public DNS ${DOMAIN} → 194.67.92.69 ..."
for i in $(seq 1 60); do
  IP=$(dig +short "${DOMAIN}" A @8.8.8.8 | tail -n1 || true)
  echo "  try ${i}: '${IP}'"
  if [ "${IP}" = "194.67.92.69" ]; then
    break
  fi
  sleep 5
done

IP=$(dig +short "${DOMAIN}" A @8.8.8.8 | tail -n1 || true)
if [ "${IP}" != "194.67.92.69" ]; then
  echo "DNS not ready yet (got '${IP}'). Add Cloudflare A record first."
  exit 2
fi

certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos --redirect \
  --register-unsafely-without-email || \
certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos --redirect \
  -m admin@lead-control.space

# Update env URLs
set_env() {
  local file="$1" key="$2" val="$3"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$file"
  else
    echo "${key}=${val}" >> "$file"
  fi
}

set_env /var/www/lead-desk/.env APP_URL "${DESK_URL}"
set_env /var/www/lead-control/.env DESK_PUBLIC_URL "${DESK_URL}"

cd /var/www/lead-desk && php artisan optimize:clear
cd /var/www/lead-control && php artisan optimize:clear
systemctl reload nginx
systemctl restart php8.2-fpm

echo "OK ${DESK_URL}"
