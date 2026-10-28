@extends('layouts.app')

@section('title', 'Purchase Request')
@section('page-title', 'Purchase Request')
@section('page-subtitle', 'Daftar Purchase Request sesuai cakupan Pool dan database SAP yang sedang dipilih.')

@section('page-actions')
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase-progress.index') }}"><i class="fa-solid fa-route me-1"></i> Lihat Progress</a>
        @can('create', \App\Models\SapDocument::class)
            <a class="btn btn-sm btn-primary" href="{{ route('purchase-requests.create') }}"><i class="fa-solid fa-plus me-1"></i> Buat Purchase Request</a>
        @endcan
    </div>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>
    @endif

    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Daftar Purchase Request</h2><span class="badge text-bg-light border">{{ $documents->total() }} dokumen</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational">
                <thead><tr><th>PR Number</th><th>Nopol</th><th>Approval</th><th>Progress</th><th>Integration</th><th>Updated</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($documents as $document)
                    <tr><td class="mono"><a href="{{ route('documents.show', $document) }}">{{ $document->sap_doc_num ?? '#'.$document->id }}</a></td><td class="mono">{{ data_get($document->normalized_data, 'vehicle_cost_center.center_code', '—') }}</td><td>{{ str($document->approval?->status ?? data_get($document->normalized_data, 'approval_status', '—'))->title() }}</td><td>{{ str($document->normalized_data['progress_status'] ?? $document->sap_business_status->value)->replace('_', ' ')->title() }}</td><td>{{ str($document->integration_status->value)->replace('_', ' ')->title() }}</td><td>{{ $document->updated_at->diffForHumans() }}</td><td class="text-end"><div class="d-flex justify-content-end gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.print', $document) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i><span class="visually-hidden">Cetak</span></a><a class="btn btn-sm btn-outline-primary" href="{{ route('documents.show', $document) }}">Detail</a></div></td></tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-regular fa-file-lines"></i>Belum ada Purchase Request dalam cakupan akses Anda.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($documents->hasPages())<div class="panel-body border-top">{{ $documents->links() }}</div>@endif
    </section>
@endsection
