@extends('layouts.app')

@section('title', 'Register User')
@section('page-title', 'Register User')
@section('page-subtitle', 'Daftarkan user operasional dan batasi akses berdasarkan role, Pool, serta Department.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-xl-7">
            <section class="operational-panel">
                <header class="panel-header">
                    <h2 class="panel-title">Data User Baru</h2>
                    <span class="text-secondary small">Semua field bertanda * wajib</span>
                </header>
                <div class="panel-body">
                    <form method="POST" action="{{ route('admin.users.store') }}" data-user-registration>
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="user-name">Nama Lengkap *</label>
                                <input class="form-control @error('name') is-invalid @enderror" id="user-name" name="name" value="{{ old('name') }}" maxlength="255" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-email">Email *</label>
                                <input class="form-control @error('email') is-invalid @enderror" id="user-email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="off" required>
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-password">Password *</label>
                                <input class="form-control @error('password') is-invalid @enderror" id="user-password" name="password" type="password" autocomplete="new-password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Minimal 10 karakter, huruf besar/kecil, dan angka.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-password-confirmation">Konfirmasi Password *</label>
                                <input class="form-control" id="user-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-role">Role *</label>
                                <select class="form-select @error('role') is-invalid @enderror" id="user-role" name="role" data-role-select required>
                                    <option value="">Pilih role</option>
                                    @foreach($roles as $role)
                                        <option value="{{ $role->value }}" data-description="{{ $role->description() }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                                    @endforeach
                                </select>
                                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text" data-role-description>Pilih role sesuai tanggung jawab user.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-warehouse">Warehouse</label>
                                <input class="form-control mono-field @error('warehouse_code') is-invalid @enderror" id="user-warehouse" name="warehouse_code" value="{{ old('warehouse_code') }}" maxlength="50" placeholder="contoh: DMI-01">
                                @error('warehouse_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-pool">Pool *</label>
                                <select class="form-select mono-field @error('pool_id') is-invalid @enderror" id="user-pool" name="pool_id" data-pool-select required>
                                    <option value="">Pilih Pool</option>
                                    @foreach($pools as $pool)
                                        <option value="{{ $pool->id }}" @selected((string) old('pool_id') === (string) $pool->id)>{{ $pool->code }} — {{ $pool->name }}</option>
                                    @endforeach
                                </select>
                                @error('pool_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-department">Department *</label>
                                <select class="form-select mono-field @error('department_id') is-invalid @enderror" id="user-department" name="department_id" data-department-select required>
                                    <option value="">Pilih Pool terlebih dahulu</option>
                                    @foreach($pools as $pool)
                                        @foreach($pool->departments as $department)
                                            <option value="{{ $department->id }}" data-pool-id="{{ $pool->id }}" @selected((string) old('department_id') === (string) $department->id)>
                                                {{ $department->code }} — {{ $department->name }}
                                            </option>
                                        @endforeach
                                    @endforeach
                                </select>
                                @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" id="user-active" name="is_active" type="checkbox" value="1" @checked((bool) old('is_active', true))>
                                    <label class="form-check-label" for="user-active">User langsung aktif dan dapat login</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-4">
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-user-plus me-1"></i> Daftarkan User
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="operational-panel h-100">
                <header class="panel-header"><h2 class="panel-title">Batasan Role</h2></header>
                <div class="panel-body">
                    <div class="role-guide">
                        @foreach($roles as $role)
                            <div class="role-guide-item">
                                <span class="role-guide-name">{{ $role->label() }}</span>
                                <p>{{ $role->description() }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="alert preview-notice py-2 mb-0 mt-3">
                        <i class="fa-solid fa-shield-halved me-1"></i>
                        Admin HO dapat mendaftarkan Admin Operasional dan Manager Operasional/Transport. Super Admin dapat mengelola seluruh role.
                    </div>
                </div>
            </section>
        </div>
    </div>

@endsection
