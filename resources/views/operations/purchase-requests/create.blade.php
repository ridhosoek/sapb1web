@extends('layouts.app')

@section('title', 'Purchase Request')
@section('page-title', 'Purchase Request')
@section('page-subtitle', 'Input Purchase Request baru dengan data master dari SAP Business One.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase-requests.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Purchase Request belum diajukan.</strong>
            <ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="alert alert-info py-2" role="status">
        <i class="fa-solid fa-circle-info me-1"></i>
        Pengajuan disimpan di portal dan dikirim ke SAP hanya setelah seluruh tahap approval selesai. Item dan Nopol dibaca dari database SAP yang dipilih.
    </div>

    <form method="POST" action="{{ route('purchase-requests.store') }}" data-sap-form data-sparepart-history-form data-sparepart-history-url="{{ route('purchase-requests.sparepart-history') }}">
        @csrf
        <input type="hidden" name="transaction_uuid" value="{{ old('transaction_uuid', $transactionUuid) }}">

        <div class="row g-3">
            <div class="col-xl-7">
                <section class="transaction-section h-100">
                    <header class="section-heading"><h2>Informasi Permintaan</h2><span class="text-secondary small">Field bertanda * wajib</span></header>
                    <div class="transaction-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="pr-vehicle">Nopol *</label>
                                @include('operations.partials.vehicle-combobox', ['inputId' => 'pr-vehicle'])
                            </div>
                            <div class="col-md-6"><label class="form-label" for="pr-required-date">Tanggal Kebutuhan / Purchase *</label><input class="form-control mono-field" id="pr-required-date" name="required_date" type="date" min="{{ now()->toDateString() }}" value="{{ old('required_date', now()->toDateString()) }}" required></div>
                            <div class="col-md-6">
                                <label class="form-label" for="pr-ending-km">KM Akhir *</label>
                                <input class="form-control mono-field @error('ending_km') is-invalid @enderror" id="pr-ending-km" name="ending_km" type="number" min="0" step="1" value="{{ old('ending_km') }}" required>
                                @error('ending_km')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">@if(filled(config('sap.fields.current_km'))) Dikirim ke UDF SAP <span class="mono">{{ config('sap.fields.current_km') }}</span>. @else Disimpan di portal; UDF KM SAP belum dipetakan. @endif</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="pr-priority">Prioritas *</label>
                                <select class="form-select @error('priority') is-invalid @enderror" id="pr-priority" name="priority" required>
                                    <option value="NORMAL" @selected(old('priority', 'NORMAL') === 'NORMAL')>NORMAL</option>
                                    <option value="URGENT" @selected(old('priority') === 'URGENT')>URGENT</option>
                                </select>
                                @error('priority')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Dikirim ke SAP pada UDF <span class="mono">U_VPRIOR</span>.</div>
                            </div>
                            <div class="col-12"><label class="form-label" for="pr-notes">Remarks</label><textarea class="form-control" id="pr-notes" name="notes" rows="3" maxlength="254" placeholder="Contoh: U/ L8568UZ - POOL KENDARI">{{ old('notes') }}</textarea></div>
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-xl-5">
                <section class="transaction-section h-100">
                    <header class="section-heading"><h2>Informasi Organisasi</h2><i class="fa-solid fa-lock text-secondary" title="Diambil dari profil user"></i></header>
                    <div class="transaction-body">
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label">Requester</label><div class="readonly-value"><i class="fa-solid fa-lock me-2"></i>{{ auth()->user()->name }}</div></div>
                            <div class="col-md-6"><label class="form-label">Pool</label><div class="readonly-value mono-field">{{ $profile->pool->code }}</div></div>
                            <div class="col-md-6"><label class="form-label">Department</label><div class="readonly-value mono-field">{{ $profile->department->code }}</div></div>
                            <div class="col-12"><label class="form-label">Warehouse</label><div class="readonly-value mono-field"><i class="fa-solid fa-lock me-2"></i>{{ $profile->warehouse_code ?: 'Belum dipetakan' }}</div></div>
                            <div class="col-md-6"><label class="form-label">Posting Date</label><div class="readonly-value mono-field">{{ now()->format('d/m/Y') }}</div></div>
                            <div class="col-md-6"><label class="form-label">Document Date</label><div class="readonly-value mono-field">{{ now()->format('d/m/Y') }}</div></div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <section class="transaction-section mt-3">
            <header class="section-heading">
                <h2>Detail Barang</h2>
                <button class="btn btn-sm btn-outline-primary" type="button" data-add-line><i class="fa-solid fa-plus me-1"></i> Tambah Baris</button>
            </header>
            @include('operations.partials.item-grid', ['context' => 'purchase-request'])
            <p class="item-grid-help">Daftar menampilkan seluruh purchase item yang tidak di-deactivate pada database SAP terpilih. Department, Pool, Nopol, Warehouse, dan Required Date dikirim otomatis pada setiap baris.</p>
        </section>

        <section class="transaction-section mt-3" data-sparepart-history>
            <header class="section-heading">
                <div>
                    <h2>Riwayat Pemakaian Sparepart</h2>
                    <span class="text-secondary small">Goods Issue terakhir untuk setiap item pada Nopol yang dipilih. Data ini disimpan bersama PR sebagai bahan approval.</span>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('sparepart-report.index') }}" target="_blank" rel="noopener"><i class="fa-solid fa-chart-column me-1"></i> Buka Report Lengkap</a>
            </header>
            <div class="transaction-body">
                <div class="alert alert-warning py-2 d-none" data-sparepart-history-warning></div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Item pada PR dan Goods Issue Terakhir</h3>
                    <span class="badge text-bg-light border" data-sparepart-vehicle-label>Pilih Nopol</span>
                </div>
                <div class="table-responsive border rounded">
                    <table class="table table-sm table-operational align-middle mb-0" aria-label="Riwayat pemakaian sparepart per item dan Nopol">
                        <thead><tr><th>Item</th><th>Goods Issue Terakhir</th><th>FPK</th><th class="text-end">Qty</th><th class="text-end">Beban</th><th class="text-end">Lama Pemakaian</th><th>Status</th></tr></thead>
                        <tbody data-sparepart-usage-history><tr><td colspan="7" class="text-center text-secondary py-4">Pilih Nopol dan item untuk melihat riwayat pemakaian.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="sticky-action-bar">
            <span><strong data-line-count>{{ count(old('lines', [1])) }}</strong> Items <span class="mx-2 text-secondary">·</span> Total Qty <strong data-total-quantity>0</strong></span>
            <div class="d-flex gap-2"><button class="btn btn-outline-secondary" type="reset">Reset</button><button class="btn btn-primary" type="submit" data-sap-submit><i class="fa-solid fa-paper-plane me-1"></i> Ajukan Approval</button></div>
        </div>
    </form>

@endsection
