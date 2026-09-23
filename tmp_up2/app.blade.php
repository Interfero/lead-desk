<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="desk-ui" content="2026-08-12-hist-open">
    <title>@yield('title', 'Единое окно') — Desk</title>
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bg:#f8fafc; --card:#fff; --border:#e5e7eb; --text:#030711; --muted:#6b7280;
            --accent:#6b26d9; --primary:#6b26d9; --ok:#059669; --err:#dc2626;
            --bg-light:#f3f4f6; --bg-white:#fff;
            --sidebar:#1f2937; --sidebar-fg:#f8fafc; --sidebar-accent:#111827;
            --navbar-height:60px;
            --font-sans:"Plus Jakarta Sans", system-ui, sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin:0; font-family: var(--font-sans); background:var(--bg); color:var(--text); letter-spacing:-0.02em; }
        .navbar {
            background:var(--sidebar); color:var(--sidebar-fg);
            height:var(--navbar-height); padding:0 1.5rem;
            display:flex; align-items:center; gap:0.5rem;
            position:sticky; top:0; z-index:100;
        }
        .navbar-brand { color:var(--sidebar-fg); font-size:1.25rem; font-weight:600; text-decoration:none; margin-right:1.5rem; white-space:nowrap; }
        .navbar-brand .brand-short { opacity:.85; margin-right:.35rem; }
        .navbar-menu { display:flex; align-items:center; list-style:none; margin:0; padding:0; flex:1; gap:0; }
        .navbar-link {
            display:inline-flex; align-items:center; gap:.4rem;
            padding:.75rem 1rem; color:color-mix(in srgb, var(--sidebar-fg) 65%, transparent);
            text-decoration:none; font-size:.875rem; white-space:nowrap; border-radius:.5rem;
        }
        .navbar-link:hover, .navbar-link.active { color:var(--sidebar-fg); background:var(--sidebar-accent); }
        .navbar-right { margin-left:auto; display:flex; align-items:center; gap:.75rem; color:color-mix(in srgb, var(--sidebar-fg) 70%, transparent); font-size:.875rem; }
        .navbar-right a, .navbar-right button.linkish {
            color:color-mix(in srgb, var(--sidebar-fg) 70%, transparent); text-decoration:none; background:none; border:0; cursor:pointer; font:inherit;
        }
        .navbar-right a:hover, .navbar-right button.linkish:hover { color:var(--sidebar-fg); }
        .wrap { max-width:none; width:100%; margin:0 auto; padding:0.75rem 1rem 1.5rem; box-sizing:border-box; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:10px; box-shadow:0 1px 2px rgb(0 0 0 / 0.04); }
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
        .table-filter { min-width:0; font-size:0.75rem; padding:0.375rem 0.625rem; border-radius:9999px; }
        .orders-top { display:flex; flex-wrap:wrap; justify-content:space-between; gap:12px; margin-bottom:12px; align-items:flex-start; }
        .orders-meta { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
        .orders-list-top-bar {
            display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px;
            border-bottom:1px solid var(--border); padding:0.5rem 0.75rem; background:var(--bg-white);
        }
        .orders-table-scroll { max-height:calc(100vh - 220px); overflow:auto; }
        table.orders-sticky-table { width:100%; border-collapse:collapse; font-size:0.875rem; min-width:1100px; }
        .orders-sticky-table th, .orders-sticky-table td {
            padding:0.75rem; border-bottom:1px solid var(--border); text-align:left; vertical-align:middle; white-space:nowrap;
        }
        .orders-sticky-table thead tr:first-child th {
            position:sticky; top:0; z-index:3; background:var(--card); font-weight:600; font-size:0.75rem;
            text-transform:uppercase; color:var(--muted); box-shadow:0 1px 0 var(--border);
        }
        .orders-sticky-table thead tr:nth-child(2) td {
            position:sticky; top:2.75rem; z-index:2; background:var(--bg-light); padding:0.5rem 0.75rem; border-bottom:1px solid var(--border);
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
        .desk-order-shell { display:none; background:transparent; border:0; border-radius:0; }
        .desk-order-shell.open { display:block; }
        .desk-order-h {
            display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;
            padding:12px 16px; margin-bottom:12px;
            background:#fff; border:1px solid var(--border); border-radius:10px;
        }
        .desk-order-b { padding:0; }
        .desk-order-page { display:flex; flex-direction:column; gap:1rem; }
        .desk-order-layout {
            display:grid; grid-template-columns:1fr minmax(280px, 340px); gap:1rem; align-items:start;
        }
        .desk-order-main, .desk-order-sidebar, .desk-order-documents, .desk-order-master, .desk-order-history {
            background:#fff; border:1px solid var(--border); border-radius:10px; padding:1rem 1.1rem;
        }
        .desk-order-history { margin-top:0; }
        .desk-hist-scroll { overflow:auto; max-width:100%; }
        .desk-hist-table { width:100%; border-collapse:collapse; font-size:12px; min-width:720px; }
        .desk-hist-table th, .desk-hist-table td {
            border-bottom:1px solid var(--border); padding:6px 8px; text-align:left; white-space:nowrap; vertical-align:top;
        }
        .desk-hist-table th { color:var(--muted); font-weight:600; background:#f9fafb; position:sticky; top:0; }
        .desk-hist-row--current { background:#eff6ff; }
        .desk-hist-row--current td:first-child { font-weight:700; }
        .desk-hist-open { color:var(--accent); text-decoration:none; font-weight:600; }
        .desk-hist-open:hover { text-decoration:underline; }
        .desk-call-at { display:inline-block; padding:2px 6px; border-radius:4px; }
        .desk-order-sidebar { position:sticky; top:calc(var(--navbar-height) + 12px); }
        .desk-order-section-title {
            margin:0 0 0.85rem; font-size:0.95rem; font-weight:600;
        }
        .desk-kv-grid {
            display:grid; grid-template-columns:1fr 1fr; gap:0.75rem 1rem;
        }
        .desk-kv { min-width:0; }
        .desk-kv--full { grid-column:1 / -1; }
        .desk-form-label { display:block; font-size:12px; color:var(--muted); margin:0 0 4px; font-weight:500; }
        .desk-form-field { margin-bottom:0.75rem; }
        .desk-form-value { font-size:0.875rem; line-height:1.4; word-break:break-word; }
        .desk-comments-box {
            margin:0; white-space:pre-wrap; font:inherit; font-size:0.8125rem; line-height:1.45;
            max-height:14rem; overflow:auto; padding:0.65rem 0.75rem;
            background:#f8fafc; border:1px solid var(--border); border-radius:8px;
        }
        .desk-sidebar-id { font-size:1.05rem; font-weight:700; margin:0 0 0.25rem; }
        .desk-sidebar-meta { font-size:0.75rem; color:var(--muted); margin-bottom:0.85rem; }
        .desk-amounts-row {
            display:grid; grid-template-columns:1fr 1fr; gap:0.65rem;
            margin-bottom:0.75rem;
        }
        .desk-net-box {
            padding:0.65rem 0.75rem; border-radius:8px; background:#ecfdf5; border:1px solid #a7f3d0;
            margin-bottom:0.75rem;
        }
        .desk-net-box .desk-form-label { color:#047857; }
        .desk-net { font-size:1.2rem; font-weight:700; color:#047857; }
        .desk-status-pill {
            display:inline-block; padding:0.2rem 0.55rem; border-radius:6px;
            font-size:0.75rem; font-weight:600; margin-bottom:0.75rem;
        }
        .desk-calc-box {
            max-width:none; border:1px solid var(--border); border-radius:10px; padding:14px; background:#f8fafc;
        }
        .desk-calc-box h3 { margin:0 0 10px; font-size:15px; }
        .desk-calc-row { display:flex; justify-content:space-between; gap:12px; padding:6px 0; font-size:14px; border-bottom:1px solid var(--border); }
        .desk-calc-row:last-of-type { border-bottom:0; }
        .desk-docs-heading {
            display:flex; align-items:center; justify-content:space-between; gap:8px;
            margin:0 0 0.85rem; font-size:1rem; font-weight:600;
        }
        .desk-docs-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .desk-doc-panel {
            border:1px solid var(--border); border-radius:10px; padding:0.85rem;
            background:#fafafa; min-width:0;
        }
        .desk-doc-panel h4 { margin:0 0 0.6rem; font-size:0.875rem; font-weight:600; }
        .desk-dropzone {
            border:2px dashed #cbd5e1; border-radius:8px; padding:0.85rem; text-align:center; cursor:pointer;
            background:#fff; color:var(--muted); font-size:12px; margin-bottom:0.65rem;
            transition: border-color .15s, color .15s, background .15s;
        }
        .desk-dropzone:hover, .desk-dropzone.is-drag { border-color:var(--accent); color:var(--accent); background:#f5f3ff; }
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
    </style>
    @stack('styles')
</head>
<body>
@php
    $user = $user ?? request()->session()->get('desk_user', []);
    $canSettings = \App\Support\DeskAccess::canManageCrm2Settings($user);
    $routeName = request()->route()?->getName();
@endphp
<nav class="navbar">
    <a class="navbar-brand" href="{{ route('desk.index') }}">
        <span class="brand-short">LC</span>
        <span class="brand-full">Единое окно</span>
    </a>
    <ul class="navbar-menu">
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
<div class="wrap">
    @if(session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash flash-err">{{ session('error') }}</div>@endif
    @yield('content')
</div>
@stack('scripts')
</body>
</html>
