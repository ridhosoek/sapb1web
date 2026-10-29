@extends('layouts.app')

@section('title', 'Goods Receipt PO')
@section('page-title', 'Goods Receipt PO')
@section('page-subtitle', 'Proses Purchase Order terbuka dan lihat histori Goods Receipt PO yang sudah dibuat.')

@section('content')
    <div class="alert preview-notice py-2" role="status">
        <i class="fa-solid fa-circle-info me-1"></i> Data penerimaan dikirim ke SAP sebagai Goods Receipt PO. Foto penerimaan tetap tersimpan privat di portal.
    </div>
    @include('partials.list-filter', [
        'id' => 'goods-receipt-po',
        'action' => route('ready-to-receive.index'),
        'filters' => $filters,
        'placeholder' => 'Cari nomor PO, GRPO, FPK, vendor, Nopol, atau item',
        'statusLabel' => 'Penerimaan',
        'statusOptions' => ['OPEN' => 'Belum Diterima', 'RECEIVED' => 'Sudah GRPO'],
    ])
    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Daftar Goods Receipt PO</h2><span class="badge text-bg-light border">{{ $purchaseOrders->total() }} dokumen</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational" aria-label="Purchase Order siap diterima">
                <thead><tr><th>PO</th><th>GRPO</th><th>FPK</th><th>Vendor</th><th>Nopol</th><th>Open Lines</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($purchaseOrders as $purchaseOrder)
                    @php
                        $normalizedData = $purchaseOrder->normalized_data ?? [];
                        $rootPurchaseRequest = $purchaseOrder->parentRelations->first()?->parentDocument?->parentRelations->first()?->parentDocument;
                        $vendorName = data_get($normalizedData, 'vendor_name', data_get($normalizedData, 'document.CardName', '—'));
                        $vehicle = data_get(
                            $normalizedData,
                            'vehicle',
                            data_get($normalizedData, 'vehicle_cost_center.center_code', data_get($rootPurchaseRequest?->normalized_data, 'vehicle_cost_center.center_code', '—')),
                        );
                        $openLines = data_get($normalizedData, 'open_lines', collect(data_get($normalizedData, 'lines', []))->count());
                        $progressStatus = data_get($normalizedData, 'progress_status', 'READY_FOR_GRPO');
                        $goodsReceipt = $purchaseOrder->childRelations->firstWhere('relation_type', 'GOODS_RECEIPT_PO')?->childDocument;
                        $progressStatus = $goodsReceipt ? 'FULLY_RECEIVED' : $progressStatus;
                    @endphp
                    <tr>
                        <td class="mono"><a href="{{ route('documents.show', $purchaseOrder) }}">{{ $purchaseOrder->sap_doc_num ?? 'PENDING' }}</a></td>
                        <td class="mono">{{ $goodsReceipt?->sap_doc_num ?? '—' }}</td>
                        <td class="mono">{{ $purchaseOrder->fpk_number ?? '—' }}</td>
                        <td>{{ $vendorName }}</td>
                        <td class="mono">{{ $vehicle }}</td>
                        <td>{{ $openLines }}</td>
                        <td>{{ str($progressStatus)->replace('_', ' ')->title() }}</td>
                        <td class="text-end"><div class="d-flex justify-content-end gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.print', $purchaseOrder) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i><span class="visually-hidden">Cetak</span></a><a class="btn btn-sm btn-outline-primary" href="{{ route('ready-to-receive.show', $purchaseOrder) }}"><i class="fa-solid {{ $goodsReceipt ? 'fa-eye' : 'fa-truck-ramp-box' }} me-1"></i> {{ $goodsReceipt ? 'Detail GRPO' : 'Proses GRPO' }}</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-truck-ramp-box"></i>Belum ada Purchase Order atau histori Goods Receipt PO.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($purchaseOrders->hasPages())<div class="panel-body border-top">{{ $purchaseOrders->links() }}</div>@endif
    </section>
@endsection
