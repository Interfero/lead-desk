<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <title>@yield('title', 'Единое окно') — Desk</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}?v={{ @filemtime(public_path('favicon.png')) ?: time() }}" sizes="128x128">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v={{ @filemtime(public_path('favicon.ico')) ?: time() }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('favicon.png') }}?v={{ @filemtime(public_path('favicon.png')) ?: time() }}">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bg:#f8fafc; --card:#fff; --border:#e5e7eb; --text:#030711; --muted:#6b7280;
            --accent:#6b26d9; --primary:#6b26d9; --ok:#059669; --err:#dc2626;
            --bg-light:#f3f4f6; --bg-white:#fff;
            --sidebar:#1f2937; --sidebar-fg:#f8fafc; --sidebar-accent:#111827;
            --navbar-height:56px;
            --font-sans:"Plus Jakarta Sans", system-ui, sans-serif;
        }
        * { box-sizing: border-box; }
        html {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden !important;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }
        body {
            margin:0;
            font-family: var(--font-sans);
            background:var(--bg);
            color:var(--text);
            letter-spacing:-0.02em;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden !important;
            overscroll-behavior-x: none;
            padding-top: var(--navbar-height);
        }
        .navbar {
            background:var(--sidebar); color:var(--sidebar-fg);
            height:var(--navbar-height); padding:0 1rem;
            display:flex; align-items:center; gap:0.5rem;
            position:fixed; top:0; left:0; right:0;
            z-index:100;
            width:100%;
            max-width:100%;
            overflow:hidden;
            box-sizing:border-box;
        }
        .navbar-brand { color:var(--sidebar-fg); font-size:1.25rem; font-weight:600; text-decoration:none; margin-right:1.5rem; white-space:nowrap; min-width:0; display:inline-flex; align-items:center; }
        .navbar-brand .brand-short { opacity:.85; margin-right:.35rem; }
        .navbar-menu { display:flex; align-items:center; list-style:none; margin:0; padding:0; flex:1; gap:0; min-width:0; }
        .navbar-link {
            display:inline-flex; align-items:center; gap:.4rem;
            padding:.75rem 1rem; color:color-mix(in srgb, var(--sidebar-fg) 65%, transparent);
            text-decoration:none; font-size:.875rem; white-space:nowrap; border-radius:.5rem;
        }
        .navbar-link:hover, .navbar-link.active { color:var(--sidebar-fg); background:var(--sidebar-accent); }
        .navbar-right { margin-left:auto; display:flex; align-items:center; gap:.75rem; color:color-mix(in srgb, var(--sidebar-fg) 70%, transparent); font-size:.875rem; min-width:0; flex-shrink:1; }
        .navbar-right > span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:10rem; }
        .navbar-right a, .navbar-right button.linkish {
            color:color-mix(in srgb, var(--sidebar-fg) 70%, transparent); text-decoration:none; background:none; border:0; cursor:pointer; font:inherit;
        }
        .navbar-right a:hover, .navbar-right button.linkish:hover { color:var(--sidebar-fg); }
        .mobile-toggle {
            display:none;
            flex-shrink:0;
            border:0;
            background:transparent;
            color:var(--sidebar-fg);
            font-size:1.5rem;
            line-height:1;
            padding:0.35rem 0.4rem;
            cursor:pointer;
        }
        .navbar-backdrop {
            display:none;
            position:fixed;
            inset:var(--navbar-height) 0 0 0;
            background:rgba(0,0,0,.45);
            z-index:95;
        }
        .navbar-backdrop.open { display:block; }
        .wrap { max-width:100%; width:100%; min-width:0; margin:0 auto; padding:0.75rem 1rem 1.5rem; box-sizing:border-box; overflow-x:hidden; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:10px; box-shadow:0 1px 2px rgb(0 0 0 / 0.04); max-width:100%; min-width:0; }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid var(--border); background:#fff; padding:0.5rem 0.875rem; border-radius:8px; cursor:pointer; font-size:0.875rem; text-decoration:none; color:inherit; font-family:inherit; }
        .btn-primary { background:var(--accent); border-color:var(--accent); color:#fff; }
        .btn-sm { padding:0.375rem 0.75rem; font-size:0.75rem; }
        .muted { color:var(--muted); }
        .pill { display:inline-block; border:1px solid var(--border); border-radius:6px; padding:4px 10px; font-size:13px; background:#fff; }
        .dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
        .dot-ok { background:var(--ok); } .dot-err { background:var(--err); } .dot-warn { background:#f59e0b; }
        .flash { padding:10px 14px; border-radius:8px; margin-bottom:12px; }
        .flash-ok { background:#d1fae5; } .flash-err { background:#fee2e2; }
        .form-control, .form-input, .table-filter {
            width:100%; padding:0.5rem 0.75rem; border:1px solid var(--border); border-radius:6px; font-size:0.875rem; background:#fff; font-family:inherit;
        }
        .form-label { display:block; margin-bottom:0.375rem; font-size:0.875rem; font-weight:500; color:var(--text); }
        .form-group { margin-bottom:1rem; }
        .form-row { display:flex; gap:1rem; }
        .form-row .form-group { flex:1; min-width:0; }
        .btn-secondary { background:#fff; }
        .btn-success { background:#059669; border-color:#059669; color:#fff; }
        .btn-order-compact { font-size:0.8125rem; padding:0.5rem 0.75rem; justify-content:center; }
        .table { width:100%; border-collapse:collapse; }
        .table th, .table td { padding:0.5rem 0.65rem; border-bottom:1px solid var(--border); text-align:left; font-size:0.8125rem; }
        .table-responsive { overflow-x:auto; max-width:100%; }
        .table-filter { min-width:0; font-size:0.75rem; padding:0.375rem 0.625rem; border-radius:9999px; }
        .orders-top { display:flex; flex-wrap:wrap; justify-content:space-between; gap:12px; margin-bottom:12px; align-items:flex-start; }
        #desk-ui-build, .orders-meta { display:none !important; }
        #deskFiltersForm.card { overflow:hidden; }
        .crm-sync-card {
            display:inline-flex; align-items:center; gap:8px;
            padding:8px 10px; border:1px solid var(--border); border-radius:10px;
            background:#fff; box-shadow:0 1px 2px rgb(0 0 0 / 0.04);
        }
        .crm-sync-card__body { display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .crm-sync-card__name { font-size:14px; font-weight:600; color:var(--text); }
        .desk-cities-picker { padding:12px 14px; margin-bottom:12px; border:1px solid #c4b5fd; background:#faf5ff; max-width:100%; }
        .desk-cities-picker__row {
            display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            max-width:100%;
        }
        .desk-cities-picker__label { flex:1; min-width:0; }
        .desk-cities-combo { position:relative; min-width:0; width:100%; max-width:24rem; }
        .desk-cities-combo__btn {
            width:100%; display:flex; align-items:center; justify-content:space-between; gap:8px;
            padding:0.55rem 0.85rem; border:1px solid var(--border); border-radius:8px;
            background:#fff; cursor:pointer; font:inherit; font-weight:600; color:var(--text);
        }
        .desk-cities-combo__btn:hover { border-color:var(--accent); }
        .desk-cities-combo__panel {
            position:absolute; z-index:40; top:calc(100% + 4px); left:0; right:0; min-width:0; width:100%;
            max-width:100%;
            background:#fff; border:1px solid var(--border); border-radius:10px;
            box-shadow:0 10px 28px rgb(0 0 0 / .14); padding:10px;
        }
        .desk-cities-combo__actions { display:flex; gap:6px; flex-wrap:wrap; margin:8px 0; }
        .desk-cities-combo__item {
            display:flex; align-items:center; gap:8px; padding:6px 4px; cursor:pointer; border-radius:6px;
        }
        .desk-cities-combo__item[hidden] { display:none !important; }
        .desk-cities-combo__item:hover { background:#f3f4f6; }
        .desk-cities-ms__search { width:100%; margin:0; }
        .desk-cities-ms__list { max-height:14rem; overflow:auto; }
        .desk-cities-ms__empty { padding:8px 4px; font-size:12px; }
        .desk-city-chip__sat {
            font-style:normal; font-size:10px; font-weight:500; color:var(--muted);
            padding:1px 5px; border-radius:999px; background:var(--bg-light); margin-left:auto;
        }
        .orders-list-top-bar {
            display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px;
            border-bottom:1px solid var(--border); padding:0.5rem 0.75rem; background:var(--bg-white);
            max-width:100%; min-width:0;
        }
        .desk-top-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .desk-mobile-filters { display:none; }
        .desk-mobile-list { display:none; }
        .desk-desktop-filters { display:none; }
        .orders-sticky-table thead tr.desk-filter-row th {
            position:static;
            background:#f3f4f6;
            text-transform:none;
            font-weight:400;
            vertical-align:middle;
            padding:0.4rem 0.35rem;
            border-bottom:1px solid var(--border);
            white-space:normal;
        }
        .orders-sticky-table thead tr.desk-filter-row .table-filter,
        .orders-sticky-table thead tr.desk-filter-row .form-input,
        .orders-sticky-table thead tr.desk-filter-row .multiselect {
            width:100%;
            min-width:0;
            max-width:100%;
            background:#fff;
        }
        .orders-sticky-table thead tr.desk-filter-row .btn {
            width:100%;
            white-space:nowrap;
        }
        .orders-table-scroll {
            max-height:calc(100vh - 260px);
            max-width:100%;
            width:100%;
            min-width:0;
            overflow:auto;
            -webkit-overflow-scrolling:touch;
            overscroll-behavior-x:contain;
        }
        table.orders-sticky-table {
            width:100%;
            border-collapse:separate;
            border-spacing:0;
            font-size:0.875rem;
            min-width:1100px;
        }
        .orders-sticky-table th, .orders-sticky-table td {
            padding:0.75rem; border-bottom:1px solid var(--border); text-align:left; vertical-align:middle; white-space:nowrap;
        }
        .orders-sticky-table thead th {
            position:static;
            background:#fff;
            font-weight:600; font-size:0.75rem;
            text-transform:uppercase; color:var(--muted);
            border-bottom:1px solid var(--border);
        }
        .orders-sticky-table thead a { color:inherit; text-decoration:none; }
        .orders-sticky-table thead a:hover { color:var(--accent); }
        .orders-sticky-table tbody tr.order-row { cursor:pointer; transition: background .15s ease; }
        .orders-sticky-table tbody tr.order-row:hover {
            background: color-mix(in srgb, var(--primary) 12%, var(--bg-light));
        }
        .orders-sticky-table td.wrap-cell { white-space:normal; max-width:16rem; }
        .orders-sticky-table td.desk-city-cell {
            white-space:normal; max-width:14rem; min-width:8rem; line-height:1.25; font-size:0.8125rem;
        }
        .orders-id-cell { font-variant-numeric:tabular-nums; font-weight:600; }
        .orders-noncore-cell { text-align:center; font-weight:700; color:#b45309; }
        .orders-id-cell__actions { display:inline-flex; margin-right:4px; vertical-align:middle; }
        .orders-id-cell__copy {
            border:0; background:transparent; cursor:pointer; color:var(--muted); padding:0 2px; font-size:14px; line-height:1;
        }
        .orders-id-cell__copy:hover { color:var(--accent); }
        .orders-datetime-cell { font-variant-numeric:tabular-nums; font-size:0.8125rem; }
        .status-cell, .order-type-cell { text-align:center; font-weight:500; font-size:0.75rem; }
        .fraud-cell { text-align:center; font-weight:700; font-size:0.75rem; text-transform:lowercase; }
        .fraud-ok { background:#dcfce7; color:#166534; }
        .fraud-not_ok { background:#fee2e2; color:#991b1b; }
        .fraud-pending { background:#fef9c3; color:#854d0e; }
        .fraud-none { color:var(--muted); font-weight:500; }
        .fraud-badge {
            display:inline-block; padding:4px 10px; border-radius:6px; font-weight:700; font-size:0.8125rem;
            text-transform:lowercase;
        }
        .fraud-badge.fraud-ok { background:#dcfce7; color:#166534; }
        .fraud-badge.fraud-not_ok { background:#fee2e2; color:#991b1b; }
        .fraud-badge.fraud-pending { background:#fef9c3; color:#854d0e; }
        .fraud-badge.fraud-none { background:var(--bg-light); color:var(--muted); }
        .desk-fraud-alert {
            display:flex; flex-wrap:wrap; align-items:baseline; gap:8px 12px;
            margin:0 0 12px; padding:12px 14px;
            border:1px solid #fca5a5; border-radius:8px;
            background:#fef2f2; color:#7f1d1d; font-size:0.875rem;
        }
        .desk-fraud-alert strong { font-size:0.9375rem; }
        .desk-office-btn {
            background:#e5e7eb; border:1px solid var(--border); color:var(--text);
            font-size:12px; padding:4px 10px;
        }
        .desk-office-btn:hover { background:#d1d5db; }
        .status-pending { background:#54d350; color:#1a1a1a; }
        .status-callback { background:#d4b5f7; color:#1a1a1a; }
        .status-not_processed { background:#c8c8c8; color:#1a1a1a; }
        .status-rejected { background:#e83b3b; color:#1a1a1a; }
        .status-in_progress { background:#f79336; color:#1a1a1a; }
        .status-in_progress_sd { background:#d2742d; color:#1a1a1a; }
        .status-on_way { background:#46b458; color:#1a1a1a; }
        .status-completed { background:#44b9f3; color:#1a1a1a; }
        .status-cancelled_cc, .status-cancelled_city { background:#47a9a7; color:#1a1a1a; }
        .status-review { background:#fde68a; color:#1a1a1a; }
        .status-waiting_parts, .status-waiting_payment { background:#fcd34d; color:#1a1a1a; }
        .order-type-new, .order-type-first { background:#ffffff; color:#1a1a1a; }
        .order-type-repeat { background:#7a4a00; color:#ffffff; }
        .order-type-warranty { background:#ffaa00; color:#1a1a1a; }
        @keyframes blink-red { 0%,100%{background-color:#fecaca;} 50%{background-color:#dc2626;color:#fff;} }
        .blink-red { animation: blink-red 2s ease-in-out infinite; }
        .time-late { background-color:#dc2626 !important; color:#fff !important; font-weight:600; }
        .row-hl-red { background:#fee2e2; }
        .row-hl-green { background:#dcfce7; }
        .row-hl-yellow { background:#fef9c3; }
        .pagination-wrap { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:10px 12px; border-top:1px solid var(--border); flex-wrap:wrap; }
        .desk-pagination { display:inline-flex; align-items:center; gap:4px; flex-wrap:wrap; }
        .desk-page {
            display:inline-flex; align-items:center; justify-content:center;
            min-width:2rem; height:2rem; padding:0 8px;
            border:1px solid var(--border); border-radius:6px;
            background:#fff; color:var(--text); text-decoration:none;
            font-size:13px; line-height:1; cursor:pointer;
        }
        .desk-page:hover { background:#f3f4f6; }
        .desk-page--active { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; cursor:default; }
        .desk-page--disabled { opacity:.45; cursor:not-allowed; background:#f9fafb; }
        .settings-grid { display:grid; gap:12px; }
        .settings-card { padding:16px; }
        .settings-card h3 { margin:0 0 8px; font-size:1rem; }
        .settings-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; align-items:end; }
        .settings-row label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
        .settings-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
        .modal-bg { display:none !important; }
        .desk-tabs-bar {
            display:flex; gap:4px; align-items:flex-end; flex-wrap:wrap;
            border-bottom:1px solid var(--border); margin:0 0 12px; padding:0 0 0;
            background:transparent;
        }
        .desk-tab {
            display:inline-flex; align-items:center; gap:8px;
            padding:8px 12px; border:1px solid var(--border); border-bottom:0;
            border-radius:8px 8px 0 0; background:#eef2f7; color:var(--muted);
            cursor:pointer; font-size:13px; font-family:inherit; max-width:14rem;
        }
        .desk-tab.active { background:#fff; color:var(--text); font-weight:600; position:relative; z-index:1; margin-bottom:-1px; padding-bottom:9px; }
        .desk-tab__x { border:0; background:transparent; color:var(--muted); cursor:pointer; font-size:14px; line-height:1; padding:0; }
        .desk-tab__x:hover { color:var(--err); }
        .desk-tab-badge {
            display:inline-flex; align-items:center; justify-content:center;
            min-width:1.25rem; height:1.25rem; padding:0 6px; border-radius:999px;
            background:#dc2626; color:#fff; font-size:11px; font-weight:700;
        }
        .fraud-row-hl { background:#fef2f2; }
        .desk-order-shell { display:none; background:transparent; border:0; border-radius:0; max-width:100%; min-width:0; }
        .desk-order-shell.open { display:block; }
        .desk-order-h {
            display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;
            padding:12px 16px; margin-bottom:12px;
            background:#fff; border:1px solid var(--border); border-radius:10px;
            max-width:100%; min-width:0;
        }
        .desk-order-b { padding:0; max-width:100%; min-width:0; }
        /* ===== Карточка заказа как в CRM (orders/show) ===== */
        .order-show-page { margin-bottom:1.5rem; max-width:100%; min-width:0; }
        .order-show-layout {
            display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 437px); gap:1.5rem; align-items:start;
            max-width:100%; min-width:0;
        }
        .order-show-layout > * { min-width:0; max-width:100%; }
        .order-show-sidebar { position:sticky; top:calc(var(--navbar-height) + 12px); min-width:0; max-width:100%; }
        .order-show-grid-row-1 {
            display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:1rem; margin-bottom:1rem;
        }
        .order-show-grid-row-3 {
            display:grid; grid-template-columns:minmax(0, 1.5fr) repeat(3, minmax(0, 1fr)); gap:1rem; margin-bottom:1rem;
        }
        .order-show-page .form-input,
        .order-show-page .form-control,
        .order-show-page select.form-input,
        .order-show-page input[type="datetime-local"] {
            max-width:100%; min-width:0; width:100%;
        }
        .order-show-buttons { display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; }
        .order-show-subtotal {
            font-weight:600; font-size:1.1rem; padding:0.625rem 0; display:block; color:var(--text);
        }
        .order-show-documents {
            margin-top:1.5rem; padding-top:1.5rem; border-top:1px solid var(--border);
        }
        .order-show-docs-grid, .desk-docs-grid {
            display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1.5rem;
        }
        .order-documents-heading {
            margin:0 0 1rem; font-size:1rem; font-weight:600; color:var(--text);
        }
        .order-doc-panel {
            background:var(--bg-white); border:1px solid var(--border); border-radius:10px;
            padding:1rem; box-shadow:0 1px 2px rgb(0 0 0 / 0.04);
        }
        .order-doc-disabled-placeholder {
            padding:2rem; background:var(--bg-light); border-radius:8px; text-align:center;
            color:var(--muted); font-size:0.875rem; border:1px solid var(--border); margin-bottom:1rem;
        }
        .order-doc-note-warn { color:var(--err); font-size:0.75rem; margin-top:0.5rem; }
        .dropzone {
            border:2px dashed var(--border); border-radius:8px; background:var(--bg-light);
            transition:all .2s; margin-bottom:1rem; cursor:pointer;
        }
        .dropzone:hover, .dropzone.is-drag {
            border-color:var(--accent); background:color-mix(in srgb, var(--accent) 8%, var(--bg-light));
        }
        .dropzone-inner { text-align:center; padding:2rem; color:var(--muted); }
        .dropzone-inner-note { font-size:0.75rem; margin-top:0.5rem; }
        .dropzone-emoji { font-size:2rem; margin-bottom:0.5rem; }
        .order-calc-box {
            margin-bottom:0.75rem; padding:0.75rem;
            background:color-mix(in srgb, var(--ok) 12%, #fff);
            border-radius:8px; border:1px solid color-mix(in srgb, var(--ok) 28%, var(--border));
        }
        .order-calc-row { display:flex; justify-content:space-between; font-size:0.8125rem; }
        .order-calc-row + .order-calc-row { margin-top:0.25rem; }
        .order-calc-label { color:var(--muted); }
        .order-calc-value { font-weight:600; color:var(--ok); }
        .order-sidebar-heading { margin:0 0 0.75rem; font-size:0.9rem; font-weight:600; }
        .order-sidebar-person-name { font-weight:600; font-size:1rem; }
        .order-sidebar-muted { color:var(--muted); font-size:0.875rem; }
        .order-sidebar-address {
            margin-top:0.35rem; white-space:pre-wrap;
            overflow-wrap:anywhere; word-break:break-word;
            color:var(--text); font-weight:700; font-size:0.9375rem; line-height:1.35;
        }
        .desk-office-text.order-sidebar-address,
        .desk-office-text {
            color:var(--text); font-weight:700; font-size:0.9375rem; line-height:1.35;
        }
        .desk-office-locked {
            margin-top:0.5rem; font-size:0.8125rem; font-weight:600; color:var(--text); opacity:0.85;
        }
        .order-sidebar-section { margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid var(--border); }
        .order-sidebar-section-title { font-weight:600; margin-bottom:0.5rem; font-size:0.875rem; }
        .order-sidebar-city { font-size:0.875rem; }
        .order-client-history-card { margin-top:1rem; max-width:100%; min-width:0; padding:0 !important; overflow:hidden; }
        .order-client-history-header { padding:0.75rem 1rem; border-bottom:1px solid var(--border); }
        .order-client-history-header h3 { margin:0; font-size:1rem; font-weight:600; }
        .order-client-history-body { padding:0.75rem 1rem 1rem; max-width:100%; min-width:0; }
        .order-client-history-summary { margin:0 0 0.5rem; font-size:0.8125rem; color:var(--muted); }
        .order-client-history-desktop {
            overflow-x:auto; overflow-y:hidden; max-width:100%; min-width:0;
            -webkit-overflow-scrolling:touch; overscroll-behavior-x:contain;
        }
        .desk-hist-table { width:100%; border-collapse:collapse; font-size:12px; min-width:720px; }
        .desk-hist-table th, .desk-hist-table td {
            border-bottom:1px solid var(--border); padding:6px 8px; text-align:left; white-space:nowrap; vertical-align:top;
        }
        .desk-hist-table th { color:var(--muted); font-weight:600; background:#f9fafb; }
        .desk-hist-row--current { background:#eff6ff; }
        .desk-hist-row--current td:first-child { font-weight:700; }
        .desk-hist-open, .order-client-history-link { color:var(--accent); text-decoration:none; font-weight:600; }
        .desk-hist-open:hover { text-decoration:underline; }
        .desk-call-at.time-late, .desk-call-at.blink-red { border-radius:6px; }
        .order-show-page textarea.form-input { min-height:6rem; resize:vertical; }
        .order-show-page input.form-input[readonly],
        .order-show-page textarea.form-input[readonly] { background:var(--bg-light); cursor:default; }
        .desk-files {
            display:grid; grid-template-columns:repeat(auto-fill, minmax(7.5rem, 1fr));
            gap:0.65rem; min-height:2rem;
        }
        .desk-file {
            display:flex; flex-direction:column; min-width:0; overflow:hidden;
            background:#fff; border:1px solid var(--border); border-radius:8px;
        }
        .desk-file:hover { border-color:var(--accent); }
        .desk-photo-wrap { position:relative; width:100%; }
        .desk-photo-thumb {
            display:block; width:100%; aspect-ratio:3/4; min-height:7rem;
            overflow:hidden; background:#eef2f7; cursor:zoom-in; line-height:0;
            border-bottom:1px solid var(--border);
        }
        .desk-photo-thumb img { display:block; width:100%; height:100%; object-fit:cover; }
        .desk-file-meta { padding:0.35rem 0.45rem; min-width:0; }
        .desk-file-name {
            display:block; font-size:0.7rem; color:var(--muted); overflow:hidden;
            text-overflow:ellipsis; white-space:nowrap;
        }
        .desk-file-del {
            position:absolute; top:4px; right:4px; border:0; background:rgba(0,0,0,.55); color:#fff;
            width:1.5rem; height:1.5rem; border-radius:999px; cursor:pointer; font-size:12px; line-height:1;
        }
        .desk-files-empty { text-align:center; padding:1rem; color:var(--muted); font-size:0.875rem; }
        .desk-calc-box { max-width:none; border:1px solid var(--border); border-radius:10px; padding:14px; background:#f8fafc; }
        .desk-calc-box h3 { margin:0 0 10px; font-size:15px; }
        .desk-calc-row { display:flex; justify-content:space-between; gap:12px; padding:6px 0; font-size:14px; border-bottom:1px solid var(--border); }
        .desk-calc-row:last-of-type { border-bottom:0; }
        @media (max-width: 960px) {
            .order-show-layout { grid-template-columns:minmax(0, 1fr); }
            .order-show-sidebar { position:static; }
            .order-show-grid-row-1, .order-show-grid-row-3, .order-show-docs-grid, .desk-docs-grid { grid-template-columns:minmax(0, 1fr); }
            .form-row { flex-direction:column; }
        }
        /* legacy stubs kept for refreshDocsOnly */
        .desk-file-del {
            position:absolute; top:0.3rem; right:0.3rem; z-index:1;
            border:0; border-radius:4px; background:rgba(255,255,255,.92);
            box-shadow:0 1px 4px rgb(0 0 0 / .12); cursor:pointer; color:var(--err);
            font-size:0.75rem; line-height:1; padding:0.15rem 0.4rem;
        }
        .desk-doc-note { font-size:11px; color:#b45309; margin-top:6px; }
        .desk-files-empty { grid-column:1/-1; text-align:center; color:var(--muted); font-size:0.75rem; padding:0.5rem; }
        .desk-photo-lightbox {
            position:fixed; inset:0; z-index:1100; display:none;
        }
        .desk-photo-lightbox.open { display:block; }
        .desk-photo-lightbox-backdrop {
            position:absolute; inset:0; background:rgba(15,23,42,.72);
        }
        .desk-photo-lightbox-stage {
            position:absolute; inset:3rem 3.5rem 4.5rem;
            display:flex; align-items:center; justify-content:center; pointer-events:none;
        }
        .desk-photo-lightbox-stage img {
            max-width:100%; max-height:100%; object-fit:contain;
            pointer-events:auto; border-radius:6px; box-shadow:0 12px 40px rgb(0 0 0 / .35);
            background:#111;
        }
        .desk-photo-lightbox-close, .desk-photo-lightbox-nav {
            position:absolute; z-index:2; border:0; background:rgba(255,255,255,.92);
            border-radius:999px; width:2.5rem; height:2.5rem; cursor:pointer;
            font-size:1.25rem; line-height:1; color:#111;
        }
        .desk-photo-lightbox-close { top:1rem; right:1rem; }
        .desk-photo-lightbox-prev { left:1rem; top:50%; transform:translateY(-50%); }
        .desk-photo-lightbox-next { right:1rem; top:50%; transform:translateY(-50%); }
        .desk-photo-lightbox-caption {
            position:absolute; left:0; right:0; bottom:1rem; text-align:center;
            color:#fff; font-size:0.875rem; text-shadow:0 1px 3px rgb(0 0 0 / .5);
        }
        body.desk-lightbox-open { overflow:hidden; }
        @media (max-width:960px){
            .desk-order-layout{ grid-template-columns:1fr; }
            .desk-order-sidebar{ position:static; }
            .desk-docs-grid{ grid-template-columns:1fr; }
            .desk-kv-grid{ grid-template-columns:1fr; }
            .desk-photo-lightbox-stage{ inset:4rem 1rem 5rem; }
        }
        @media (max-width:800px){
            .navbar{ padding:0 .75rem; } .wrap{ padding:.75rem; }
        }
        /* Телефоны + узкие планшеты: та же таблица, скролл внутри; без карточек */
        @media (max-width:1023px){
            .mobile-toggle { display:inline-flex; align-items:center; justify-content:center; }
            .navbar { padding:0 .5rem; gap:.25rem; }
            .navbar-brand { margin-right:auto; font-size:1.05rem; max-width:40vw; overflow:hidden; text-overflow:ellipsis; }
            .navbar-brand .brand-full { display:none; }
            .navbar-menu {
                position:fixed;
                top:var(--navbar-height);
                left:0;
                width:min(100%, 20rem);
                bottom:0;
                z-index:96;
                display:none;
                flex-direction:column;
                align-items:stretch;
                gap:0.25rem;
                margin:0;
                padding:0.75rem 1rem 1.25rem;
                background:var(--sidebar);
                overflow-y:auto;
                overflow-x:hidden;
                flex:none;
            }
            .navbar-menu.open { display:flex; }
            .navbar-menu li { width:100%; list-style:none; }
            .navbar-menu .navbar-link { width:100%; padding:.7rem .85rem; }
            .navbar-right {
                max-width:45vw;
                gap:.25rem;
                font-size:.7rem;
            }
            .navbar-right > span { max-width:5.5rem; }
            .navbar-right a[href*="hub"],
            .navbar-right form { display:none; }
            .wrap { padding:.5rem .5rem 1.25rem; overflow-x:hidden; }
            .orders-top { flex-direction:column; align-items:stretch; gap:8px; }
            .orders-top > div:last-child {
                display:grid !important;
                grid-template-columns:1fr;
                gap:8px;
                width:100%;
            }
            .crm-sync-card {
                display:flex;
                width:100%;
                max-width:100%;
                justify-content:space-between;
            }
            .crm-sync-card__body { flex:1; justify-content:space-between; min-width:0; }
            .crm-sync-card__name { overflow:hidden; text-overflow:ellipsis; }
            .crm-sync-card .btn { flex-shrink:0; }
            .orders-meta { gap:4px; }
            .orders-meta .pill { font-size:11px; padding:3px 7px; }
            .desk-cities-picker { padding:10px; }
            .desk-cities-picker__row { flex-direction:column; align-items:stretch; }
            .desk-cities-picker__label { min-width:0; width:100%; }
            .desk-cities-combo { min-width:0; width:100%; max-width:none; }
            .desk-cities-combo__panel { min-width:0; left:0; right:0; width:auto; }
            .orders-list-top-bar {
                flex-direction:column;
                align-items:stretch;
                padding:0.5rem;
            }
            .orders-list-top-bar > * { width:100%; min-width:0; }
            .desk-top-actions { width:100%; display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px; }
            .desk-top-actions .btn { width:100%; justify-content:center; }
            .desk-date-filters { width:100%; display:grid; grid-template-columns:1fr 1fr; gap:6px; }
            .desk-date-filters .table-filter { min-width:0; width:100%; flex:none; }
            .desk-date-filters .btn { grid-column:1 / -1; width:100%; }
            .desk-desktop-filters { display:none !important; }
            /* Та же таблица, что на ПК — скролл только внутри */
            .desk-desktop-table,
            .orders-table-scroll.desk-desktop-table {
                display:block !important;
            }
            .orders-table-scroll {
                display:block;
                width:100%;
                max-width:100%;
                overflow-x:auto;
                overflow-y:auto;
                -webkit-overflow-scrolling:touch;
                overscroll-behavior-x:contain;
                max-height:min(70vh, calc(100vh - 14rem));
                border-top:1px solid var(--border);
            }
            table.orders-sticky-table {
                min-width:1100px !important;
                width:max-content;
            }
            .desk-mobile-filters,
            .desk-mobile-list {
                display:none !important;
            }
            .pagination-wrap {
                flex-direction:column;
                align-items:stretch;
                padding:0.75rem;
            }
            .desk-pagination { justify-content:center; }
            .form-input, .table-filter, .form-control, select.form-input {
                font-size:16px !important;
                max-width:100%;
            }
            .order-show-layout { grid-template-columns:minmax(0, 1fr) !important; }
            .order-show-sidebar { position:static !important; }
            .order-show-grid-row-1,
            .order-show-grid-row-3,
            .order-show-docs-grid,
            .desk-docs-grid { grid-template-columns:minmax(0, 1fr) !important; }
            .order-show-page,
            .order-show-layout,
            .desk-order-shell,
            .desk-order-b,
            .order-client-history-card,
            .order-client-history-body,
            .order-client-history-desktop { max-width:100%; min-width:0; }
            .order-show-page .form-row { flex-direction:column; }
            .order-show-buttons { grid-template-columns:1fr; }
            #deskFiltersForm.card { overflow:hidden; max-width:100%; }
            .desk-pane-fraud .card { max-width:100%; overflow-x:auto; }
            .desk-pane-fraud table.orders-sticky-table { min-width:640px !important; }
        }
        .desk-date-filters { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
        .desk-date-filters .table-filter { width:auto; min-width:8.5rem; }
        .multiselect { position:relative; min-width:7rem; font-size:0.75rem; }
        .multiselect-selected {
            display:flex; align-items:center; gap:6px; cursor:pointer;
            padding:0.375rem 0.625rem; border:1px solid var(--border); border-radius:9999px; background:#fff;
        }
        .multiselect-text { color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .multiselect-dropdown {
            display:none; position:absolute; z-index:20; top:calc(100% + 4px); left:0; min-width:12rem;
            background:#fff; border:1px solid var(--border); border-radius:8px; box-shadow:0 8px 24px rgb(0 0 0 / .12);
            max-height:16rem; overflow:auto; padding:6px;
        }
        .multiselect.open .multiselect-dropdown { display:block; }
        .multiselect-dropdown label { display:flex; align-items:center; gap:6px; padding:4px 6px; cursor:pointer; border-radius:4px; }
        .multiselect-dropdown label:hover { background:#f3f4f6; }
        .multiselect-actions { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:6px; padding-bottom:6px; border-bottom:1px solid var(--border); }
        .multiselect-actions button {
            border:0; background:transparent; color:var(--accent); cursor:pointer; font-size:11px; padding:0;
        }
        .ml-auto { margin-left:auto; }
        pre { white-space:pre-wrap; background:#f9fafb; padding:10px; border-radius:8px; border:1px solid var(--border); }
        .desk-notify-bar {
            position: sticky;
            top: var(--navbar-height);
            z-index: 99;
            border-bottom:2px solid #f59e0b;
            background:#fffbeb;
            padding:8px 12px;
            text-align:center;
            font-size:12px;
            color:var(--text);
        }
        .desk-notify-bar.hidden { display:none !important; }
        .desk-notify-bar button {
            margin-left:8px;
            border:0;
            border-radius:6px;
            background:var(--accent);
            color:#fff;
            padding:4px 10px;
            font:inherit;
            cursor:pointer;
        }
        .desk-notify-toast-host {
            position:fixed;
            right:12px;
            bottom:12px;
            z-index:1200;
            display:flex;
            flex-direction:column;
            gap:8px;
            max-width:min(360px, calc(100vw - 24px));
            pointer-events:none;
        }
        .desk-notify-toast {
            pointer-events:auto;
            background:#111827;
            color:#f9fafb;
            border-radius:10px;
            padding:12px 14px;
            box-shadow:0 10px 30px rgba(0,0,0,.2);
            cursor:pointer;
            transition:opacity .35s ease;
        }
        .desk-notify-toast__title { display:block; font-size:13px; margin-bottom:4px; }
        .desk-notify-toast__body { font-size:12px; opacity:.9; }
        .desk-notify-toast--fade { opacity:0; }
    </style>
    @stack('styles')
</head>
@php
    $user = $user ?? request()->session()->get('desk_user', []);
    $canSettings = \App\Support\DeskAccess::canManageCrm2Settings($user);
    $routeName = request()->route()?->getName();
@endphp
<body
    @if(!empty($user))
        data-desk-notify-poll="1"
        data-desk-notify-url="/desk/notifications/new-orders"
        data-desk-notify-sound="/sounds/order-notify.mp3"
        data-desk-orders-url="{{ url('/desk') }}"
    @endif
>
<nav class="navbar" id="deskNavbar">
    <button type="button" class="mobile-toggle" id="deskMobileToggle" aria-label="Меню" aria-expanded="false">☰</button>
    <a class="navbar-brand" href="{{ route('desk.index') }}">
        <span class="brand-short">LC</span>
        <span class="brand-full">Единое окно</span>
    </a>
    <ul class="navbar-menu" id="deskNavbarMenu">
        <li><a class="navbar-link {{ $routeName === 'desk.index' ? 'active' : '' }}" href="{{ route('desk.index') }}">Заказы</a></li>
        @if($canSettings)
            <li><a class="navbar-link {{ str_starts_with((string)$routeName, 'desk.settings') ? 'active' : '' }}" href="{{ route('desk.settings') }}">Настройки</a></li>
        @endif
        @if(in_array('developer', $user['roles'] ?? [], true))
            <li><a class="navbar-link {{ $routeName === 'desk.logs' ? 'active' : '' }}" href="{{ route('desk.logs') }}">Логи</a></li>
        @endif
    </ul>
    <div class="navbar-right">
        <span>{{ $user['name'] ?? '' }}</span>
        <a href="{{ route('hub.home') }}">Хаб</a>
        <form method="POST" action="{{ route('hub.logout') }}" style="display:inline;">@csrf
            <button type="submit" class="linkish">Выход</button>
        </form>
    </div>
</nav>
@if(!empty($user))
<div id="deskNotifyToastHost" class="desk-notify-toast-host" aria-live="polite" aria-atomic="true"></div>
@endif
<div class="navbar-backdrop" id="deskNavbarBackdrop" hidden></div>
<div class="wrap">
    @if(session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash flash-err">{{ session('error') }}</div>@endif
    @if(session('desk_sync_notice'))<div class="flash flash-ok">{{ session('desk_sync_notice') }}</div>@endif
    @yield('content')
</div>
@stack('scripts')
@if(!empty($user))
<script src="/js/lc-notify-sound.js?v={{ @filemtime(public_path('js/lc-notify-sound.js')) ?: time() }}"></script>
<script src="/js/desk-order-notify.js?v={{ @filemtime(public_path('js/desk-order-notify.js')) ?: time() }}"></script>
@endif
<script>
(function () {
    var toggle = document.getElementById('deskMobileToggle');
    var menu = document.getElementById('deskNavbarMenu');
    var backdrop = document.getElementById('deskNavbarBackdrop');
    if (toggle && menu && backdrop) {
        function closeMenu() {
            menu.classList.remove('open');
            backdrop.classList.remove('open');
            backdrop.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
        function openMenu() {
            menu.classList.add('open');
            backdrop.classList.add('open');
            backdrop.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        toggle.addEventListener('click', function () {
            if (menu.classList.contains('open')) closeMenu();
            else openMenu();
        });
        backdrop.addEventListener('click', closeMenu);
        menu.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', closeMenu);
        });
    }

    function syncDeskFilterScopes() {
        // На всех ширинах используем desktop-фильтры; мобильные карточки/фильтры выключены
        document.querySelectorAll('[data-desk-filter-scope="mobile"]').forEach(function (scope) {
            scope.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = true;
            });
        });
        document.querySelectorAll('[data-desk-filter-scope="desktop"]').forEach(function (scope) {
            scope.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = false;
            });
        });
    }

    function syncDeskLayout() {
        syncDeskFilterScopes();
    }

    syncDeskLayout();
    window.addEventListener('resize', syncDeskLayout);
})();
</script>
</body>
</html>
