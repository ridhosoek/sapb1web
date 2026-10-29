@extends('layouts.app')

@section('title', 'User')
@section('page-title', 'User')
@section('page-subtitle', 'Daftar user, role, dan konteks operasional yang dapat mengakses portal.')

@section('page-actions')
    <a class="btn btn-sm btn-primary" href="{{ route('admin.users.create') }}"><i class="fa-solid fa-user-plus me-1"></i> Register User</a>
@endsection

@section('content')
    @if(session('status'))<div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>@endif

    @include('partials.list-filter', [
        'id' => 'users',
        'action' => route('admin.users.index'),
        'filters' => $filters,
        'placeholder' => 'Cari nama, email, Pool, atau Department',
        'statusLabel' => 'Status User',
        'statusOptions' => ['ACTIVE' => 'Aktif', 'INACTIVE' => 'Nonaktif'],
    ])

    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">User Terdaftar</h2><span class="badge text-bg-light border">{{ $users->total() }} user</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational">
                <thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Pool</th><th>Department</th><th>Warehouse</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($users as $registeredUser)
                    <tr>
                        <td><a href="{{ route('admin.users.show', $registeredUser) }}">{{ $registeredUser->name }}</a></td><td>{{ $registeredUser->email }}</td>
                        <td><span class="badge text-bg-light border">{{ $registeredUser->role->label() }}</span></td>
                        <td class="mono">{{ $registeredUser->operationalProfile?->pool?->code ?? '—' }}</td>
                        <td class="mono">{{ $registeredUser->operationalProfile?->department?->code ?? '—' }}</td>
                        <td class="mono">{{ $registeredUser->operationalProfile?->warehouse_code ?? '—' }}</td>
                        <td><span class="status-dot {{ $registeredUser->is_active ? 'success' : 'muted' }}"></span>{{ $registeredUser->is_active ? 'Aktif' : 'Nonaktif' }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.show', $registeredUser) }}">Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><i class="fa-regular fa-user"></i>Belum ada user terdaftar.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())<div class="panel-body border-top">{{ $users->links() }}</div>@endif
    </section>
@endsection
