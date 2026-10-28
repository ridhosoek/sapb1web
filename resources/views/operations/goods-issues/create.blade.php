@extends('layouts.app')

@section('title', 'Goods Issue')
@section('page-title', 'Goods Issue')
@section('page-subtitle', 'Ajukan pengeluaran barang sesuai FPK untuk diverifikasi Admin HO sebelum posting SAP.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('goods-issues.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Goods Issue belum diajukan.</strong>
            <ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="alert alert-info py-2" role="status">
        <i class="fa-solid fa-circle-info me-1"></i>
        Item dibatasi ke stok tersedia di warehouse <strong class="mono">{{ $profile->warehouse_code ?: 'belum dipetakan' }}</strong>. Setelah Admin HO menyetujui, stok diperiksa ulang lalu Goods Issue dibuat di SAP sebagai bukti barang benar-benar keluar.
    </div>

    <form method="POST" action="{{ route('goods-issues.store') }}" data-sap-form>
        @csrf
        <input type="hidden" name="transaction_uuid" value="{{ old('transaction_uuid', $transactionUuid) }}">

        <div class="row g-3">
            <div class="col-xl-7">
                <section class="transaction-section h-100">
                    <header class="section-heading"><h2>Informasi Pengeluaran (FPK)</h2><span class="text-secondary small">Semua field bertanda * wajib</span></header>
                    <div class="transaction-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="gi-fpk">No. FPK *</label><input class="form-control mono-field" id="gi-fpk" name="fpk_number" maxlength="11" value="{{ old('fpk_number') }}" required></div>
                            <div class="col-md-6"><label class="form-label" for="gi-fpk-date">Tanggal FPK / Pengeluaran *</label><input class="form-control mono-field" id="gi-fpk-date" name="fpk_date" type="date" min="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" value="{{ old('fpk_date', now()->toDateString()) }}" required></div>
                            <div class="col-md-6">
                                <label class="form-label" for="gi-vehicle">Nopol *</label>
                                @include('operations.partials.vehicle-combobox', ['inputId' => 'gi-vehicle'])
                            </div>
                            <div class="col-md-6"><label class="form-label" for="gi-km">KM Saat Pengeluaran *</label><input class="form-control mono-field" id="gi-km" name="ending_km" type="number" min="0" step="1" value="{{ old('ending_km') }}" required></div>
                            <div class="col-12"><label class="form-label" for="gi-complaint">Keluhan / Indikasi Kerusakan *</label><textarea class="form-control" id="gi-complaint" name="complaint" rows="2" maxlength="1000" required>{{ old('complaint') }}</textarea></div>
                            <div class="col-12"><label class="form-label" for="gi-analysis">Analisa Kerusakan Mekanik *</label><textarea class="form-control" id="gi-analysis" name="damage_analysis" rows="2" maxlength="1000" required>{{ old('damage_analysis') }}</textarea></div>
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-xl-5">
                <section class="transaction-section h-100">
                    <header class="section-heading"><h2>Informasi Lokasi dan SAP</h2><i class="fa-solid fa-lock text-secondary"></i></header>
                    <div class="transaction-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Pool</label><div class="readonly-value mono-field">{{ $profile->pool->code }}</div></div>
                            <div class="col-md-6"><label class="form-label">Department</label><div class="readonly-value mono-field">{{ $profile->department->code }}</div></div>
                            <div class="col-12"><label class="form-label">Warehouse</label><div class="readonly-value mono-field"><i class="fa-solid fa-lock me-2"></i>{{ $profile->warehouse_code ?: 'Belum dipetakan' }}</div></div>
                            <div class="col-md-6"><label class="form-label">Posting Date</label><div class="readonly-value mono-field">{{ now()->format('d/m/Y') }}</div></div>
                            <div class="col-md-6"><label class="form-label">Document Date</label><div class="readonly-value mono-field">{{ now()->format('d/m/Y') }}</div></div>
                        </div>
                        <hr>
                        <div class="small text-secondary">
                            <div><strong>Ref. 2:</strong> diisi otomatis dengan nomor FPK.</div>
                            <div><strong>Remarks & Journal Memo:</strong> diisi otomatis dengan Nopol + nomor FPK.</div>
                            <div><strong>Tipe Pengeluaran & Account Code:</strong> dipilih dan dipetakan terpisah pada setiap baris item.</div>
                            <div><strong>No Pol:</strong> dikirim sebagai cost center dimensi kendaraan pada setiap baris.</div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <section class="transaction-section mt-3">
            <header class="section-heading"><h2>Detail Barang Sesuai FPK</h2><button class="btn btn-sm btn-outline-primary" type="button" data-add-line><i class="fa-solid fa-plus me-1"></i> Tambah Baris</button></header>
            @include('operations.partials.item-grid', ['context' => 'goods-issue'])
            <p class="item-grid-help">Hanya item inventory aktif dengan Available Stock &gt; 0 di warehouse user yang tampil. Setiap item wajib memiliki Tipe Pengeluaran/COA sendiri. Untuk ban, masukkan satu serial number per baris atau pisahkan dengan koma.</p>
        </section>

        <div class="sticky-action-bar">
            <span><strong data-line-count>{{ count(old('lines', [1])) }}</strong> Items <span class="mx-2 text-secondary">·</span> Total Qty Issue <strong data-total-quantity>0</strong></span>
            <div class="d-flex gap-2"><button class="btn btn-outline-secondary" type="reset">Reset</button><button class="btn btn-primary" type="submit" data-sap-submit><i class="fa-solid fa-paper-plane me-1"></i> Ajukan ke Admin HO</button></div>
        </div>
    </form>

@endsection
