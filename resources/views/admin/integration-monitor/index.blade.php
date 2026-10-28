@extends('layouts.app')

@section('title', 'Integration Monitor')
@section('page-title', 'Integration Monitor')
@section('page-subtitle', 'Kondisi queue, watcher, dan komunikasi SAP Service Layer.')

@section('content')
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
