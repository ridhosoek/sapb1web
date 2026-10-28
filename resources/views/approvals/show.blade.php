<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approval Dokumen · SAP B1 Portal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-body-tertiary">
<main class="container py-4" style="max-width: 760px">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-4"><span class="brand-mark">B1</span><div><h1 class="h4 mb-0">Approval {{ str($document->document_type)->replace('_', ' ')->title() }}</h1><span class="text-secondary">Tahap {{ $step->step_order }} dari {{ $approval->steps->count() }}</span></div></div>
            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
            <dl class="row detail-list">
                <dt class="col-5">Approver</dt><dd class="col-7">{{ $step->approver_name }}</dd>
                <dt class="col-5">Pengaju</dt><dd class="col-7">{{ $approval->submitter->name }}</dd>
                <dt class="col-5">Pool / Department</dt><dd class="col-7">{{ $document->pool?->code }} / {{ $document->department?->code }}</dd>
                <dt class="col-5">Referensi</dt><dd class="col-7 mono">{{ $document->fpk_number ?: '#'.$document->id }}</dd>
                <dt class="col-5">Nopol</dt><dd class="col-7 mono">{{ data_get($document->normalized_data, 'vehicle.license_plate', data_get($document->normalized_data, 'vehicle_cost_center.center_code', '—')) }}</dd>
                <dt class="col-5">KM Akhir</dt><dd class="col-7 mono">{{ number_format((int) data_get($document->normalized_data, 'ending_km', 0), 0, ',', '.') }}</dd>
                <dt class="col-5">Status</dt><dd class="col-7"><span class="badge text-bg-light border">{{ $approval->status }}</span></dd>
            </dl>
            @php($approvalLines = collect(data_get($document->normalized_data, 'lines', []))->filter(fn($line) => is_array($line)))
            @if($approvalLines->isNotEmpty())
                <h2 class="h6 mt-4">Detail Item</h2>
                <div class="table-responsive border rounded mb-4">
                    <table class="table table-sm table-operational align-middle mb-0">
                        <thead><tr><th>Kode</th><th>Nama Item</th><th class="text-end">Qty</th>@if($document->document_type === 'PURCHASE_REQUEST')<th>GI Terakhir</th><th class="text-end">Beban</th><th class="text-end">Pemakaian</th>@elseif($document->document_type === 'GOODS_ISSUE')<th>Tipe / COA</th>@endif</tr></thead>
                        <tbody>
                        @foreach($approvalLines as $line)
                            @php($usage = is_array($line['usage_history'] ?? null) ? $line['usage_history'] : [])
                            <tr>
                                <td class="mono fw-semibold">{{ $line['item_code'] ?? '—' }}</td>
                                <td>{{ $line['item_name'] ?? '—' }}</td>
                                <td class="text-end mono">{{ number_format((float) ($line['quantity'] ?? 0), 2, ',', '.') }}</td>
                                @if($document->document_type === 'PURCHASE_REQUEST')
                                    <td>{{ filled($usage['last_goods_issue_date'] ?? null) ? \Illuminate\Support\Carbon::parse($usage['last_goods_issue_date'])->format('d/m/Y') : ((($usage['status'] ?? null) === 'NOT_FOUND') ? 'Belum pernah GI' : '—') }}</td>
                                    <td class="text-end mono">{{ isset($usage['expense_amount']) ? 'Rp '.number_format((float) $usage['expense_amount'], 0, ',', '.') : '—' }}</td>
                                    <td class="text-end">{{ isset($usage['days_in_use']) ? number_format((int) $usage['days_in_use'], 0, ',', '.').' hari' : '—' }}</td>
                                @elseif($document->document_type === 'GOODS_ISSUE')
                                    <td>{{ data_get($line, 'issue_type.code', '—') }} / <span class="mono">{{ data_get($line, 'issue_type.account_code', '—') }}</span></td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($isCurrentStep)
                <div class="alert alert-warning small">Persetujuan terakhir akan langsung mencoba membuat dokumen di SAP. Untuk Goods Issue, stok warehouse diperiksa ulang sebelum barang dikeluarkan.</div>
                <form method="POST" action="{{ URL::temporarySignedRoute('approvals.decide', $step->expires_at, ['step' => $step->id, 'token' => $step->token]) }}">
                    @csrf
                    <label class="form-label" for="note">Catatan keputusan</label>
                    <textarea class="form-control mb-3" id="note" name="note" rows="3" maxlength="1000" placeholder="Wajib diisi jika menolak"></textarea>
                    <div class="d-grid d-sm-flex gap-2 justify-content-sm-end">
                        <button class="btn btn-outline-danger" name="decision" value="REJECT" type="submit"><i class="fa-solid fa-xmark me-1"></i> Tolak</button>
                        <button class="btn btn-success" name="decision" value="APPROVE" type="submit"><i class="fa-solid fa-check me-1"></i> Setujui</button>
                    </div>
                </form>
            @elseif($step->status !== 'PENDING')
                <div class="alert alert-secondary mb-0">Tautan ini sudah diproses: <strong>{{ $step->status }}</strong>{{ $step->decided_at ? ' pada '.$step->decided_at->format('d/m/Y H:i') : '' }}.</div>
            @else
                <div class="alert alert-info mb-0">Menunggu approval tahap {{ $approval->current_step }} terlebih dahulu.</div>
            @endif
        </div>
    </div>
</main>
</body>
</html>
