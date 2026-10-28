@extends('layouts.app')

@section('title', 'Goods Receipt PO')
@section('page-title', 'Goods Receipt PO')
@section('page-subtitle', 'Terima barang Purchase Order '.$purchaseOrder->sap_doc_num.' ke SAP dan simpan bukti penerimaannya di portal.')

@section('page-actions')
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('documents.print', $purchaseOrder) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print me-1"></i> Cetak Form GRPO</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('ready-to-receive.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
    </div>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><strong>Goods Receipt PO belum diproses.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="alert alert-info py-2">
        <i class="fa-solid fa-shield-halved me-1"></i>
        Data penerimaan dikirim ke SAP sebagai <strong>Goods Receipt PO</strong>. Foto tetap disimpan privat di Web SAP dan <strong>tidak dikirim</strong> ke attachment SAP Business One.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-7">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Referensi Purchase Order</h2><span class="badge {{ $goodsReceipt ? 'text-bg-success' : 'text-bg-light border' }}">{{ $goodsReceipt ? 'GRPO Dibuat' : 'Siap GRPO' }}</span></header>
                <div class="transaction-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4"><label class="form-label">Nomor PO</label><div class="readonly-value mono-field">{{ $purchaseOrder->sap_doc_num ?? '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">DocEntry</label><div class="readonly-value mono-field">{{ $purchaseOrder->sap_doc_entry ?? '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">FPK</label><div class="readonly-value mono-field">{{ $purchaseOrder->fpk_number ?? '—' }}</div></div>
                        <div class="col-sm-6"><label class="form-label">Vendor</label><div class="readonly-value">{{ data_get($normalizedData, 'vendor_name', data_get($normalizedData, 'document.CardName', '—')) }}</div></div>
                        <div class="col-sm-6"><label class="form-label">Nopol</label><div class="readonly-value mono-field">{{ $vehicle ?: '—' }}</div></div>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-xl-5">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Konteks Penerimaan</h2><i class="fa-solid fa-lock text-secondary"></i></header>
                <div class="transaction-body">
                    <dl class="row mb-0 detail-list">
                        <dt class="col-5">Database</dt><dd class="col-7 mono">{{ $purchaseOrder->company_db }}</dd>
                        <dt class="col-5">Pool</dt><dd class="col-7 mono">{{ $purchaseOrder->pool?->code ?? '—' }}</dd>
                        <dt class="col-5">Department</dt><dd class="col-7 mono">{{ $purchaseOrder->department?->code ?? '—' }}</dd>
                        <dt class="col-5">Status PO</dt><dd class="col-7">{{ str($purchaseOrder->sap_business_status->value)->title() }}</dd>
                        <dt class="col-5">Nomor GRPO</dt><dd class="col-7 mono">{{ $goodsReceipt?->sap_doc_num ?? 'Belum dibuat' }}</dd>
                        <dt class="col-5">Terakhir sync</dt><dd class="col-7">{{ $purchaseOrder->updated_at->format('d/m/Y H:i') }}</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Barang yang Akan Diterima</h2><span class="badge text-bg-light border">{{ $lines->count() }} baris</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle mb-0">
                <thead><tr><th>#</th><th>Item</th><th>Description</th><th class="text-end">Order Qty</th><th class="text-end">Open Qty</th><th style="min-width: 150px">Qty Diterima</th><th>UoM</th><th>Warehouse</th></tr></thead>
                <tbody>
                @forelse($lines as $index => $line)
                    @php
                        $item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null;
                        $lineNumber = (int) ($line['LineNum'] ?? $line['line_num'] ?? $index);
                        $openQuantity = (float) ($line['open_quantity'] ?? $line['RemainingOpenQuantity'] ?? $line['OpenQty'] ?? $line['quantity'] ?? $line['Quantity'] ?? 0);
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="mono fw-semibold">{{ $item?->item_code ?? $line['item_code'] ?? $line['ItemCode'] ?? '—' }}</td>
                        <td>{{ $item?->item_name ?? $line['item_name'] ?? $line['description'] ?? $line['ItemDescription'] ?? $line['Dscription'] ?? '—' }}</td>
                        <td class="text-end mono">{{ number_format((float) ($line['quantity'] ?? $line['Quantity'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-end mono">{{ number_format($openQuantity, 2, ',', '.') }}</td>
                        <td>
                            @if($goodsReceipt)
                                <span class="mono">{{ number_format((float) data_get($goodsReceipt->normalized_data, 'lines.'.$index.'.quantity', 0), 2, ',', '.') }}</span>
                            @else
                                <input form="goods-receipt-form" type="hidden" name="lines[{{ $index }}][line_num]" value="{{ $lineNumber }}">
                                <input form="goods-receipt-form" class="form-control form-control-sm @error('lines.'.$index.'.quantity') is-invalid @enderror" type="number" name="lines[{{ $index }}][quantity]" min="0.000001" max="{{ $openQuantity }}" step="0.000001" value="{{ old('lines.'.$index.'.quantity', $openQuantity) }}" required>
                            @endif
                        </td>
                        <td class="mono">{{ $item?->inventory_uom ?? $line['uom'] ?? $line['UoMCode'] ?? $line['MeasureUnit'] ?? '—' }}</td>
                        <td class="mono">{{ $line['warehouse_code'] ?? $line['WarehouseCode'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><i class="fa-regular fa-file-lines"></i>Detail item Purchase Order belum tersedia pada cache portal.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="row g-3">
        @can('create', \App\Models\SapDocument::class)
        <div class="col-xl-4">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>{{ $goodsReceipt ? 'Tambah Dokumentasi' : 'Buat GRPO dan Dokumentasi' }}</h2><span class="badge text-bg-light border">Foto Portal Only</span></header>
                <form id="goods-receipt-form" class="transaction-body" method="POST" action="{{ $goodsReceipt ? route('ready-to-receive.attachments.store', $purchaseOrder) : route('ready-to-receive.receive', $purchaseOrder) }}" enctype="multipart/form-data">
                    @csrf
                    @unless($goodsReceipt)
                    <div class="mb-3">
                        <label class="form-label" for="receipt-date">Tanggal Penerimaan *</label>
                        <input class="form-control @error('receipt_date') is-invalid @enderror" id="receipt-date" name="receipt_date" type="date" max="{{ now()->toDateString() }}" value="{{ old('receipt_date', now()->toDateString()) }}" required>
                        <div class="form-text">Tanggal ini menjadi Posting Date, Due Date, dan Document Date GRPO di SAP.</div>
                    </div>
                    @endunless
                    <div class="mb-3">
                        <label class="form-label" for="receipt-images">Foto Barang / Surat Jalan {{ $goodsReceipt ? '*' : '(opsional)' }}</label>
                        <input class="form-control @error('images') is-invalid @enderror @error('images.*') is-invalid @enderror" id="receipt-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple {{ $goodsReceipt ? 'required' : '' }}>
                        <div class="form-text">JPG, PNG, atau WebP. Maksimal {{ config('sap.documents.goods_receipt_attachments.max_files_per_upload', 5) }} foto, masing-masing {{ number_format(config('sap.documents.goods_receipt_attachments.max_size_kb', 5120) / 1024, 0) }} MB.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="receipt-caption">Keterangan</label>
                        <textarea class="form-control" id="receipt-caption" name="caption" rows="3" maxlength="255" placeholder="Contoh: Barang diterima lengkap dan kondisi baik">{{ old('caption') }}</textarea>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="fa-solid {{ $goodsReceipt ? 'fa-cloud-arrow-up' : 'fa-truck-ramp-box' }} me-1"></i>
                        {{ $goodsReceipt ? 'Simpan Dokumentasi' : 'Terima Barang & Kirim GRPO ke SAP' }}
                    </button>
                </form>
            </section>
        </div>
        @endcan
        <div class="{{ auth()->user()->can('create', \App\Models\SapDocument::class) ? 'col-xl-8' : 'col-12' }}">
            <section class="operational-panel h-100">
                <header class="panel-header"><h2 class="panel-title">Galeri Dokumentasi</h2><span class="badge text-bg-light border">{{ $attachments->count() }} foto</span></header>
                <div class="panel-body">
                    @if($attachments->isEmpty())
                        <div class="empty-state"><i class="fa-regular fa-images"></i>Belum ada foto penerimaan untuk Purchase Order ini.</div>
                    @else
                        <div class="receipt-gallery">
                            @foreach($attachments as $attachment)
                                <figure class="receipt-photo-card">
                                    <a href="{{ route('document-attachments.show', $attachment) }}" target="_blank" rel="noopener">
                                        <img src="{{ route('document-attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy">
                                    </a>
                                    <figcaption>
                                        <strong title="{{ $attachment->original_name }}">{{ $attachment->original_name }}</strong>
                                        <span>{{ $attachment->caption ?: 'Tanpa keterangan' }}</span>
                                        <small>{{ $attachment->uploader?->name ?? 'User tidak tersedia' }} · {{ $attachment->created_at->format('d/m/Y H:i') }}</small>
                                    </figcaption>
                                </figure>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
