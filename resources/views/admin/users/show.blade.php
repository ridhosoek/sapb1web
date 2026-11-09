@extends('layouts.app')

@section('title', 'Detail User')
@section('page-title', 'Detail User')
@section('page-subtitle', 'Informasi akun, role, dan konteks operasional user.')

@section('page-actions')
    <div class="d-flex gap-2">
        @if(in_array($registeredUser->role, auth()->user()->role->assignableRoles(), true))
            <a class="btn btn-sm btn-primary" href="{{ route('admin.users.edit', $registeredUser) }}"><i class="fa-solid fa-pen-to-square me-1"></i> Edit User</a>
        @endif
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
    </div>
@endsection

@section('content')
    @if(session('status'))<div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>@endif
    <section class="transaction-section">
        <header class="section-heading"><h2>Informasi User</h2><span class="badge {{ $registeredUser->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $registeredUser->is_active ? 'Aktif' : 'Nonaktif' }}</span></header>
        <div class="transaction-body">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nama</label><div class="readonly-value">{{ $registeredUser->name }}</div></div>
                <div class="col-md-6"><label class="form-label">Email</label><div class="readonly-value">{{ $registeredUser->email }}</div></div>
                <div class="col-md-6"><label class="form-label">Jabatan Asli</label><div class="readonly-value">{{ $registeredUser->job_title ?: '—' }}</div></div>
                <div class="col-md-6"><label class="form-label">Role Akses</label><div class="readonly-value">{{ $registeredUser->role->label() }}</div></div>
                <div class="col-md-4"><label class="form-label">Terdaftar</label><div class="readonly-value">{{ $registeredUser->created_at->format('d/m/Y H:i') }}</div></div>
                <div class="col-md-4"><label class="form-label">Diperbarui</label><div class="readonly-value">{{ $registeredUser->updated_at->format('d/m/Y H:i') }}</div></div>
            </div>
        </div>
    </section>
    <section class="transaction-section mt-3">
        <header class="section-heading"><h2>Pemetaan Operasional per Database SAP</h2></header>
        <div class="transaction-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Database SAP</th><th>Pool</th><th>Department</th><th>Warehouse</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($companies as $company)
                        @php($companyProfile = $registeredUser->operationalProfiles->firstWhere('company_db', $company))
                        <tr>
                            <td class="mono">{{ $company }}@if($company === session('sap_company_db')) <span class="badge text-bg-light">sedang login</span>@endif</td>
                            <td class="mono">{{ $companyProfile?->pool?->code ?? '—' }}</td>
                            <td class="mono">{{ $companyProfile?->department?->code ?? '—' }}</td>
                            <td class="mono">{{ $companyProfile?->warehouse_code ?? '—' }}</td>
                            <td>
                                @if($companyProfile)
                                    <span class="badge text-bg-success">Dipetakan</span>
                                @else
                                    <span class="badge text-bg-warning">Belum dipetakan</span>
                                @endif
                                <a class="ms-2 small" href="{{ route('admin.users.edit', ['user' => $registeredUser, 'company_db' => $company]) }}">Atur</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($legacyProfile)
                <div class="small text-secondary mt-2">Profil lama yang belum dipetakan ke database mana pun (hanya referensi, tidak dipakai transaksi): Pool {{ $legacyProfile->pool?->code ?? '—' }}, Department {{ $legacyProfile->department?->code ?? '—' }}, Warehouse {{ $legacyProfile->warehouse_code ?? '—' }}.</div>
            @endif
        </div>
    </section>
@endsection
