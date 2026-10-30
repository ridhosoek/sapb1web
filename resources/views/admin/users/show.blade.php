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
                <div class="col-md-3"><label class="form-label">Role</label><div class="readonly-value">{{ $registeredUser->role->label() }}</div></div>
                <div class="col-md-3"><label class="form-label">Warehouse</label><div class="readonly-value mono-field">{{ $registeredUser->operationalProfile?->warehouse_code ?? '—' }}</div></div>
                <div class="col-md-3"><label class="form-label">Pool</label><div class="readonly-value mono-field">{{ $registeredUser->operationalProfile?->pool?->code ?? '—' }}</div></div>
                <div class="col-md-3"><label class="form-label">Department</label><div class="readonly-value mono-field">{{ $registeredUser->operationalProfile?->department?->code ?? '—' }}</div></div>
                <div class="col-md-4"><label class="form-label">Terdaftar</label><div class="readonly-value">{{ $registeredUser->created_at->format('d/m/Y H:i') }}</div></div>
                <div class="col-md-4"><label class="form-label">Diperbarui</label><div class="readonly-value">{{ $registeredUser->updated_at->format('d/m/Y H:i') }}</div></div>
            </div>
        </div>
    </section>
@endsection
