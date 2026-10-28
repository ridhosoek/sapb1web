@extends('layouts.app')

@section('title', 'Payment Request')
@section('page-title', 'Payment Request')
@section('page-subtitle', 'Daftar dan status Payment Request sebelum terbentuk Outgoing Payment SAP.')

@section('page-actions')
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('payment-approvals.index') }}"><i class="fa-solid fa-file-signature me-1"></i> Approval Queue</a>
        <a class="btn btn-sm btn-primary" href="{{ route('payment-requests.create') }}"><i class="fa-solid fa-plus me-1"></i> Buat Payment Request</a>
    </div>
@endsection

@section('content')
    <div class="alert preview-notice py-2" role="status"><i class="fa-solid fa-shield-halved me-1"></i>Mode rancangan terkontrol: penyimpanan UDO dan pembuatan Outgoing Payment belum diaktifkan sampai metadata Service Layer dikonfirmasi.</div>

    <div class="row g-3 mb-3">
        @foreach([
            ['label' => 'Draft', 'value' => $statusSummary['DRAFT'], 'hint' => 'Masih dapat diedit', 'class' => 'muted'],
            ['label' => 'Waiting Approval', 'value' => $statusSummary['NEW'], 'hint' => 'Approval bertingkat', 'class' => 'warning'],
            ['label' => 'Ready to Execute', 'value' => $statusSummary['APPROVED'], 'hint' => 'Siap dibuat Outgoing', 'class' => 'info'],
            ['label' => 'Generated', 'value' => $statusSummary['GENERATED'], 'hint' => 'Outgoing Payment terbentuk', 'class' => 'success'],
        ] as $metric)
            <div class="col-sm-6 col-xl-3"><div class="metric-card compact payment-metric {{ $metric['class'] }}"><div class="metric-label">{{ $metric['label'] }}</div><div class="metric-value">{{ $metric['value'] }}</div><div class="metric-hint">{{ $metric['hint'] }}</div></div></div>
        @endforeach
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Business Flow yang Dikendalikan</h2><span class="badge text-bg-light border">Maker · Approver · Treasury</span></header>
        <div class="panel-body"><div class="payment-flow" aria-label="Alur Payment Request">
            @foreach([
                ['icon' => 'fa-pen-to-square', 'title' => '1. Draft', 'text' => 'Maker memilih BP dan invoice/account.'],
                ['icon' => 'fa-paper-plane', 'title' => '2. Submit', 'text' => 'Nilai dan detail dibekukan sebagai snapshot.'],
                ['icon' => 'fa-user-check', 'title' => '3. Approval', 'text' => 'Persetujuan berjenjang sesuai limit.'],
                ['icon' => 'fa-building-columns', 'title' => '4. Execute', 'text' => 'Treasury melengkapi bank dan posting date.'],
                ['icon' => 'fa-circle-check', 'title' => '5. Generated', 'text' => 'Outgoing Payment dibuat satu kali di SAP.'],
            ] as $index => $step)
                @if($index > 0)<i class="fa-solid fa-chevron-right payment-flow-arrow"></i>@endif
                <div class="payment-flow-step"><span class="payment-flow-icon"><i class="fa-solid {{ $step['icon'] }}"></i></span><strong>{{ $step['title'] }}</strong><small>{{ $step['text'] }}</small></div>
            @endforeach
        </div></div>
    </section>

    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Daftar Payment Request</h2><span class="badge text-bg-light border">{{ $paymentRequests->count() }} dokumen</span></header>
        <div class="table-responsive"><table class="table table-hover table-operational"><thead><tr><th>Payment Req</th><th>Type</th><th>BP / Account</th><th>Status</th><th>Approval</th><th class="text-end">Payment Amount</th><th>Outgoing No</th><th>Updated</th><th class="text-end">Action</th></tr></thead><tbody>
        @forelse($paymentRequests as $paymentRequest)
            @php($data = $paymentRequest->normalized_data ?? [])
            <tr><td class="mono"><a href="{{ route('documents.show', $paymentRequest) }}">{{ $paymentRequest->sap_doc_num ?? 'PENDING' }}</a></td><td>{{ $data['type'] ?? '—' }}</td><td>{{ $data['bp_name'] ?? $data['account_name'] ?? '—' }}</td><td><span class="badge payment-status-badge">{{ $data['status'] ?? 'DRAFT' }}</span></td><td>{{ $data['current_approval_level'] ?? 0 }} / {{ $data['required_approval_level'] ?? 0 }}</td><td class="text-end mono">{{ number_format((float) ($data['total_payment'] ?? 0), 2, ',', '.') }}</td><td class="mono">{{ $data['outgoing_payment_no'] ?? '—' }}</td><td>{{ $paymentRequest->updated_at->diffForHumans() }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('documents.show', $paymentRequest) }}">Detail</a></td></tr>
        @empty
            <tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-money-check-dollar"></i>Belum ada Payment Request yang tersinkron dari SAP.</div></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
@endsection
