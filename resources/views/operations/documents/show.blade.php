@extends('layouts.app')

@php
    $typeLabel = str($document->document_type)->replace('_', ' ')->title();
    $fieldLabels = [
        'required_date' => 'Tanggal Kebutuhan',
        'priority' => 'Prioritas',
        'notes' => 'Remarks',
        'progress_status' => 'Progress',
        'fpk_number' => 'Nomor FPK',
        'fpk_date' => 'Tanggal FPK',
        'vehicle' => 'Nopol',
        'vehicle_cost_center' => 'Nopol / Cost Center',
        'issue_type' => 'Tipe Pengeluaran',
        'ending_km' => 'KM Akhir',
        'approval_status' => 'Status Approval',
        'warehouse_code' => 'Warehouse',
        'complaint' => 'Keluhan / Indikasi Kerusakan',
        'damage_analysis' => 'Analisa Kerusakan',
        'type' => 'Tipe',
        'payment_means' => 'Payment Means',
        'payment_currency' => 'Mata Uang',
        'bp_name' => 'Business Partner',
        'account_name' => 'Account',
        'remarks' => 'Remarks',
        'total_payment' => 'Total Payment',
        'current_approval_level' => 'Approval Saat Ini',
        'required_approval_level' => 'Approval Dibutuhkan',
        'outgoing_payment_no' => 'Outgoing Payment',
        'vendor_name' => 'Vendor',
        'open_lines' => 'Open Lines',
    ];
@endphp

@section('title', 'Detail '.$typeLabel)
@section('page-title', 'Detail '.$typeLabel)
@section('page-subtitle', 'Data lengkap dokumen portal dan referensi SAP Business One.')

