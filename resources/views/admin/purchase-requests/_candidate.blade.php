<section class="operational-panel mb-3" data-sap-candidate="{{ $candidate['DocEntry'] }}">
    <header class="panel-header">
        <h2 class="panel-title">{{ $title }}: DocEntry {{ $candidate['DocEntry'] }} · DocNum {{ $candidate['DocNum'] ?: '—' }}</h2>
        @if($candidate['held_by'])<span class="badge text-bg-secondary">Dipakai {{ $candidate['held_by'] }}</span>@endif
    </header>
    <div class="panel-body">
        <dl class="row detail-list mb-2">
            <dt class="col-md-3">Dibuat di SAP</dt><dd class="col-md-9">{{ $candidate['CreationDate'] ?: '—' }} {{ $candidate['DocTime'] ?? '' }}</dd>
            <dt class="col-md-3">Tanggal dokumen</dt><dd class="col-md-9">{{ $candidate['DocDate'] ?: '—' }}</dd>
            <dt class="col-md-3">Status SAP</dt><dd class="col-md-9">{{ $candidate['DocumentStatus'] ?? '—' }} · Cancelled {{ $candidate['Cancelled'] ?? '—' }}</dd>
            <dt class="col-md-3">Komentar</dt><dd class="col-md-9">{{ $candidate['Comments'] ?: '—' }}</dd>
        </dl>
        <ul class="list-unstyled small mb-2">
            @foreach($candidate['checks'] as $check => $passed)
                <li data-check="{{ $check }}" data-passed="{{ $passed ? 'yes' : 'no' }}" class="{{ $passed ? 'text-success' : 'text-danger' }}">
                    <i class="fa-solid {{ $passed ? 'fa-circle-check' : 'fa-circle-xmark' }} me-1"></i>{{ \App\Application\Purchasing\LinkPurchaseRequestToSap::CHECK_LABELS[$check] }}: {{ $passed ? 'cocok' : 'tidak cocok' }}
                </li>
            @endforeach
        </ul>
        @include('admin.purchase-requests._lines', ['lines' => $candidate['DocumentLines']])
    </div>
</section>
