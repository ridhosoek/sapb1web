@extends('layouts.app')

@section('title', 'Integration Monitor')
@section('page-title', 'Integration Monitor')
@section('page-subtitle', 'Kondisi queue, watcher, dan komunikasi SAP Service Layer.')

@section('content')
    @include('partials.list-filter', [
        'id' => 'integration-monitor',
        'action' => route('admin.integration-monitor'),
        'filters' => $filters,
        'placeholder' => 'Cari endpoint, method, atau pesan error SAP',
        'statusLabel' => 'Hasil API',
        'statusOptions' => ['SUCCESS' => 'Berhasil', 'ERROR' => 'Error'],
    ])

    @if($uncertainReceipts->isNotEmpty())
        <section class="operational-panel mb-3" data-uncertain-goods-receipts>
            <header class="panel-header"><h2 class="panel-title">GRPO dengan hasil belum pasti</h2><span class="badge text-bg-danger">{{ $uncertainReceipts->count() }} command</span></header>
            <div class="panel-body">
                <p class="small text-secondary">PO terkait tidak dapat menerima barang baru selama command di bawah belum selesai. Sistem mencocokkan UUID dengan SAP secara berkala. Waktu yang berlalu atau hasil pencarian kosong <strong>tidak</strong> membuktikan GRPO tidak tersimpan. Lepaskan hanya setelah investigasi otoritatif (mis. pemeriksaan langsung di SAP oleh tim SAP) membuktikan tidak ada GRPO dari command ini.</p>
                @if($errors->any())
                    <div class="alert alert-danger py-2" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                @foreach($uncertainReceipts as $uncertain)
                    <div class="border rounded p-2 mb-2">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="small"><span class="mono">{{ $uncertain->transaction_uuid }}</span> · PO DocEntry <span class="mono">{{ $uncertain->base_document_entry }}</span> · {{ $uncertain->status->value }} · percobaan {{ $uncertain->attempts }} · {{ $uncertain->last_attempt_at?->format('d/m/Y H:i') ?? '—' }}</div>
                        </div>
                        <div class="small text-secondary mb-2">{{ $uncertain->last_error }}</div>
                        <form method="POST" action="{{ route('admin.goods-receipt-commands.release', $uncertain) }}" class="row g-2 align-items-start">
                            @csrf
                            <div class="col-lg-7"><textarea class="form-control form-control-sm" name="evidence" rows="2" minlength="20" maxlength="1000" placeholder="Bukti investigasi otoritatif: siapa yang memeriksa, di mana, hasilnya" required></textarea></div>
                            <div class="col-lg-3 small"><label class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" required> <span class="form-check-label">Investigasi membuktikan GRPO tidak tersimpan di SAP</span></label></div>
                            <div class="col-lg-2"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Lepas command</button></div>
                        </form>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="row g-3 mb-3">
        @foreach([
            ['Pending Jobs', $metrics['pending_jobs'], 'Menunggu worker'],
            ['Failed Jobs', $metrics['failed_jobs'], 'Perlu pemeriksaan'],
            ['Active Watchers', $metrics['active_watchers'], 'Dokumen dipantau'],
            ['SAP Errors', $metrics['sap_errors_today'], 'Hari ini'],
            ['Avg Response', $metrics['average_response_ms'].' ms', 'Service Layer'],
            ['Last Success', $metrics['last_success']?->diffForHumans() ?? 'Belum ada', 'Request berhasil terakhir'],
        ] as [$label, $value, $hint])
            <div class="col-6 col-xl-2">
                <section class="metric-card compact">
                    <div class="metric-label">{{ $label }}</div><div class="metric-value">{{ $value }}</div><div class="metric-hint">{{ $hint }}</div>
                </section>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <section class="operational-panel">
                <header class="panel-header"><h2 class="panel-title">SAP API Log Terbaru</h2><span class="badge text-bg-light border">{{ $logs->count() }} entries</span></header>
                <div class="table-responsive"><table class="table table-operational"><thead><tr><th>Time</th><th>Result</th><th>Method</th><th>Endpoint</th><th>Status</th><th>Duration</th><th>Error SAP</th></tr></thead><tbody>
                @forelse($logs as $log)
                    <tr><td class="mono">{{ $log->created_at->format('d/m H:i:s') }}</td><td><span class="status-dot {{ $log->result === 'SUCCESS' ? 'success' : 'error' }}"></span>{{ $log->result }}</td><td class="mono">{{ $log->method }}</td><td class="mono">{{ $log->endpoint }}</td><td>{{ $log->response_status ?? '—' }}</td><td>{{ $log->duration_ms !== null ? $log->duration_ms.' ms' : '—' }}</td><td class="small" title="{{ $log->error_message }}">{{ $log->error_message ? Str::limit($log->error_message, 90) : '—' }}</td></tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-plug-circle-check"></i>Belum ada komunikasi dengan SAP Service Layer.</div></td></tr>
                @endforelse
                </tbody></table></div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="operational-panel">
                <header class="panel-header"><h2 class="panel-title">Document Watchers</h2></header>
                <div class="panel-body">
                @forelse($watchers as $watcher)
                    <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
                        <div><div class="mono small"><a href="{{ route('documents.show', $watcher->document) }}">{{ $watcher->document->sap_doc_num ?? $watcher->document->transaction_uuid ?? 'PENDING' }}</a></div><div class="small text-secondary">{{ str($watcher->document->document_type)->replace('_', ' ')->title() }}</div></div>
                        <div class="text-end"><div>{{ str($watcher->watch_status->value)->title() }}</div><div class="small text-secondary">{{ $watcher->last_polled_at?->diffForHumans() ?? 'Belum dipolling' }}</div></div>
                    </div>
                @empty
                    <div class="empty-state py-4"><i class="fa-regular fa-eye"></i>Belum ada watcher aktif.</div>
                @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
