@php
    $uuidField = trim((string) config('sap.fields.web_transaction_id'));
    $uuidWasSent = $command && $uuidField !== '' && data_get($command->request_payload, $uuidField) === $command->transaction_uuid;
    $outcome = $command?->reconcile_outcome;
    $formatTime = fn ($time): string => $time?->format('d/m/Y H:i') ?? 'Belum ada';
@endphp

<div class="border rounded p-3 mt-3" data-purchase-request-recovery>
    <h3 class="h6">Rekonsiliasi Purchase Request</h3>
    @if(! $command)
        <p class="mb-0">Command SAP untuk dokumen ini tidak ditemukan. Perlu investigasi manual.</p>
    @else
        <dl class="row detail-list mb-2">
            <dt class="col-md-3">Percobaan POST terakhir</dt><dd class="col-md-9">{{ $formatTime($command->last_attempt_at) }}</dd>
            <dt class="col-md-3">UUID di payload</dt><dd class="col-md-9">{{ $uuidWasSent ? 'Terkirim pada '.$uuidField : 'Tidak ada pada payload tersimpan' }}</dd>
            <dt class="col-md-3">Pemeriksaan SAP terakhir</dt><dd class="col-md-9">{{ $formatTime($command->last_reconciled_at) }} ({{ (int) $command->reconcile_attempts }} kali)</dd>
            <dt class="col-md-3">Hasil pemeriksaan</dt>
            <dd class="col-md-9">
                {{ $outcome?->message() ?? 'Belum pernah diperiksa.' }}
                @if($command->reconcile_match_count !== null)<div class="small text-secondary">Jumlah temuan SAP: {{ $command->reconcile_match_count >= 2 ? '2 atau lebih' : $command->reconcile_match_count }}</div>@endif
                @if(filled($command->reconcile_detail))<div class="small text-secondary">{{ $command->reconcile_detail }}</div>@endif
            </dd>
            <dt class="col-md-3">Tindakan berikutnya</dt><dd class="col-md-9">{{ $outcome?->nextStep() ?? 'Periksa SAP. Jangan kirim ulang.' }}</dd>
            <dt class="col-md-3">Pemeriksaan otomatis</dt>
            <dd class="col-md-9">
                @if(! config('sap.purchase_request.reconcile.schedule_enabled'))
                    Nonaktif.
                @elseif($command->next_reconcile_at)
                    Berikutnya setelah {{ $formatTime($command->next_reconcile_at) }}.
                @elseif((int) $command->reconcile_attempts === 0)
                    Menunggu jadwal.
                @else
                    Berhenti; periksa secara manual.
                @endif
            </dd>
        </dl>
        @can('reconcile-uncertain-purchase-requests')
            <form method="POST" action="{{ route('admin.purchase-requests.reconcile', $document) }}">
                @csrf
                <button class="btn btn-sm btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i> Periksa SAP &amp; Rekonsiliasi</button>
                <span class="small text-secondary ms-2">Hanya membaca SAP. Dokumen tidak dikirim ulang.</span>
            </form>
            @if(! $uuidWasSent && $document->integration_status->value === 'POSTING_UNKNOWN' && $command->status->value === 'POSTING_UNKNOWN' && $document->approval?->status === 'APPROVED' && $command->last_attempt_at)
                <form class="border-top mt-3 pt-3" method="GET" action="{{ route('admin.purchase-requests.link', $document) }}" data-purchase-request-link>
                    <p class="small mb-2">Dikirim tanpa UUID. Bila sudah menemukan dokumennya di SAP, masukkan nomornya untuk diperiksa ulang. Belum ada yang diubah pada langkah ini.</p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3"><label class="form-label" for="link-doc-entry">DocEntry SAP</label><input class="form-control form-control-sm" id="link-doc-entry" name="doc_entry" type="number" min="1" required></div>
                        <div class="col-md-3"><label class="form-label" for="link-doc-num">DocNum SAP</label><input class="form-control form-control-sm" id="link-doc-num" name="doc_num" inputmode="numeric" pattern="[0-9]+" maxlength="20" required></div>
                        <div class="col-md-4"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="fa-solid fa-link me-1"></i> Periksa untuk Ditautkan</button></div>
                    </div>
                </form>
            @endif
        @endcan
    @endif
</div>
