@extends('layouts.app')

@section('title', 'Goods Receipt PO')
@section('page-title', 'Goods Receipt PO')
@section('page-subtitle', 'Purchase Order terbuka yang siap diproses menjadi Goods Receipt PO.')

@section('content')
    <div class="alert preview-notice py-2" role="status">
        <i class="fa-solid fa-circle-info me-1"></i> Daftar menampilkan PO terbuka sesuai cakupan Pool role Anda. Penerimaan ke SAP belum diaktifkan dalam mode uji antarmuka.
    </div>
    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Daftar Goods Receipt PO</h2><span class="badge text-bg-light border">{{ $purchaseOrders->total() }} siap diterima</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational" aria-label="Purchase Order siap diterima">
                <thead><tr><th>PO</th><th>FPK</th><th>Vendor</th><th>Nopol</th><th>Open Lines</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($purchaseOrders as $purchaseOrder)
                    <tr>
                        <td class="mono"><a href="{{ route('documents.show', $purchaseOrder) }}">{{ $purchaseOrder->sap_doc_num ?? 'PENDING' }}</a></td>
                        <td class="mono">{{ $purchaseOrder->fpk_number ?? '—' }}</td>
                        <td>{{ $purchaseOrder->normalized_data['vendor_name'] ?? '—' }}</td>
                        <td class="mono">{{ $purchaseOrder->normalized_data['vehicle'] ?? '—' }}</td>
                        <td>{{ $purchaseOrder->normalized_data['open_lines'] ?? '—' }}</td>
                        <td>{{ str($purchaseOrder->normalized_data['progress_status'] ?? 'READY_FOR_GRPO')->replace('_', ' ')->title() }}</td>
                        <td class="text-end"><div class="d-flex justify-content-end gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.print', $purchaseOrder) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i><span class="visually-hidden">Cetak</span></a><a class="btn btn-sm btn-outline-primary" href="{{ route('ready-to-receive.show', $purchaseOrder) }}"><i class="fa-regular fa-images me-1"></i> Dokumentasi</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-truck-ramp-box"></i>Belum ada Purchase Order terbuka yang siap diterima.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
