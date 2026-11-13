@extends('layouts.app')

@php
    $isCancelled = $goodsReceipt->sap_business_status === \App\Domain\Sap\Enums\BusinessStatus::Cancelled;
    $receiptDate = data_get($normalizedData, 'receipt_date');
@endphp

@section('title', 'Detail GRPO '.($goodsReceipt->sap_doc_num ?? '#'.$goodsReceipt->sap_doc_entry))
@section('page-title', 'Goods Receipt PO')
@section('page-subtitle', 'Detail GRPO '.($goodsReceipt->sap_doc_num ?? '#'.$goodsReceipt->sap_doc_entry).' yang Anda kirim dari Web SAP.')

@section('page-actions')
    <div class="d-flex gap-2">
        @if($purchaseOrder)
            <a class="btn btn-sm btn-outline-primary" href="{{ route('ready-to-receive.show', $purchaseOrder) }}"><i class="fa-solid fa-truck-ramp-box me-1"></i> Buka PO Asal</a>
        @endif
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('ready-to-receive.history') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Riwayat</a>
    </div>
@endsection

@section('content')
    @if($isCancelled)
        <div class="alert alert-danger py-2" role="alert" data-goods-receipt-cancelled><i class="fa-solid fa-ban me-1"></i>GRPO ini tercatat <strong>dibatalkan di SAP</strong>.</div>
    @endif
    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Goods Receipt PO</h2><span class="badge {{ $isCancelled ? 'text-bg-danger' : 'text-bg-success' }}">{{ $isCancelled ? 'Dibatalkan di SAP' : 'Aktif' }}</span></header>
                <div class="transaction-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4"><label class="form-label">Nomor GRPO</label><div class="readonly-value mono-field">{{ $goodsReceipt->sap_doc_num ?? '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">DocEntry SAP</label><div class="readonly-value mono-field">{{ $goodsReceipt->sap_doc_entry }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">Tanggal GRPO</label><div class="readonly-value">{{ $receiptDate ? \Illuminate\Support\Carbon::parse($receiptDate)->format('d/m/Y') : '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">PO Asal</label><div class="readonly-value mono-field">{{ data_get($normalizedData, 'base_document_num') ?? '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">Warehouse</label><div class="readonly-value mono-field">{{ data_get($normalizedData, 'warehouse_code') ?? '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">FPK</label><div class="readonly-value mono-field">{{ $goodsReceipt->fpk_number ?? '—' }}</div></div>
                        <div class="col-12"><label class="form-label">Vendor</label><div class="readonly-value">{{ data_get($normalizedData, 'vendor_name') ?? data_get($normalizedData, 'vendor_code') ?? '—' }}</div></div>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Konteks</h2><i class="fa-solid fa-lock text-secondary"></i></header>
                <div class="transaction-body">
                    <dl class="row mb-0 detail-list">
                        <dt class="col-5">Database</dt><dd class="col-7 mono">{{ $goodsReceipt->company_db }}</dd>
                        <dt class="col-5">Pool</dt><dd class="col-7 mono">{{ $goodsReceipt->pool?->code ?? '—' }}</dd>
                        <dt class="col-5">Department</dt><dd class="col-7 mono">{{ $goodsReceipt->department?->code ?? '—' }}</dd>
                        <dt class="col-5">Dikirim dari Web</dt><dd class="col-7">{{ $goodsReceipt->created_at->format('d/m/Y H:i') }}</dd>
                        <dt class="col-5">Terakhir dibaca</dt><dd class="col-7">{{ $goodsReceipt->updated_at->format('d/m/Y H:i') }}</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Barang yang Diterima</h2><span class="badge text-bg-light border">{{ $lines->count() }} baris</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle mb-0">
                <thead><tr><th>#</th><th>Item</th><th>Description</th><th>Nopol</th><th class="text-end">Qty Diterima</th><th>Warehouse</th><th>Serial Numbers</th></tr></thead>
                <tbody>
                @forelse($lines as $index => $line)
                    @php
                        $serialLabels = collect($line['serial_numbers'] ?? [])
                            ->map(fn ($serialNumber) => is_array($serialNumber)
                                ? collect([
                                    $serialNumber['manufacturer_serial_number'] ?? null,
                                    $serialNumber['internal_serial_number'] ?? null,
                                    $serialNumber['lot_number'] ?? null,
                                ])->filter()->join(' / ')
                                : (string) $serialNumber)
                            ->filter();
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="mono fw-semibold">{{ $line['item_code'] ?? $line['ItemCode'] ?? '—' }}</td>
                        <td>{{ $line['item_name'] ?? $line['ItemDescription'] ?? '—' }}</td>
                        <td class="mono">{{ $line['vehicle_code'] ?? '—' }}</td>
                        <td class="text-end mono">{{ number_format((float) ($line['quantity'] ?? $line['Quantity'] ?? 0), 2, ',', '.') }}</td>
                        <td class="mono">{{ $line['warehouse_code'] ?? $line['WarehouseCode'] ?? '—' }}</td>
                        <td>
                            @forelse($serialLabels as $serialLabel)
                                <span class="badge text-bg-light border mono">{{ $serialLabel }}</span>
                            @empty
                                <span class="text-secondary">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-regular fa-file-lines"></i>Detail baris GRPO tidak tersedia pada cache portal.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="operational-panel">
        <header class="panel-header"><div><h2 class="panel-title">Foto GRPO</h2><div class="small text-secondary">Dokumentasi privat portal yang tertaut ke GRPO ini; tidak dikirim ke SAP Business One.</div></div><span class="badge text-bg-light border">{{ $goodsReceipt->attachments->count() }} foto</span></header>
        <div class="panel-body">
            @if($goodsReceipt->attachments->isEmpty())
                <div class="empty-state"><i class="fa-regular fa-images"></i>Belum ada foto yang tertaut ke GRPO ini.</div>
            @else
                <div class="receipt-gallery">
                    @foreach($goodsReceipt->attachments as $attachment)
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
@endsection
