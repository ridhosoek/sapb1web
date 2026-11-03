@extends('layouts.app')

@section('title', 'Ganti Password')
@section('page-title', 'Ganti Password')
@section('page-subtitle', 'Perbarui password akun Anda tanpa bantuan administrator.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('dashboard') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Dashboard</a>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-7">
            <section class="transaction-section">
                <header class="section-heading">
                    <div>
                        <h2>Keamanan Akun</h2>
                        <span class="small text-secondary">Password saat ini diperlukan untuk mengonfirmasi identitas Anda.</span>
                    </div>
                    <i class="fa-solid fa-shield-halved text-secondary" aria-hidden="true"></i>
                </header>
                <div class="transaction-body">
                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label" for="current-password">Password Saat Ini *</label>
                            <input class="form-control @error('current_password') is-invalid @enderror" id="current-password" name="current_password" type="password" autocomplete="current-password" required autofocus>
                            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="new-password">Password Baru *</label>
                                <input class="form-control @error('password') is-invalid @enderror" id="new-password" name="password" type="password" autocomplete="new-password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="new-password-confirmation">Konfirmasi Password Baru *</label>
                                <input class="form-control" id="new-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                            </div>
                        </div>

                        <div class="alert alert-info py-2 mt-3 mb-0">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            Minimal 10 karakter, menggunakan huruf besar, huruf kecil, dan angka. Password baru harus berbeda dari password saat ini.
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan Password Baru</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
@endsection
