@extends('layouts.app')

@section('title', 'Purchase Progress')
@section('page-title', 'Purchase Progress')
@section('page-subtitle', 'Pantau lifecycle Purchase Request hingga penerimaan barang.')

@section('content')
    @if($errors->has('sap'))
        <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ $errors->first('sap') }}</div>
    @endif
    @include('partials.list-filter', [
        'id' => 'purchase-progress',
        'action' => route('purchase-progress.index'),
        'filters' => $filters,
        'placeholder' => 'Cari nomor PR, FPK, Nopol, atau isi dokumen',
        'statusLabel' => 'Status Integrasi',
        'statusOptions' => collect($integrationStatuses)->mapWithKeys(fn($status) => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()])->all(),
    ])
    <section class="operational-panel">
        <header class="panel-header">
            <h2 class="panel-title">Dokumen Pembelian</h2>
            <span class="badge text-bg-light border">{{ $documents->total() }} Purchase Request</span>
        </header>
        <div class="table-responsive">
            <table class="table table-hover table-operational" aria-label="Progress pembelian">
                <thead><tr><th>PR Number</th><th>FPK</th><th>Progress</th><th>Integration</th><th>Watcher</th><th>Updated</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($documents as $document)
                    @php
                        $progress = $document->normalized_data['progress_status'] ?? 'PR_CREATED';
                        $quotationRelations = $document->childRelations
                            ->filter(fn ($relation) => $relation->relation_type === 'PURCHASE_QUOTATION'
                                && $relation->childDocument !== null
                                && $relation->childDocument->sap_business_status->value !== 'CANCELLED');
                        $hasPurchaseQuotation = $quotationRelations->isNotEmpty();
                        $purchaseOrderRelations = $quotationRelations
                            ->flatMap(fn ($relation) => $relation->childDocument->childRelations)
                            ->filter(fn ($purchaseOrderRelation) => $purchaseOrderRelation->relation_type === 'PURCHASE_ORDER'
                                && $purchaseOrderRelation->childDocument?->sap_business_status?->value !== 'CANCELLED');
                        $hasPurchaseOrder = $purchaseOrderRelations->isNotEmpty();
                        $hasGoodsReceipt = $purchaseOrderRelations->contains(fn ($purchaseOrderRelation) => $purchaseOrderRelation->childDocument->childRelations
                            ->contains(fn ($goodsReceiptRelation) => $goodsReceiptRelation->relation_type === 'GOODS_RECEIPT_PO'
                                && $goodsReceiptRelation->childDocument?->sap_business_status?->value !== 'CANCELLED'));

                        if ($hasGoodsReceipt) {
                            $progress = 'FULLY_RECEIVED';
                        }

                        if ($document->created_from_web && $progress === 'WAITING_PO' && ! $hasPurchaseQuotation) {
                            $progress = 'WAITING_QUOTATION';
                        }

                        if (in_array($progress, ['PO_CREATED', 'READY_FOR_GRPO'], true) && ! $hasPurchaseOrder) {
                            $progress = $hasPurchaseQuotation ? 'WAITING_PO' : 'WAITING_QUOTATION';
                        }

                        $completedSteps = match ($progress) {
                            'PR_CREATED', 'WAITING_QUOTATION' => 1,
                            'QUOTATION_CREATED', 'WAITING_PO' => 2,
                            'PO_CREATED', 'READY_FOR_GRPO', 'PARTIALLY_RECEIVED' => 3,
                            'FULLY_RECEIVED', 'CLOSED' => 4,
                            default => 0,
                        };
                    @endphp
                    <tr>
                        <td class="mono"><a href="{{ route('documents.show', $document) }}">{{ $document->sap_doc_num ?? 'PENDING' }}</a></td>
                        <td class="mono">{{ $document->fpk_number ?? '—' }}</td>
                        <td>
                            <div class="progress-steps" aria-label="{{ str($progress)->replace('_', ' ')->title() }}">
                                @foreach(['PR', 'Quotation', 'PO', 'GRPO'] as $index => $step)
                                    @php
                                        $stepState = $index < $completedSteps ? 'done' : ($index === $completedSteps ? 'current' : 'pending');
                                    @endphp
                                    @if($index > 0)<i class="fa-solid fa-chevron-right progress-separator"></i>@endif
                                    <span class="progress-step {{ $stepState }}" data-progress-step="{{ str($step)->slug() }}" data-progress-state="{{ $stepState }}">
                                        <i class="fa-{{ $stepState === 'done' ? 'solid fa-circle-check' : 'regular fa-circle' }} me-1"></i>{{ $step }}
                                    </span>
                                @endforeach
                            </div>
                            <div class="small text-secondary mt-1">{{ str($progress)->replace('_', ' ')->title() }}</div>
                        </td>
                        <td>{{ str($document->integration_status->value)->replace('_', ' ')->title() }}</td>
                        <td>{{ $document->watcher ? str($document->watcher->watch_status->value)->title() : 'Belum terdaftar' }}</td>
                        <td>{{ $document->updated_at->diffForHumans() }}</td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                @if(! $hasGoodsReceipt)
                                    <form method="POST" action="{{ route('purchase-progress.sync', $document) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit" title="Periksa Purchase Quotation terbaru di SAP">
                                            <i class="fa-solid fa-rotate"></i> Sync SAP
                                        </button>
                                    </form>
                                @endif
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('documents.show', $document) }}">Detail</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-route"></i>Belum ada Purchase Request yang dapat dipantau.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($documents->hasPages())
            <div class="panel-body border-top d-flex justify-content-between">
                <a class="btn btn-sm btn-outline-secondary {{ $documents->onFirstPage() ? 'disabled' : '' }}" href="{{ $documents->previousPageUrl() ?: '#' }}">Sebelumnya</a>
                <span class="small text-secondary">Halaman {{ $documents->currentPage() }} dari {{ $documents->lastPage() }}</span>
                <a class="btn btn-sm btn-outline-secondary {{ $documents->hasMorePages() ? '' : 'disabled' }}" href="{{ $documents->nextPageUrl() ?: '#' }}">Berikutnya</a>
            </div>
        @endif
    </section>
@endsection
