# Lead Desk — отдельное Единое окно заказов

## Сейчас (до домена)
- Приложение: `/var/www/lead-desk`
- БД: `lead_desk`
- Временный доступ: `http://194.67.92.69:8088` (HTTP)
- Вход: из CRM меню «Единое окно» → SSO (`/desk-sso`)

## Поддомен позже
1. DNS: `A desk.lead-control.space → 194.67.92.69`
2. Nginx `server_name desk.lead-control.space` + `certbot`
3. В CRM1 `.env`: `DESK_PUBLIC_URL=https://desk.lead-control.space`
4. В desk `.env`: `APP_URL=https://desk.lead-control.space`

## Auth B (SSO)
CRM1 (залогинен) → `/desk-sso` → one-time code → desk `/sso/callback` → локальная сессия desk.
API CRM1: Bearer `DESK_API_TOKEN` + `X-Desk-User-Id`.
