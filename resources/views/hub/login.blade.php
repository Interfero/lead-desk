<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Вход — Lead Hub</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}?v={{ @filemtime(public_path('favicon.png')) ?: time() }}" sizes="128x128">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v={{ @filemtime(public_path('favicon.ico')) ?: time() }}" sizes="32x32">
    <style>
        :root { --bg:#0f172a; --card:#1e293b; --text:#f8fafc; --muted:#94a3b8; --accent:#7c3aed; --err:#f87171; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family: system-ui, Segoe UI, sans-serif; background: radial-gradient(1200px 600px at 20% 0%, #312e81, var(--bg)); color:var(--text); }
        .card { width:min(420px,92vw); background:var(--card); border-radius:16px; padding:28px; box-shadow:0 20px 50px rgba(0,0,0,.35); }
        h1 { margin:0 0 6px; font-size:24px; }
        p { margin:0 0 20px; color:var(--muted); font-size:14px; }
        label { display:block; font-size:12px; color:var(--muted); margin:0 0 6px; }
        input { width:100%; padding:12px; border-radius:10px; border:1px solid #334155; background:#0f172a; color:var(--text); margin-bottom:14px; }
        button { width:100%; padding:12px; border:0; border-radius:10px; background:var(--accent); color:#fff; font-weight:600; cursor:pointer; }
        .err { color:var(--err); font-size:13px; margin-bottom:12px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Lead Hub</h1>
    <p>Единый вход. Дальше выберите проект.</p>
    @if($errors->any())
        <div class="err">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('hub.login.submit') }}">
        @csrf
        <label>Email</label>
        <input type="email" name="email" value="{{ old('email') }}" required autofocus>
        <label>Пароль</label>
        <input type="password" name="password" required>
        <button type="submit">Войти</button>
    </form>
</div>
</body>
</html>
