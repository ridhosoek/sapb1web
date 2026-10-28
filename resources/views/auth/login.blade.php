<!doctype html>
<html lang="id" data-bs-theme="light" style="color-scheme: light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login · SAP B1 Operational Portal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
<main class="container login-shell d-flex align-items-center justify-content-center py-4">
    <section class="login-card" aria-labelledby="login-title">
        <span class="login-badge mb-4">SAP B1 · OPS</span>
        <h1 id="login-title">Masuk ke Operational Portal</h1>
        <p class="text-secondary mb-4">Gunakan akun operasional dan pilih database SAP Business One yang akan digunakan.</p>

        <form method="POST" action="{{ route('login.store') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" autocomplete="current-password" required>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label" for="sap-company-db">Database SAP *</label>
                <select class="form-select mono-field @error('sap_company_db') is-invalid @enderror" id="sap-company-db" name="sap_company_db" required>
                    <option value="">Pilih database perusahaan</option>
                    @foreach($sapCompanies as $companyDb)
                        <option value="{{ $companyDb }}" @selected(old('sap_company_db', config('sap.company_db')) === $companyDb)>{{ $companyDb }}</option>
                    @endforeach
                </select>
                @error('sap_company_db')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($sapCompanies === [])
                    <div class="form-text text-danger">Belum ada database SAP yang dikonfigurasi.</div>
                @endif
            </div>
            <div class="form-check mb-4">
                <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                <label class="form-check-label" for="remember">Ingat sesi saya</label>
            </div>
            <button class="btn btn-primary w-100" type="submit" @disabled($sapCompanies === [])>Masuk</button>
        </form>
    </section>
</main>
</body>
</html>
