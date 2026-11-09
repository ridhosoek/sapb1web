@extends('layouts.app')

@section('title', 'Register User')
@section('page-title', 'Register User')
@section('page-subtitle', 'Daftarkan user operasional dengan dimensi Pool, Department, serta batas akses Warehouse.')

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
                    <form method="GET" action="{{ route('admin.users.create') }}" class="mb-4">
                        <label class="form-label" for="mapping-company">Database SAP untuk pemetaan *</label>
                        <select class="form-select mono-field" id="mapping-company" name="company_db" onchange="this.form.submit()">
                            @foreach($companies as $company)
                                <option value="{{ $company }}" @selected($company === $companyDb)>{{ $company }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Pool, Department, dan Warehouse di bawah hanya berlaku untuk database ini. Pilih database lebih dulu; mengganti database memuat ulang pilihan. Pemetaan database lain diisi terpisah lewat Edit User.</div>
                    </form>
                    <form method="POST" action="{{ route('admin.users.store') }}" data-user-registration>
                        @csrf
                        <input type="hidden" name="company_db" value="{{ $companyDb }}">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="user-name">Nama Lengkap *</label>
                                <input class="form-control @error('name') is-invalid @enderror" id="user-name" name="name" value="{{ old('name') }}" maxlength="255" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-job-title">Jabatan Asli *</label>
                                <input class="form-control @error('job_title') is-invalid @enderror" id="user-job-title" name="job_title" value="{{ old('job_title') }}" maxlength="150" placeholder="contoh: Supervisor Admin Operasional" required>
                                @error('job_title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Jabatan ini ditampilkan pada dokumen cetak; berbeda dari role akses aplikasi.</div>
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
                                <label class="form-label" for="user-pool">Pool *</label>
                                <select class="form-select mono-field @error('pool_cost_center_id') is-invalid @enderror" id="user-pool" name="pool_cost_center_id" required>
                                    <option value="">Pilih Pool</option>
                                    @foreach($poolCostCenters as $poolCostCenter)
                                        <option value="{{ $poolCostCenter->id }}" @selected((string) old('pool_cost_center_id') === (string) $poolCostCenter->id)>
                                            {{ $poolCostCenter->center_code }} — {{ $poolCostCenter->center_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('pool_cost_center_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Cost center aktif dimensi Pool pada database SAP {{ $companyDb }}.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-warehouse">Warehouse *</label>
                                <input class="form-control mono-field @error('warehouse_code') is-invalid @enderror" id="user-warehouse" name="warehouse_code" value="{{ old('warehouse_code') }}" maxlength="50" placeholder="contoh: DMI-01" list="warehouse-options" required>
                                <datalist id="warehouse-options">
                                    @foreach($warehouseCodes as $warehouseCode)<option value="{{ $warehouseCode }}"></option>@endforeach
                                </datalist>
                                @error('warehouse_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Warehouse aktif pada database SAP {{ $companyDb }}; menjadi batas akses data dan stok user.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="user-department">Department *</label>
                                <select class="form-select mono-field @error('department_cost_center_id') is-invalid @enderror" id="user-department" name="department_cost_center_id" data-department-select required>
                                    <option value="">Pilih Department</option>
                                    @foreach($departmentCostCenters as $departmentCostCenter)
                                        <option value="{{ $departmentCostCenter->id }}" @selected((string) old('department_cost_center_id') === (string) $departmentCostCenter->id)>
                                            {{ $departmentCostCenter->center_code }} — {{ $departmentCostCenter->center_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('department_cost_center_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Cost center aktif dimensi Department pada database SAP {{ $companyDb }}. Dipilih terpisah dari Pool.</div>
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
