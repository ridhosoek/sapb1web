@extends('layouts.app')

@section('title', 'Edit User')
@section('page-title', 'Edit User')
@section('page-subtitle', 'Perbarui akun, role, Pool, Warehouse, password, dan status user.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.show', $registeredUser) }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Detail</a>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-xl-8">
            <section class="operational-panel">
                <header class="panel-header">
                    <div><h2 class="panel-title">Data User</h2><div class="small text-secondary">Pool, Department, dan Warehouse disimpan terpisah untuk setiap database SAP.</div></div>
                    <span class="badge {{ $registeredUser->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $registeredUser->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                </header>
                <div class="panel-body">
                    <form method="GET" action="{{ route('admin.users.edit', $registeredUser) }}" class="mb-4">
                        <label class="form-label" for="mapping-company">Database SAP yang dipetakan *</label>
                        <select class="form-select mono-field" id="mapping-company" name="company_db" onchange="this.form.submit()">
                            @foreach($companies as $company)
                                <option value="{{ $company }}" @selected($company === $companyDb)>{{ $company }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Mengganti database memuat ulang form; perubahan yang belum disimpan tidak terbawa. Menyimpan hanya mengubah pemetaan database {{ $companyDb }}.</div>
                    </form>
                    @if(! $profile)
                        <div class="alert alert-warning py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> User ini belum memiliki pemetaan untuk database <strong>{{ $companyDb }}</strong>. Selama belum diisi, user tidak dapat membuat transaksi di database tersebut. Pilihan tidak diisi otomatis dari database lain.</div>
                    @endif
                    @if($legacyProfile)
                        <div class="small text-secondary mb-3">Profil lama (belum dipetakan ke database mana pun, hanya referensi): Pool {{ $legacyProfile->pool?->code ?? '—' }}, Department {{ $legacyProfile->department?->code ?? '—' }}, Warehouse {{ $legacyProfile->warehouse_code ?? '—' }}.</div>
                    @endif
                    <form method="POST" action="{{ route('admin.users.update', $registeredUser) }}" data-user-registration>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="company_db" value="{{ $companyDb }}">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="user-name">Nama Lengkap *</label>
                                <input class="form-control @error('name') is-invalid @enderror" id="user-name" name="name" value="{{ old('name', $registeredUser->name) }}" maxlength="255" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-job-title">Jabatan Asli *</label>
                                <input class="form-control @error('job_title') is-invalid @enderror" id="user-job-title" name="job_title" value="{{ old('job_title', $registeredUser->job_title) }}" maxlength="150" required>
                                @error('job_title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Ditampilkan pada dokumen cetak dan tidak mengubah hak akses role.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-email">Email *</label>
                                <input class="form-control @error('email') is-invalid @enderror" id="user-email" name="email" type="email" value="{{ old('email', $registeredUser->email) }}" maxlength="255" required>
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-role">Role *</label>
                                <select class="form-select @error('role') is-invalid @enderror" id="user-role" name="role" data-role-select required>
                                    @foreach($roles as $role)
                                        <option value="{{ $role->value }}" data-description="{{ $role->description() }}" @selected(old('role', $registeredUser->role->value) === $role->value)>{{ $role->label() }}</option>
                                    @endforeach
                                </select>
                                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text" data-role-description>Pilih role sesuai tanggung jawab user.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-pool">Pool *</label>
                                <select class="form-select mono-field @error('pool_cost_center_id') is-invalid @enderror" id="user-pool" name="pool_cost_center_id" required>
                                    <option value="">Pilih Pool</option>
                                    @foreach($poolCostCenters as $poolCostCenter)
                                        <option value="{{ $poolCostCenter->id }}" @selected((string) old('pool_cost_center_id', $selectedPoolCostCenterId) === (string) $poolCostCenter->id)>
                                            {{ $poolCostCenter->center_code }} — {{ $poolCostCenter->center_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('pool_cost_center_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Cost center aktif dimensi Pool pada database SAP {{ $companyDb }}.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-warehouse">Warehouse *</label>
                                <input class="form-control mono-field @error('warehouse_code') is-invalid @enderror" id="user-warehouse" name="warehouse_code" value="{{ old('warehouse_code', $profile?->warehouse_code) }}" maxlength="50" list="warehouse-options" required>
                                <datalist id="warehouse-options">
                                    @foreach($warehouseCodes as $warehouseCode)<option value="{{ $warehouseCode }}"></option>@endforeach
                                </datalist>
                                @error('warehouse_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Warehouse aktif pada database SAP {{ $companyDb }}; menjadi batas akses data dan stok user.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-department">Department *</label>
                                <select class="form-select mono-field @error('department_id') is-invalid @enderror" id="user-department" name="department_id" required>
                                    <option value="">Pilih Department</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}" @selected((string) old('department_id', $profile?->department_id) === (string) $department->id)>
                                            {{ $department->code }} — {{ $department->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Hanya Department yang kodenya cocok dengan cost center Department aktif di database SAP {{ $companyDb }}.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-password">Password Baru</label>
                                <input class="form-control @error('password') is-invalid @enderror" id="user-password" name="password" type="password" autocomplete="new-password">
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Kosongkan jika password tidak ingin diubah.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-password-confirmation">Konfirmasi Password Baru</label>
                                <input class="form-control" id="user-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    @if($registeredUser->is(auth()->user()))
                                        <input type="hidden" name="is_active" value="1">
                                        <input class="form-check-input" id="user-active" type="checkbox" checked disabled>
                                        <label class="form-check-label" for="user-active">User aktif</label>
                                        <div class="form-text">Akun yang sedang digunakan tidak dapat dinonaktifkan sendiri.</div>
                                    @else
                                        <input type="hidden" name="is_active" value="0">
                                        <input class="form-check-input" id="user-active" name="is_active" type="checkbox" value="1" @checked((bool) old('is_active', $registeredUser->is_active))>
                                        <label class="form-check-label" for="user-active">User aktif dan dapat login</label>
                                    @endif
                                    @error('is_active')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a class="btn btn-outline-secondary" href="{{ route('admin.users.show', $registeredUser) }}">Batal</a>
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="operational-panel">
                <header class="panel-header"><h2 class="panel-title">Dampak Perubahan</h2></header>
                <div class="panel-body small">
                    <p><strong>Pool dan Department</strong> menentukan cost center pada transaksi berikutnya di database {{ $companyDb }}. Dokumen yang sudah diajukan tetap memakai snapshot saat pengajuan.</p>
                    <p><strong>Warehouse</strong> membatasi item, stok, dan dokumen yang dapat dilihat user di database {{ $companyDb }}.</p>
                    <p><strong>Nonaktif</strong> mencegah user mengakses portal tanpa menghapus histori transaksi.</p>
                    <p class="mb-0"><strong>Password</strong> hanya berubah ketika password baru diisi dan dikonfirmasi.</p>
                </div>
            </section>
        </div>
    </div>
@endsection
