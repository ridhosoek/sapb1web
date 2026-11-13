@extends('layouts.app')

@section('title', 'Riwayat GRPO Web SAP')
@section('page-title', 'Goods Receipt PO')
@section('page-subtitle', 'Goods Receipt PO yang Anda kirim dari Web SAP dan sudah tersimpan di SAP.')

@section('content')
    @include('operations.ready-to-receive._tabs', ['active' => 'history'])
    <div class="alert preview-notice py-2" role="status">
        <i class="fa-solid fa-circle-info me-1"></i> Hanya GRPO yang <strong>Anda sendiri kirim dari Web SAP</strong> pada database <strong class="mono">{{ $companyDb }}</strong>@unless($seesAllWarehouses), warehouse <strong class="mono">{{ $warehouseCode ?: 'belum dipetakan' }}</strong>@endunless. GRPO tetap tampil setelah PO asal diterima penuh, ditutup, atau tidak lagi ada di daftar Siap Diterima. GRPO yang dibuat langsung di SAP atau oleh pengguna lain tidak ditampilkan. Status mengikuti pembacaan terakhir portal dari SAP.
    </div>
    @if($uncertainCommands->isNotEmpty())
        <div class="alert alert-warning" role="alert" data-uncertain-own-receipts>
            <strong><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ $uncertainCommands->count() }} pengiriman GRPO Anda belum dapat dipastikan hasilnya.</strong>
            Pengiriman ini <strong>bukan</strong> GRPO yang berhasil dan tidak masuk riwayat sampai SAP memastikannya. Jangan kirim ulang; minta Super Admin merekonsiliasinya di Integration Monitor.
            <ul class="small mb-0 mt-1">
                @foreach($uncertainCommands as $command)
                    <li>PO <span class="mono">{{ $uncertainPurchaseOrderNumbers[$command->purchase_order_doc_entry] ?? ($command->purchase_order_doc_entry ? 'DocEntry '.$command->purchase_order_doc_entry : 'tidak diketahui') }}</span> — dikirim {{ $command->last_attempt_at?->format('d/m/Y H:i') ?? '—' }}, status {{ str($command->status->value)->replace('_', ' ')->title() }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @include('partials.list-filter', [
        'id' => 'goods-receipt-history',
        'action' => route('ready-to-receive.history'),
        'filters' => $filters,
        'placeholder' => 'Cari nomor GRPO, nomor PO, vendor, FPK, atau warehouse',
        'statusOptions' => ['ACTIVE' => 'Aktif', 'CANCELLED' => 'Dibatalkan di SAP'],
    ])
    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Riwayat GRPO Web SAP</h2><span class="badge text-bg-light border">{{ $goodsReceipts->total() }} dokumen</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational" aria-label="Riwayat Goods Receipt PO yang dibuat dari Web SAP">
                <thead><tr><th>No. GRPO</th><th>Tanggal GRPO</th><th>PO Asal</th><th>Vendor</th><th>Warehouse</th><th>Status</th><th>Foto</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($goodsReceipts as $goodsReceipt)
                    @php
                        $receiptData = $goodsReceipt->normalized_data ?? [];
                        $receiptDate = data_get($receiptData, 'receipt_date');
                        $isCancelled = $goodsReceipt->sap_business_status === \App\Domain\Sap\Enums\BusinessStatus::Cancelled;
                    @endphp
                    <tr data-goods-receipt-row="{{ $goodsReceipt->id }}">
                        <td class="mono fw-semibold">{{ $goodsReceipt->sap_doc_num ?? '#'.$goodsReceipt->sap_doc_entry }}</td>
                        <td>{{ $receiptDate ? \Illuminate\Support\Carbon::parse($receiptDate)->format('d/m/Y') : '—' }}</td>
                        <td class="mono">{{ data_get($receiptData, 'base_document_num') ?? '—' }}</td>
                        <td>{{ data_get($receiptData, 'vendor_name') ?? data_get($receiptData, 'vendor_code') ?? '—' }}</td>
                        <td class="mono">{{ data_get($receiptData, 'warehouse_code') ?? '—' }}</td>
                        <td><span class="badge {{ $isCancelled ? 'text-bg-danger' : 'text-bg-success' }}" data-goods-receipt-status>{{ $isCancelled ? 'Dibatalkan di SAP' : 'Aktif' }}</span></td>
                        <td>{{ $goodsReceipt->attachments_count }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('ready-to-receive.history.show', $goodsReceipt) }}"><i class="fa-regular fa-file-lines me-1"></i> Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state" data-history-empty><i class="fa-solid fa-clock-rotate-left"></i>
                        @if($isFiltered)
                            Tidak ada GRPO Anda yang cocok dengan pencarian ini.
                        @else
                            Belum ada GRPO yang tercatat berhasil Anda kirim dari Web SAP pada database {{ $companyDb }}. GRPO yang dibuat langsung di SAP atau oleh pengguna lain tidak ditampilkan di sini.
                        @endif
                    </div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($goodsReceipts->hasPages())<div class="panel-body border-top">{{ $goodsReceipts->links() }}</div>@endif
    </section>
@endsection
