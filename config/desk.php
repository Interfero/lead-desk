<?php

return [
    'crm1_base_url' => rtrim((string) env('CRM1_BASE_URL', 'https://lead-control.space'), '/'),
    'crm1_token' => env('CRM1_API_TOKEN', env('DESK_API_TOKEN', '')),
    'crm1_sso_url' => env('CRM1_SSO_URL', 'https://lead-control.space/crm/desk-sso'),
    'crm2_base_url' => rtrim((string) env('CRM2_BASE_URL', 'https://kp-lead-centre.ru'), '/'),
    // crm_connections.id источника KP-Lead
    'kp_crm_id' => (int) env('DESK_KP_CRM_ID', 2),
    // Bearer для server-to-server записи статусов из Lead Control (GM accept)
    'internal_token' => (string) env('DESK_INTERNAL_TOKEN', ''),

    // Автосинхронизация CRM1 при открытии списка (без КП — КП только cron).
    'auto_sync_on_visit' => env('DESK_AUTO_SYNC_ON_VISIT', true),
    'auto_sync_stale_minutes' => (int) env('DESK_AUTO_SYNC_STALE_MINUTES', 3),
    'auto_sync_time_limit' => (int) env('DESK_AUTO_SYNC_TIME_LIMIT', 120),
];
