@extends('layouts.app')

@section('title', 'Monitoring Stok')
@section('page-title', 'Early Stock Monitoring')
@section('page-subtitle', 'Pantau stok tersedia, komitmen, dan incoming pada warehouse user sebelum menghambat operasional.')

@section('page-actions')
    @can('create', \App\Models\SapDocument::class)
        <a class="btn btn-sm btn-primary" href="{{ route('goods-issues.create') }}"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Buat Goods Issue</a>
    @endcan
@endsection

@section('content')
    @if(!$warehouseCode)
        <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>Warehouse belum dipetakan pada profil Anda. Hubungi administrator agar monitoring stok dapat digunakan.</div>
    @endif

    <div class="alert alert-info py-2">
        <i class="fa-solid fa-warehouse me-1"></i>
        Warehouse <strong class="mono">{{ $warehouseCode ?: '—' }}</strong> · Available = In Stock − Committed · Incoming tidak dihitung sebagai stok siap pakai.
        @if($summary['last_synced_at'])
            <span class="ms-2">Sinkron terakhir {{ \Illuminate\Support\Carbon::parse($summary['last_synced_at'])->diffForHumans() }}.</span>
        @else
            <span class="ms-2 text-danger">Stok belum pernah disinkron.</span>
        @endif
    </div>

    <div class="row g-3 mb-3">
        @foreach([
            ['Item Termonitor', $summary['total'], 'Baris stok warehouse'],
            ['Siap Dipakai', $summary['available'], 'Available Stock > 0'],
            ['Stok Rendah', $summary['low'], 'Perlu perhatian awal'],
            ['Stok Habis', $summary['out'], 'Tidak dapat di-GI'],
            ['Incoming', number_format($summary['ordered'], 2, ',', '.'), 'Qty dalam pemesanan'],
        ] as [$label, $value, $hint])
            <div class="col-6 col-xl">
                <section class="metric-card {{ in_array($label, ['Stok Rendah', 'Stok Habis'], true) && (float) $value > 0 ? 'action' : '' }}">
                    <div class="metric-label">{{ $label }}</div>
                    <div class="metric-value">{{ is_numeric($value) ? number_format((float) $value, 0, ',', '.') : $value }}</div>
                    <div class="metric-hint">{{ $hint }}</div>
                </section>
            </div>
        @endforeach
    </div>

    <section class="operational-panel">
        <header class="panel-header align-items-end">
            <div><h2 class="panel-title">Stok Warehouse {{ $warehouseCode ?: '—' }}</h2><div class="small text-secondary mt-1">Jika minimum stok SAP belum diisi, status rendah memakai ambang fallback {{ number_format($threshold, 2, ',', '.') }}.</div></div>
            <form class="d-flex flex-wrap gap-2" method="GET">
                <input class="form-control form-control-sm" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="ItemCode atau ItemName">
                <select class="form-select form-select-sm" name="status">
                    <option value="">Semua status</option>
                    <option value="OUT" @selected(request('status') === 'OUT')>Stok Habis</option>
                    <option value="LOW" @selected(request('status') === 'LOW')>Stok Rendah</option>
                    <option value="HEALTHY" @selected(request('status') === 'HEALTHY')>Sehat</option>
                </select>
                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>
                @if(request()->hasAny(['q', 'status']))<a class="btn btn-sm btn-outline-secondary" href="{{ route('stock-monitoring.index') }}">Reset</a>@endif
            </form>
        </header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle" aria-label="Monitoring stok warehouse">
                <thead><tr><th>Item No</th><th>Description</th><th class="text-end">In Stock</th><th class="text-end">Committed</th><th class="text-end">Available</th><th class="text-end">Incoming</th><th class="text-end">Minimum</th><th>Status</th><th>Sync</th></tr></thead>
                <tbody>
                @forelse($stocks as $stock)
                    @php($status = $stock->status())
                    <tr>
                        <td class="mono fw-semibold"><a href="{{ route('stock-monitoring.show', $stock) }}">{{ $stock->item->item_code }}</a></td>
                        <td>{{ $stock->item->item_name }} <span class="text-secondary small">{{ $stock->item->inventory_uom ?: '' }}</span></td>
                        <td class="text-end mono">{{ number_format((float) $stock->in_stock, 2, ',', '.') }}</td>
                        <td class="text-end mono">{{ number_format((float) $stock->committed_stock, 2, ',', '.') }}</td>
                        <td class="text-end mono fw-semibold">{{ number_format((float) $stock->available_stock, 2, ',', '.') }}</td>
                        <td class="text-end mono">{{ number_format((float) $stock->ordered_stock, 2, ',', '.') }}</td>
                        <td class="text-end mono">{{ number_format($stock->reorderPoint(), 2, ',', '.') }}{{ (float) $stock->minimum_stock <= 0 ? '*' : '' }}</td>
                        <td><span class="badge {{ $status === 'OUT' ? 'text-bg-danger' : ($status === 'LOW' ? 'text-bg-warning' : 'text-bg-success') }}">{{ $status === 'OUT' ? 'HABIS' : ($status === 'LOW' ? 'RENDAH' : 'SEHAT') }}</span></td>
                        <td class="small text-secondary text-nowrap">{{ $stock->synced_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-boxes-stacked"></i>Tidak ada data stok yang sesuai. Jalankan sinkronisasi item jika warehouse baru dipetakan.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($stocks->hasPages())<div class="panel-body border-top">{{ $stocks->links() }}</div>@endif
    </section>
@endsection
