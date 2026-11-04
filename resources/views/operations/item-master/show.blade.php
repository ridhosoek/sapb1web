@extends('layouts.app')

@section('title', 'Detail Item '.$item->item_code)
@section('page-title', 'Detail Item Master')
@section('page-subtitle', $item->item_code.' · '.$item->item_name)

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('item-master.index', ['q' => $item->item_code]) }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Pencarian</a>
@endsection

@section('content')
    <div class="row g-3 mb-3">
        <div class="col-xl-7">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Identitas Item</h2><span class="badge {{ $item->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $item->is_active ? 'Aktif' : 'Nonaktif' }}</span></header>
                <div class="table-responsive"><table class="table table-operational mb-0"><tbody>
                    <tr><th style="width: 35%">Item Code</th><td class="mono fw-semibold">{{ $item->item_code }}</td></tr>
                    <tr><th>Nama Item</th><td>{{ $item->item_name }}</td></tr>
                    <tr><th>Item Group</th><td>{{ $item->item_group_name ?: '—' }} @if($item->item_group_code)<span class="mono text-secondary">(#{{ $item->item_group_code }})</span>@endif</td></tr>
                    <tr><th>Inventory UoM</th><td class="mono">{{ $item->inventory_uom ?: '—' }}</td></tr>
                    <tr><th>Terakhir Sinkron</th><td>{{ $item->synced_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>
                </tbody></table></div>
            </section>
        </div>
        <div class="col-xl-5">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Pengaturan SAP</h2></header>
                <div class="table-responsive"><table class="table table-operational mb-0"><tbody>
                    <tr><th>Inventory Item</th><td>{{ $item->is_inventory_item ? 'Ya' : 'Tidak' }}</td></tr>
                    <tr><th>Purchase Item</th><td>{{ $item->is_purchase_item ? 'Ya' : 'Tidak' }}</td></tr>
                    <tr><th>Manage Serial Numbers</th><td>{{ $item->manages_serial_numbers ? 'Ya' : 'Tidak' }}</td></tr>
                    <tr><th>Management Method</th><td class="mono">{{ $item->serial_management_method ?: '—' }}</td></tr>
                    <tr><th>Serial wajib saat GRPO</th><td>{{ $item->requiresSerialNumbersOnReceipt() ? 'Ya' : 'Tidak' }}</td></tr>
                </tbody></table></div>
            </section>
        </div>
    </div>

    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Stok per Warehouse</h2><span class="badge text-bg-light border">{{ $item->warehouseStocks->count() }} warehouse</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle mb-0">
                <thead><tr><th>Warehouse</th><th class="text-end">In Stock</th><th class="text-end">Committed</th><th class="text-end">Available</th><th class="text-end">Ordered</th><th class="text-end">Minimum</th><th>Status</th><th>Last Sync</th></tr></thead>
                <tbody>
                @forelse($item->warehouseStocks as $stock)
                    <tr><td class="mono fw-semibold">{{ $stock->warehouse_code }}</td><td class="text-end mono">{{ number_format($stock->in_stock, 2, ',', '.') }}</td><td class="text-end mono">{{ number_format($stock->committed_stock, 2, ',', '.') }}</td><td class="text-end mono">{{ number_format($stock->available_stock, 2, ',', '.') }}</td><td class="text-end mono">{{ number_format($stock->ordered_stock, 2, ',', '.') }}</td><td class="text-end mono">{{ number_format($stock->minimum_stock, 2, ',', '.') }}</td><td>{{ $stock->is_locked ? 'Locked' : 'Aktif' }}</td><td>{{ $stock->synced_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><i class="fa-regular fa-folder-open"></i>Belum ada cache stok warehouse untuk item ini.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
