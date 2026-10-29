<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('code') · SAP B1 Operational Portal</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #102a43; background: #f3f6f9; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .card { width: min(620px, 100%); background: #fff; border: 1px solid #d9e2ec; border-radius: 16px; box-shadow: 0 18px 45px rgba(16, 42, 67, .10); overflow: hidden; }
        .top { height: 7px; background: #176b91; }
        .body { padding: 36px; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; font-weight: 700; }
        .mark { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 8px; background: #102f46; color: #fff; font-size: 19px; }
        .code { color: #176b91; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; font-size: 13px; }
        h1 { margin: 8px 0 12px; color: #102a43; font-size: clamp(26px, 5vw, 36px); line-height: 1.15; }
        p { margin: 0; color: #526d82; line-height: 1.65; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 28px; }
        a, button { appearance: none; border: 1px solid #176b91; border-radius: 8px; padding: 10px 16px; font: inherit; font-weight: 650; cursor: pointer; text-decoration: none; }
        .primary { background: #176b91; color: #fff; }
        .secondary { background: #fff; color: #176b91; }
        .meta { margin-top: 24px; padding-top: 18px; border-top: 1px solid #e7edf2; color: #829ab1; font-size: 12px; }
    </style>
</head>
<body>
<main class="card">
    <div class="top"></div>
    <div class="body">
        <div class="brand"><span class="mark">B1</span><span>SAP B1 Operational Portal</span></div>
        <div class="code">Error @yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <button class="primary" type="button" onclick="window.location.reload()">Refresh Halaman</button>
            <a class="secondary" href="{{ url('/dashboard') }}">Kembali ke Dashboard</a>
        </div>
        <div class="meta">Jika masalah berulang, catat waktu kejadian dan kode referensi <strong>{{ request()->attributes->get('request_id', 'tidak tersedia') }}</strong>, lalu hubungi administrator.</div>
    </div>
</main>
</body>
</html>
