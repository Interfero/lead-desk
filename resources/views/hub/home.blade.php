<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Выбор проекта — Lead Hub</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}?v={{ @filemtime(public_path('favicon.png')) ?: time() }}" sizes="128x128">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v={{ @filemtime(public_path('favicon.ico')) ?: time() }}" sizes="32x32">
    <style>
        :root { --bg:#0f172a; --card:#1e293b; --text:#f8fafc; --muted:#94a3b8; --accent:#7c3aed; --line:#334155; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; font-family: system-ui, Segoe UI, sans-serif;
            background: radial-gradient(1000px 500px at 80% 0%, #4c1d95, var(--bg)); color:var(--text); }
        .wrap { max-width:980px; margin:0 auto; padding:40px 20px; }
        .top { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:28px; }
        h1 { margin:0; font-size:28px; }
        .muted { color:var(--muted); }
        .grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:20px; }
        @media (max-width:640px) { .grid { grid-template-columns:1fr; } }
        .card {
            background:var(--card); border:1px solid var(--line); border-radius:16px;
            padding:48px 32px; min-height:140px;
            text-decoration:none; color:inherit; cursor:pointer;
            transition: transform .15s ease, border-color .15s ease;
            display:flex; align-items:center; justify-content:center;
            width:100%; text-align:center; font: inherit;
        }
        .card:hover { transform: translateY(-2px); border-color:var(--accent); }
        .card h2 { margin:0; font-size:24px; font-weight:700; line-height:1.25; }
        form.card-form { margin:0; display:contents; }
        .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:transparent; border:1px solid var(--line); color:var(--text); text-decoration:none; font-size:14px; cursor:pointer; }
        .flash { background:#7f1d1d; padding:10px 12px; border-radius:8px; margin-bottom:16px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Проекты</h1>
            <div class="muted">{{ $user['name'] ?? '' }} · {{ $user['email'] ?? '' }}</div>
        </div>
        <form method="POST" action="{{ route('hub.logout') }}">@csrf
            <button class="btn" type="submit">Выйти</button>
        </form>
    </div>

    @if(session('error'))
        <div class="flash">{{ session('error') }}</div>
    @endif

    <div class="grid">
        @forelse($projects as $p)
            @if(($p['code'] ?? '') === 'crm')
                <form method="POST" action="{{ route('hub.open.crm') }}" class="card-form">
                    @csrf
                    <button type="submit" class="card">
                        <h2>{{ $p['name'] }}</h2>
                    </button>
                </form>
            @else
                <a class="card" href="{{ $p['path'] ?? '/desk' }}">
                    <h2>{{ $p['name'] }}</h2>
                </a>
            @endif
        @empty
            <div class="card"><h2>Нет доступных проектов</h2></div>
        @endforelse
    </div>
</div>
</body>
</html>
