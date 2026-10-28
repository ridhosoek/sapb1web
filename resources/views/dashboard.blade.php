@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard Operasional')
@section('page-subtitle', 'Fokus pada transaksi yang membutuhkan tindakan berikutnya.')

@section('content')
    <div class="row g-3 mb-3">
        @foreach([
            ['Open PR', $kpis['open_pr'], 'Permintaan pembelian aktif'],
            ['Waiting PO', $kpis['waiting_po'], 'Menunggu Purchase Order'],
            ['Ready GRPO', $kpis['ready_grpo'], 'Siap diterima'],
            ['Goods Issue', $kpis['goods_issue_month'], 'Bulan berjalan'],
        ] as [$label, $value, $hint])
            <div class="col-6 col-xl-3">
                <section class="metric-card {{ $label === 'Ready GRPO' ? 'action' : '' }}">
                    <div class="metric-label">{{ $label }}</div>
                    <div class="metric-value">{{ number_format($value) }}</div>
                    <div class="metric-hint">{{ $hint }}</div>
                </section>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <section class="operational-panel h-100">
                <header class="panel-header">
                    <h2 class="panel-title">Action Required</h2>
                    <span class="badge text-bg-light border">{{ $actions->count() }} dokumen</span>
                </header>
                <div class="table-responsive">
                    <table class="table table-hover table-operational" aria-label="Dokumen yang memerlukan tindakan">
                        <thead><tr><th>Document</th><th>FPK</th><th>Status</th><th>Updated</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        @forelse($actions as $document)
                            <tr>
                                <td class="mono"><a href="{{ route('documents.show', $document) }}">{{ $document->sap_doc_num ?? 'PENDING' }}</a></td>
                                <td class="mono">{{ $document->fpk_number ?: '—' }}</td>
                                <td>{{ str($document->normalized_data['progress_status'] ?? 'OPEN')->replace('_', ' ')->title() }}</td>
                                <td>{{ $document->updated_at->diffForHumans() }}</td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('documents.show', $document) }}">Detail</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state"><i class="fa-regular fa-circle-check"></i>Tidak ada tindakan yang menunggu.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="operational-panel mb-3">
                <header class="panel-header">
                    <div><h2 class="panel-title">Report Beban Sparepart</h2><div class="small text-secondary mt-1">Histori item dan biaya per Nopol</div></div>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('sparepart-report.index') }}">Buka Report</a>
                </header>
                <div class="panel-body">
                    <p class="text-secondary mb-3">Periksa pembelian terakhir suatu item dan seluruh beban sparepart kendaraan sebelum membuat permintaan baru.</p>
                    <a class="btn btn-sm btn-primary" href="{{ route('sparepart-report.index') }}"><i class="fa-solid fa-chart-column me-1"></i> Analisa Beban</a>
                </div>
            </section>
            <section class="operational-panel mb-3">
                <header class="panel-header"><h2 class="panel-title">Recent Activity</h2></header>
                <div class="panel-body">
                    @forelse($activity as $entry)
                        <div class="d-flex gap-3 py-2 border-bottom">
                            <time class="mono small text-secondary">{{ $entry->created_at->format('H:i') }}</time>
                            <span>{{ str($entry->event)->replace('_', ' ')->title() }}</span>
                        </div>
                    @empty
                        <div class="empty-state py-4"><i class="fa-regular fa-clock"></i>Belum ada aktivitas.</div>
                    @endforelse
                </div>
            </section>
            <section class="operational-panel">
                <header class="panel-header"><h2 class="panel-title">Item Search Foundation</h2></header>
                <div class="panel-body"><livewire:item-search /></div>
            </section>
        </div>
    </div>
@endsection
