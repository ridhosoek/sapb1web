@extends('layouts.app')

@section('title', 'Report Beban Sparepart')
@section('page-title', 'Report Beban Sparepart')
@section('page-subtitle', 'Analisa histori pembelian dan pengeluaran sparepart berdasarkan item maupun Nopol.')

@section('page-actions')
    @can('create', \App\Models\SapDocument::class)
        <a class="btn btn-sm btn-primary" href="{{ route('purchase-requests.create') }}"><i class="fa-solid fa-file-circle-plus me-1"></i> Buat Purchase Request</a>
    @endcan
@endsection

@section('content')
    @if($connectionError)
        @include('partials.data-load-error', ['message' => $connectionError])
    @endif

    @foreach($warnings as $warning)
        <div class="alert alert-warning py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ $warning }}</div>
    @endforeach

    <div class="alert alert-info py-2">
        <i class="fa-solid fa-circle-info me-1"></i>
        Sumber: Goods Issue, A/P Invoice, dan Payment Request berstatus GENERATED pada database <strong class="mono">{{ $companyDb }}</strong>. Dokumen cancel tidak dihitung.
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Filter Report</h2><span class="small text-secondary">Maksimal {{ number_format(config('sap.sparepart_report.limit', 250)) }} transaksi terbaru</span></header>
        <div class="panel-body">
            <form class="row g-3 align-items-end" method="GET" action="{{ route('sparepart-report.index') }}">
                <div class="col-sm-6 col-xl-2">
                    <label class="form-label" for="report-date-from">Dari Tanggal</label>
                    <input class="form-control form-control-sm mono-field" id="report-date-from" name="date_from" type="date" value="{{ $filters['date_from'] }}" required>
                </div>
                <div class="col-sm-6 col-xl-2">
                    <label class="form-label" for="report-date-to">Sampai Tanggal</label>
                    <input class="form-control form-control-sm mono-field" id="report-date-to" name="date_to" type="date" max="{{ now()->toDateString() }}" value="{{ $filters['date_to'] }}" required>
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="report-item-code">Item Code</label>
                    <input class="form-control form-control-sm mono-field" id="report-item-code" name="item_code" value="{{ $filters['item_code'] ?? '' }}" maxlength="50" placeholder="Kosongkan untuk semua item">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="report-nopol">Nopol</label>
                    <select class="form-select form-select-sm mono-field" id="report-nopol" name="nopol">
                        <option value="">Semua Nopol</option>
                        @foreach($vehicles as $vehicle)
                            <option value="{{ $vehicle->center_code }}" @selected(($filters['nopol'] ?? '') === $vehicle->center_code)>{{ $vehicle->center_code }}{{ $vehicle->center_name !== $vehicle->center_code ? ' — '.$vehicle->center_name : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 d-flex gap-2">
                    <button class="btn btn-sm btn-primary flex-grow-1" type="submit"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('sparepart-report.index') }}">Reset</a>
                </div>
            </form>
        </div>
    </section>

    <div class="row g-3 mb-3">
        @foreach([
            ['Transaksi', $summary['transactions'], 'Baris sesuai filter'],
            ['Total Beban', 'Rp '.number_format((float) $summary['amount'], 0, ',', '.'), 'Nilai dalam mata uang lokal'],
            ['Item', $summary['items'], 'Item unik'],
            ['Nopol', $summary['vehicles'], 'Kendaraan unik'],
            ['Data Terakhir', $summary['latest_date'] ? \Illuminate\Support\Carbon::parse($summary['latest_date'])->format('d/m/Y') : '—', 'Dibaca '.$generatedAt->format('d/m/Y H:i')],
        ] as [$label, $value, $hint])
            <div class="col-6 col-xl">
                <section class="metric-card">
                    <div class="metric-label">{{ $label }}</div>
                    <div class="metric-value {{ $label === 'Total Beban' ? 'fs-5' : '' }}">{{ is_numeric($value) ? number_format((float) $value, 0, ',', '.') : $value }}</div>
                    <div class="metric-hint">{{ $hint }}</div>
                </section>
            </div>
        @endforeach
    </div>

    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Detail Beban Sparepart</h2><span class="badge text-bg-light border">{{ $rows->count() }} baris</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle" aria-label="Report beban sparepart">
                <thead>
                <tr><th>Tanggal</th><th>Transaksi</th><th>Dokumen / FPK</th><th>Item / Keterangan</th><th>Warehouse</th><th>Nopol</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Beban</th><th>KM</th><th>No. Seri Ban</th></tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td class="text-nowrap">{{ filled($row['date']) ? \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') : '—' }}</td>
                        <td><span class="badge {{ $row['transaction'] === 'A/P Invoice' ? 'text-bg-primary' : ($row['transaction'] === 'Goods Issue' ? 'text-bg-secondary' : 'text-bg-warning') }}">{{ $row['transaction'] }}</span></td>
                        <td><div class="mono fw-semibold">{{ $row['document_number'] ?: '—' }}</div><div class="small text-secondary">FPK {{ $row['fpk'] ?: '—' }}</div></td>
                        <td><div class="mono fw-semibold">{{ $row['item_code'] ?: 'NON-ITEM' }}</div><div>{{ $row['description'] ?: '—' }}</div></td>
                        <td><div class="mono">{{ $row['warehouse_code'] ?: '—' }}</div><div class="small text-secondary">{{ $row['warehouse_name'] ?: '' }}</div></td>
                        <td class="mono fw-semibold">{{ $row['vehicle'] ?: '—' }}</td>
                        <td class="text-end mono">{{ $row['quantity'] ? number_format((float) $row['quantity'], 2, ',', '.') : '—' }}</td>
                        <td class="text-end mono">{{ $row['unit_price'] ? number_format((float) $row['unit_price'], 0, ',', '.') : '—' }}</td>
                        <td class="text-end mono fw-semibold">{{ number_format((float) $row['amount'], 0, ',', '.') }}</td>
                        <td class="mono text-nowrap">{{ $row['km_start'] !== null ? number_format((float) $row['km_start'], 0, ',', '.') : '—' }} → {{ $row['km_end'] !== null ? number_format((float) $row['km_end'], 0, ',', '.') : '—' }}</td>
                        <td class="mono small">{{ count($row['serial_numbers']) ? implode(', ', $row['serial_numbers']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-chart-column"></i>Tidak ada transaksi beban sparepart pada filter ini.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
