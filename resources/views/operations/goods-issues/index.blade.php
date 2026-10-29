@extends('layouts.app')

@section('title', 'Goods Issue')
@section('page-title', 'Goods Issue')
@section('page-subtitle', 'Daftar pengeluaran barang yang dibuat melalui portal.')

@section('page-actions')
    @can('create', \App\Models\SapDocument::class)
        <a class="btn btn-sm btn-primary" href="{{ route('goods-issues.create') }}"><i class="fa-solid fa-plus me-1"></i> Buat Goods Issue</a>
    @endcan
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>
    @endif

    @include('partials.list-filter', [
        'id' => 'goods-issue',
        'action' => route('goods-issues.index'),
        'filters' => $filters,
        'placeholder' => 'Cari nomor GI, FPK, Nopol, item, atau isi dokumen',
        'statusLabel' => 'Approval HO',
        'statusOptions' => ['PENDING' => 'Menunggu Admin HO', 'APPROVED' => 'Disetujui', 'REJECTED' => 'Ditolak'],
    ])

    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Daftar Goods Issue</h2><span class="badge text-bg-light border">{{ $documents->total() }} dokumen</span></header>
        <div class="table-responsive"><table class="table table-hover table-operational"><thead><tr><th>GI Number</th><th>FPK</th><th>Approval HO</th><th>Status</th><th>Integration</th><th>Updated</th><th class="text-end">Action</th></tr></thead><tbody>
        @forelse($documents as $document)
            <tr><td class="mono"><a href="{{ route('documents.show', $document) }}">{{ $document->sap_doc_num ?? '#'.$document->id }}</a></td><td class="mono">{{ $document->fpk_number ?? '—' }}</td><td>{{ str($document->approval?->status ?? data_get($document->normalized_data, 'approval_status', '—'))->title() }}</td><td>{{ str($document->sap_business_status->value)->title() }}</td><td>{{ str($document->integration_status->value)->replace('_', ' ')->title() }}</td><td>{{ $document->updated_at->diffForHumans() }}</td><td class="text-end"><div class="d-flex justify-content-end gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.print', $document) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i><span class="visually-hidden">Cetak</span></a><a class="btn btn-sm btn-outline-primary" href="{{ route('documents.show', $document) }}">Detail</a></div></td></tr>
        @empty
            <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-arrow-right-from-bracket"></i>Belum ada Goods Issue dalam cakupan akses Anda.</div></td></tr>
        @endforelse
        </tbody></table></div>
        @if($documents->hasPages())<div class="panel-body border-top">{{ $documents->links() }}</div>@endif
    </section>
@endsection
