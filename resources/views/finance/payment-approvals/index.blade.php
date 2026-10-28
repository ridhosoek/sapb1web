@extends('layouts.app')

@section('title', 'Approval Payment')
@section('page-title', 'Approval Payment Request')
@section('page-subtitle', 'Review, approve, atau reject Payment Request sesuai level kewenangan.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('payment-requests.index') }}"><i class="fa-solid fa-money-check-dollar me-1"></i> Payment Request</a>
@endsection

@section('content')
    <div class="alert preview-notice py-2" role="status">
        <i class="fa-solid fa-user-shield me-1"></i>
        Approval pada web belum menulis ke <span class="mono-field">@VIT_HISTAPP</span>. Tombol keputusan akan diaktifkan setelah mapping user SAP dan level approval dikonfirmasi.
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Filter Approval Queue</h2><span class="badge text-bg-light border">Level user: belum dipetakan</span></header>
        <form class="panel-body" method="GET" action="{{ route('payment-approvals.index') }}">
            <div class="row g-3 align-items-end">
                <div class="col-md-2"><label class="form-label" for="filter-payment-no">Payment Req No</label><input class="form-control mono-field" id="filter-payment-no" name="payment_request_no" value="{{ request('payment_request_no') }}"></div>
                <div class="col-md-2"><label class="form-label" for="filter-status">Status</label><select class="form-select" id="filter-status" name="status"><option value="">Semua</option>@foreach(['NEW', 'APPROVED', 'REJECTED', 'GENERATED', 'CANCELED'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label" for="filter-type">Type</label><select class="form-select" id="filter-type" name="type"><option value="">Semua</option>@foreach(['VENDOR', 'CUSTOMER', 'ACCOUNT'] as $type)<option value="{{ $type }}" @selected(request('type') === $type)>{{ $type }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label" for="filter-means">Payment Means</label><select class="form-select" id="filter-means" name="payment_means"><option value="">Semua</option>@foreach(['CASH', 'BANK TRANSFER', 'CHECK'] as $means)<option value="{{ $means }}" @selected(request('payment_means') === $means)>{{ $means }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label" for="filter-currency">Currency</label><select class="form-select mono-field" id="filter-currency" name="currency"><option value="">Semua</option>@foreach(['IDR', 'USD', 'SGD'] as $currency)<option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ $currency }}</option>@endforeach</select></div>
                <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button><a class="btn btn-outline-secondary" href="{{ route('payment-approvals.index') }}" aria-label="Reset filter"><i class="fa-solid fa-rotate-left"></i></a></div>
            </div>
        </form>
    </section>

    <section class="operational-panel">
        <header class="panel-header">
            <div><h2 class="panel-title">List Approval Payment</h2><div class="small text-secondary mt-1">Hanya request non-draft dan sesuai company database aktif.</div></div>
            <div class="d-flex gap-2"><button class="btn btn-sm btn-outline-danger" type="button" disabled><i class="fa-solid fa-xmark me-1"></i> Reject</button><button class="btn btn-sm btn-success" type="button" disabled><i class="fa-solid fa-check me-1"></i> Approve</button></div>
        </header>
        <div class="table-responsive">
            <table class="table table-hover table-operational approval-table">
                <thead><tr><th><input class="form-check-input" type="checkbox" disabled aria-label="Pilih semua"></th><th>No Payment Req</th><th>Type</th><th>Payment Means</th><th>BP / Account</th><th>Currency</th><th class="text-end">Invoice Amount</th><th class="text-end">Payment Amount</th><th>Level</th><th>Status</th><th>Remarks</th></tr></thead>
                <tbody>
                @forelse($paymentRequests as $paymentRequest)
                    @php($data = $paymentRequest->normalized_data ?? [])
                    <tr>
                        <td><input class="form-check-input" type="checkbox" disabled aria-label="Pilih {{ $paymentRequest->sap_doc_num }}"></td>
                        <td class="mono"><a href="{{ route('documents.show', $paymentRequest) }}">{{ $paymentRequest->sap_doc_num ?? 'PENDING' }}</a></td>
                        <td>{{ $data['type'] ?? '—' }}</td>
                        <td>{{ $data['payment_means'] ?? '—' }}</td>
                        <td>{{ $data['bp_name'] ?? $data['account_name'] ?? '—' }}</td>
                        <td class="mono">{{ $data['payment_currency'] ?? 'IDR' }}</td>
                        <td class="text-end mono">{{ number_format((float) ($data['invoice_amount'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-end mono">{{ number_format((float) ($data['total_payment'] ?? 0), 2, ',', '.') }}</td>
                        <td>{{ $data['current_approval_level'] ?? 0 }} / {{ $data['required_approval_level'] ?? 0 }}</td>
                        <td><span class="badge payment-status-badge">{{ $data['status'] ?? 'NEW' }}</span></td>
                        <td>{{ $data['remarks'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-file-signature"></i>Tidak ada Payment Request yang menunggu keputusan.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($paymentRequests->hasPages())
            <div class="panel-body border-top d-flex justify-content-between">
                <a class="btn btn-sm btn-outline-secondary {{ $paymentRequests->onFirstPage() ? 'disabled' : '' }}" href="{{ $paymentRequests->previousPageUrl() ?: '#' }}">Sebelumnya</a>
                <span class="small text-secondary">Halaman {{ $paymentRequests->currentPage() }} dari {{ $paymentRequests->lastPage() }}</span>
                <a class="btn btn-sm btn-outline-secondary {{ $paymentRequests->hasMorePages() ? '' : 'disabled' }}" href="{{ $paymentRequests->nextPageUrl() ?: '#' }}">Berikutnya</a>
            </div>
        @endif
    </section>

    <section class="operational-panel mt-3">
        <header class="panel-header"><h2 class="panel-title">Kontrol Approval yang Diusulkan</h2></header>
        <div class="panel-body">
            <div class="row g-3">
                <div class="col-md-4"><div class="control-note"><i class="fa-solid fa-user-lock"></i><div><strong>Segregation of Duties</strong><span>Maker tidak boleh approve atau execute request miliknya sendiri.</span></div></div></div>
                <div class="col-md-4"><div class="control-note"><i class="fa-solid fa-layer-group"></i><div><strong>Sequential Approval</strong><span>Level berikutnya baru aktif setelah level sebelumnya selesai.</span></div></div></div>
                <div class="col-md-4"><div class="control-note"><i class="fa-solid fa-clock-rotate-left"></i><div><strong>Immutable Audit Trail</strong><span>Approve, reject, reapprove, dan execute dicatat tanpa overwrite histori.</span></div></div></div>
            </div>
        </div>
    </section>
@endsection
