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
            <dl class="detail-facts detail-facts--three mb-0">
                <div class="detail-fact"><dt>Nama</dt><dd>{{ $registeredUser->name }}</dd></div>
                <div class="detail-fact"><dt>Email</dt><dd>{{ $registeredUser->email }}</dd></div>
                <div class="detail-fact"><dt>Jabatan</dt><dd>{{ $registeredUser->job_title ?: '—' }}</dd></div>
                <div class="detail-fact"><dt>Role Akses</dt><dd>{{ $registeredUser->role->label() }}</dd></div>
                <div class="detail-fact"><dt>Warehouse</dt><dd class="mono-field">{{ $registeredUser->operationalProfile?->warehouse_code ?? '—' }}</dd></div>
                <div class="detail-fact"><dt>Pool</dt><dd class="mono-field">{{ $registeredUser->operationalProfile?->pool?->code ?? '—' }}</dd></div>
                <div class="detail-fact"><dt>Department</dt><dd class="mono-field">{{ $registeredUser->operationalProfile?->department?->code ?? '—' }}</dd></div>
                <div class="detail-fact"><dt>Terdaftar</dt><dd>{{ $registeredUser->created_at->format('d/m/Y H:i') }}</dd></div>
                <div class="detail-fact"><dt>Diperbarui</dt><dd>{{ $registeredUser->updated_at->format('d/m/Y H:i') }}</dd></div>
            </dl>
        </div>
    </section>
@endsection
