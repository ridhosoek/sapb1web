@extends('layouts.app')

@section('title', 'Approval Goods Issue')
@section('page-title', 'Approval Goods Issue')
@section('page-subtitle', 'Periksa pengeluaran barang yang ditugaskan kepada Anda sebelum transaksi dibuat di SAP.')

@section('content')
    <div class="alert alert-info py-2">
        Approval Goods Issue dilakukan di portal ini tanpa email. Buka detail untuk memeriksa FPK, Nopol, KM, item, qty, stok, dan tipe pengeluaran sebelum memberi keputusan.
    </div>

    <section class="operational-panel">
        <header class="panel-header">
            <h2 class="panel-title">Menunggu Keputusan Saya</h2>
            <span class="badge text-bg-light border">{{ $steps->total() }} pengajuan</span>
        </header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle">
                <thead><tr><th>Dokumen</th><th>FPK</th><th>Pengaju</th><th>Pool / Department</th><th>Nopol</th><th>Diajukan</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($steps as $step)
                    @php($document = $step->approval->document)
                    <tr>
                        <td class="mono fw-semibold">Goods Issue #{{ $document->id }}</td>
                        <td class="mono">{{ $document->fpk_number ?: data_get($document->normalized_data, 'fpk_number', '—') }}</td>
                        <td>{{ $step->approval->submitter->name }}</td>
                        <td><span class="mono">{{ $document->pool?->code ?? '—' }}</span> / <span class="mono">{{ $document->department?->code ?? '—' }}</span></td>
                        <td class="mono">{{ data_get($document->normalized_data, 'vehicle_cost_center.center_code', '—') }}</td>
                        <td>{{ $step->approval->submitted_at->diffForHumans() }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-primary" href="{{ route('documents.show', $document) }}"><i class="fa-solid fa-magnifying-glass me-1"></i> Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-regular fa-circle-check"></i>Tidak ada Goods Issue yang menunggu keputusan Anda.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($steps->hasPages())<div class="panel-body border-top">{{ $steps->links() }}</div>@endif
    </section>
@endsection
