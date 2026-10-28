@extends('layouts.app')

@section('title', 'Stock Ledger')
@section('page-title', 'Stock Ledger')
@section('page-subtitle', $stock->item->item_code.' — '.$stock->item->item_name)

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('stock-monitoring.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Monitoring</a>
@endsection

@section('content')
    @if($ledgerError)
        <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ $ledgerError }}</div>
    @endif

    <div class="alert alert-info py-2">
        <i class="fa-solid fa-circle-info me-1"></i>
        Pergerakan terbaru dibaca langsung dari dokumen stok SAP Service Layer untuk warehouse <strong class="mono">{{ $stock->warehouse_code }}</strong>. Dokumen yang dibatalkan tidak dihitung.
    </div>

    <div class="row g-3 mb-3">
        @foreach([
            ['In Stock', $stock->in_stock, 'Stok fisik SAP'],
            ['Committed', $stock->committed_stock, 'Sudah dialokasikan'],
            ['Available', $stock->available_stock, 'Siap dipakai'],
            ['Incoming', $stock->ordered_stock, 'Dalam pemesanan'],
            ['Minimum', $stock->reorderPoint(), (float) $stock->minimum_stock > 0 ? 'Minimum SAP' : 'Ambang fallback'],
        ] as [$label, $value, $hint])
            <div class="col-6 col-xl">
                <section class="metric-card">
                    <div class="metric-label">{{ $label }}</div>
                    <div class="metric-value">{{ number_format((float) $value, 2, ',', '.') }}</div>
                    <div class="metric-hint">{{ $hint }} · {{ $stock->item->inventory_uom ?: 'UoM —' }}</div>
                </section>
            </div>
        @endforeach
    </div>

    <section class="operational-panel">
        <header class="panel-header">
            <div>
                <h2 class="panel-title">Pergerakan Stok Terbaru</h2>
                <div class="small text-secondary mt-1">Maksimal {{ config('sap.stock.ledger_limit', 100) }} transaksi terbaru dari GRPO, Goods Receipt, Goods Issue, Delivery, Return, Goods Return, dan Stock Transfer.</div>
            </div>
            <span class="badge text-bg-light border">{{ count($movements) }} transaksi</span>
        </header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle" aria-label="Stock ledger item {{ $stock->item->item_code }}">
                <thead>
                    <tr><th>Tanggal</th><th>Dokumen</th><th>Nomor SAP</th><th>Referensi</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                @forelse($movements as $movement)
                    <tr>
                        <td class="text-nowrap">{{ filled($movement['date']) ? \Illuminate\Support\Carbon::parse($movement['date'])->format('d/m/Y') : '—' }}</td>
                        <td><span class="badge {{ $movement['quantity_in'] > 0 ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $movement['document_type'] }}</span></td>
                        <td class="mono">{{ $movement['document_number'] ?: '—' }}</td>
                        <td class="mono">{{ $movement['reference'] ?: '—' }}</td>
                        <td class="text-end mono text-success fw-semibold">{{ $movement['quantity_in'] > 0 ? number_format($movement['quantity_in'], 2, ',', '.') : '—' }}</td>
                        <td class="text-end mono fw-semibold">{{ $movement['quantity_out'] > 0 ? number_format($movement['quantity_out'], 2, ',', '.') : '—' }}</td>
                        <td title="{{ $movement['remarks'] }}">{{ $movement['remarks'] ? \Illuminate\Support\Str::limit($movement['remarks'], 80) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i>Belum ada pergerakan dokumen yang ditemukan untuk item dan warehouse ini.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