@section('page-actions')
    <div class="d-flex gap-2">
        @if(in_array($document->document_type, ['PURCHASE_REQUEST', 'GOODS_ISSUE', 'PURCHASE_ORDER'], true))
            <a class="btn btn-sm btn-outline-primary" href="{{ route('documents.print', $document) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print me-1"></i> Cetak Dokumen</a>
        @endif
        @if($document->document_type === 'PURCHASE_ORDER' && auth()->user()->can('create', \App\Models\SapDocument::class))
            <a class="btn btn-sm btn-outline-primary" href="{{ route('ready-to-receive.show', $document) }}"><i class="fa-regular fa-images me-1"></i> Dokumentasi GRPO</a>
        @endif
        <a class="btn btn-sm btn-outline-secondary" href="{{ route($backRoute) }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
    </div>
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Identitas Dokumen</h2><span class="badge text-bg-light border">{{ $typeLabel }}</span></header>
                <div class="transaction-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Nomor SAP</label><div class="readonly-value mono-field">{{ $document->sap_doc_num ?? 'PENDING' }}</div></div>
                        <div class="col-md-4"><label class="form-label">DocEntry SAP</label><div class="readonly-value mono-field">{{ $document->sap_doc_entry ?? '—' }}</div></div>
                        <div class="col-md-4"><label class="form-label">Nomor FPK</label><div class="readonly-value mono-field">{{ $document->fpk_number ?: data_get($normalizedData, 'fpk_number', '—') }}</div></div>
                        <div class="col-md-4"><label class="form-label">Status Dokumen</label><div class="readonly-value">{{ str($document->sap_business_status->value)->replace('_', ' ')->title() }}</div></div>
                        <div class="col-md-4"><label class="form-label">Status Integrasi</label><div class="readonly-value">{{ str($document->integration_status->value)->replace('_', ' ')->title() }}</div></div>
                        <div class="col-md-4"><label class="form-label">Sumber</label><div class="readonly-value">{{ $document->source }}</div></div>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Konteks Organisasi</h2><i class="fa-solid fa-lock text-secondary"></i></header>
                <div class="transaction-body">
                    <dl class="row mb-0 detail-list">
                        <dt class="col-5">Database</dt><dd class="col-7 mono">{{ $document->company_db }}</dd>
                        <dt class="col-5">Pool</dt><dd class="col-7 mono">{{ $document->pool?->code ?? '—' }}</dd>
                        <dt class="col-5">Department</dt><dd class="col-7 mono">{{ $document->department?->code ?? '—' }}</dd>
                        <dt class="col-5">Dibuat</dt><dd class="col-7">{{ $document->created_at->format('d/m/Y H:i') }}</dd>
                        <dt class="col-5">Diperbarui</dt><dd class="col-7">{{ $document->updated_at->format('d/m/Y H:i') }}</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>

    @if($detailFields !== [])
        <section class="transaction-section mb-3">
            <header class="section-heading"><h2>Informasi Transaksi</h2></header>
            <div class="transaction-body">
                <div class="row g-3">
                    @foreach($detailFields as $key => $value)
                        <div class="{{ in_array($key, ['notes', 'remarks', 'complaint', 'damage_analysis'], true) ? 'col-12' : 'col-md-6 col-xl-4' }}">
                            <label class="form-label">{{ $fieldLabels[$key] ?? str($key)->replace('_', ' ')->title() }}</label>
                            <div class="readonly-value {{ in_array($key, ['notes', 'remarks', 'complaint', 'damage_analysis'], true) ? 'h-auto text-wrap' : '' }}">
                                @if(is_bool($value))
                                    {{ $value ? 'Ya' : 'Tidak' }}
                                @elseif($value === null || $value === '')
                                    —
                                @else
                                    {{ is_string($value) ? str($value)->replace('_', ' ')->toString() : $value }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if($document->approval)
        <section class="operational-panel mb-3">
            <header class="panel-header"><h2 class="panel-title">Riwayat Approval</h2><span class="badge text-bg-light border">{{ $document->approval->status }}</span></header>
            <div class="table-responsive">
                <table class="table table-operational align-middle mb-0">
                    <thead><tr><th>Tahap</th><th>Approver</th><th>Email</th><th>Status</th><th>Waktu Keputusan</th><th>Catatan</th></tr></thead>
                    <tbody>@foreach($document->approval->steps as $approvalStep)<tr><td>{{ $approvalStep->step_order }}</td><td>{{ $approvalStep->approver_name }}</td><td><span class="mono-field">{{ $approvalStep->notification_email ?? '—' }}</span><div class="small text-secondary">{{ $approvalStep->notification_sent_at ? (config('mail.default') === 'log' ? 'Simulasi tercatat ' : 'Terkirim ').$approvalStep->notification_sent_at->format('d/m/Y H:i') : ($approvalStep->notification_failed_at ? 'Pengiriman gagal' : 'Belum dikirim') }}</div></td><td>{{ str($approvalStep->status)->title() }}</td><td>{{ $approvalStep->decided_at?->format('d/m/Y H:i') ?? '—' }}</td><td>{{ $approvalStep->decision_note ?: '—' }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        </section>
    @endif

    @if($document->document_type === 'PURCHASE_REQUEST')
        <section class="operational-panel mb-3">
            <header class="panel-header">
                <div><h2 class="panel-title">Snapshot Riwayat Pemakaian</h2><div class="small text-secondary">Data Goods Issue terakhir saat PR diajukan; tidak berubah walaupun histori SAP kemudian bertambah.</div></div>
                <span class="badge text-bg-light border">{{ $lines->count() }} item</span>
            </header>
            @if(!empty($normalizedData['usage_history_warnings']))
                <div class="alert alert-warning rounded-0 border-start-0 border-end-0 mb-0">{{ implode(' ', $normalizedData['usage_history_warnings']) }}</div>
            @endif
            <div class="table-responsive">
                <table class="table table-operational align-middle mb-0">
                    <thead><tr><th>Item</th><th>Nopol</th><th>Goods Issue Terakhir</th><th>FPK</th><th class="text-end">Qty</th><th class="text-end">Beban</th><th class="text-end">Lama Pemakaian</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($lines as $line)
                        @php($usage = is_array($line['usage_history'] ?? null) ? $line['usage_history'] : [])
                        <tr>
                            <td><div class="mono fw-semibold">{{ $line['item_code'] ?? '—' }}</div><div class="small text-secondary">{{ $line['item_name'] ?? '—' }}</div></td>
                            <td class="mono">{{ $usage['vehicle_code'] ?? data_get($normalizedData, 'vehicle_cost_center.center_code', '—') }}</td>
                            <td>{{ filled($usage['last_goods_issue_date'] ?? null) ? \Illuminate\Support\Carbon::parse($usage['last_goods_issue_date'])->format('d/m/Y') : '—' }}</td>
                            <td class="mono">{{ $usage['fpk'] ?? '—' }}</td>
                            <td class="text-end mono">{{ isset($usage['quantity']) ? number_format((float) $usage['quantity'], 2, ',', '.') : '—' }}</td>
                            <td class="text-end mono fw-semibold">{{ isset($usage['expense_amount']) ? 'Rp '.number_format((float) $usage['expense_amount'], 0, ',', '.') : '—' }}</td>
                            <td class="text-end">{{ isset($usage['days_in_use']) ? number_format((int) $usage['days_in_use'], 0, ',', '.').' hari' : '—' }}</td>
                            <td>{{ match($usage['status'] ?? null) { 'FOUND' => 'Ditemukan', 'NOT_FOUND' => 'Belum pernah GI', 'UNAVAILABLE' => 'Tidak tersedia', default => 'Belum tersimpan' } }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Detail Baris</h2><span class="badge text-bg-light border">{{ $lines->count() }} baris</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle">
                <thead><tr><th>#</th><th>Item / Dokumen Sumber</th><th>Description</th><th>Detail</th></tr></thead>
                <tbody>
                @forelse($lines as $index => $line)
                    @php($item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="mono fw-semibold">{{ $item?->item_code ?? $line['item_code'] ?? $line['invoice_no'] ?? $line['account_code'] ?? '—' }}</td>
                        <td>{{ $item?->item_name ?? $line['item_name'] ?? $line['description'] ?? $line['account_name'] ?? '—' }}</td>
                        <td>
                            <dl class="row mb-0 small detail-list">
                                @foreach($line as $lineKey => $lineValue)
                                    @continue($lineKey === 'item_id' || is_array($lineValue))
                                    <dt class="col-sm-5">{{ str($lineKey)->replace('_', ' ')->title() }}</dt>
                                    <dd class="col-sm-7 mb-1">{{ $lineValue === null || $lineValue === '' ? '—' : $lineValue }}</dd>
                                @endforeach
                                @if(!empty($line['serial_numbers']))
                                    <dt class="col-sm-5">Serial Number</dt><dd class="col-sm-7 mb-1 mono">{{ implode(', ', $line['serial_numbers']) }}</dd>
                                @endif
                                @if(filled(data_get($line, 'issue_type.code')))
                                    <dt class="col-sm-5">Tipe Pengeluaran</dt><dd class="col-sm-7 mb-1">{{ data_get($line, 'issue_type.code') }} — {{ data_get($line, 'issue_type.name') }}</dd>
                                    <dt class="col-sm-5">Account Code</dt><dd class="col-sm-7 mb-1 mono">{{ data_get($line, 'issue_type.account_code') }}</dd>
                                @endif
                            </dl>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="empty-state"><i class="fa-regular fa-file-lines"></i>Dokumen ini tidak memiliki detail baris pada cache portal.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('view-integration-monitor')
        <section class="operational-panel">
            <header class="panel-header"><h2 class="panel-title">Informasi Integrasi</h2><span class="badge text-bg-light border">Admin</span></header>
            <div class="panel-body">
                <dl class="row detail-list">
                    <dt class="col-md-3">Transaction UUID</dt><dd class="col-md-9 mono">{{ $document->transaction_uuid ?: '—' }}</dd>
                    <dt class="col-md-3">SAP Entity</dt><dd class="col-md-9 mono">{{ $document->sap_entity }}</dd>
                    <dt class="col-md-3">Command Status</dt><dd class="col-md-9">{{ $command ? str($command->status->value)->replace('_', ' ')->title() : '—' }}</dd>
                    <dt class="col-md-3">Attempt</dt><dd class="col-md-9">{{ $command?->attempts ?? '—' }}</dd>
                    <dt class="col-md-3">Error Terakhir</dt><dd class="col-md-9">{{ $command?->last_error ?: '—' }}</dd>
                </dl>
                @if($command?->request_payload)
                    <details><summary class="btn btn-sm btn-outline-secondary">Lihat JSON yang dikirim ke SAP</summary><pre class="mt-3 mb-0 p-3 bg-body-tertiary border rounded small"><code>{{ json_encode($command->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre></details>
                @endif
                @if($document->approval?->status === 'APPROVED' && $document->integration_status->value === 'PENDING_POST')
                    <form class="mt-3" method="POST" action="{{ route('documents.post-approved', $document) }}">
                        @csrf
                        <button class="btn btn-sm btn-primary" type="submit"><i class="fa-solid fa-rotate me-1"></i> Coba Kirim ke SAP Sekarang</button>
                        <span class="small text-secondary ms-2">Sistem juga mencoba otomatis setiap 2 menit.</span>
                    </form>
                @endif
            </div>
        </section>
    @endcan
@endsection
