@extends('layouts.app')

@section('title', 'Tautkan Purchase Request')
@section('page-title', 'Tautkan Purchase Request ke SAP')
@section('page-subtitle', 'Portal #'.$document->id.' · '.$document->company_db.' · hanya membaca SAP, tidak mengirim dokumen.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.show', $document) }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Dokumen</a>
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @if($review['problems'] !== [])
        <div class="alert alert-warning" data-link-problems>
            <strong>Tidak dapat ditautkan.</strong>
            <ul class="mb-0">@foreach($review['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
        </div>
    @endif

    @if($review['portal'])
        <section class="operational-panel mb-3">
            <header class="panel-header"><h2 class="panel-title">Yang dikirim portal</h2></header>
            <div class="panel-body">
                <dl class="row detail-list mb-2">
                    <dt class="col-md-3">Percobaan POST</dt><dd class="col-md-9">{{ $review['portal']['attempted_at'] }}</dd>
                    <dt class="col-md-3">Tanggal dokumen</dt><dd class="col-md-9">{{ $review['portal']['DocDate'] }}</dd>
                    <dt class="col-md-3">Komentar</dt><dd class="col-md-9">{{ $review['portal']['Comments'] ?: '—' }}</dd>
                </dl>
                @include('admin.purchase-requests._lines', ['lines' => $review['portal']['DocumentLines']])
            </div>
        </section>
    @endif

    @if($review['selected'])
        @include('admin.purchase-requests._candidate', ['candidate' => $review['selected'], 'title' => 'DocEntry yang dipilih'])

        <section class="operational-panel mb-3">
            <header class="panel-header">
                <div><h2 class="panel-title">Kandidat lain dengan baris yang sama</h2><div class="small text-secondary">Dibuat di SAP {{ $review['candidate_window']['from'] }} s.d. {{ $review['candidate_window']['to'] }}.</div></div>
                <span class="badge text-bg-light border">{{ count($review['others']) }} kandidat</span>
            </header>
            @if($review['others'] === [])
                <div class="panel-body">Tidak ada kandidat lain pada rentang tersebut.</div>
            @endif
        </section>
        @foreach($review['others'] as $other)
            @include('admin.purchase-requests._candidate', ['candidate' => $other, 'title' => 'Kandidat lain'])
        @endforeach
    @endif

    @if($review['problems'] === [] && $review['selected'])
        <section class="operational-panel">
            <header class="panel-header"><h2 class="panel-title">Konfirmasi tautan</h2></header>
            <form class="panel-body" method="POST" action="{{ route('admin.purchase-requests.link.store', $document) }}">
                @csrf
                <input type="hidden" name="doc_entry" value="{{ $docEntry }}">
                <input type="hidden" name="doc_num" value="{{ $docNum }}">
                @foreach($review['others'] as $other)
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="reviewed-{{ $other['DocEntry'] }}" name="reviewed_candidates[]" value="{{ $other['DocEntry'] }}" required>
                        <label class="form-check-label" for="reviewed-{{ $other['DocEntry'] }}">Saya sudah memeriksa DocEntry {{ $other['DocEntry'] }} (DocNum {{ $other['DocNum'] }}) dan bukan itu hasil PR portal ini.</label>
                    </div>
                @endforeach
                <label class="form-label" for="link-reason">Alasan memilih DocEntry {{ $docEntry }} dan hasil pemeriksaan di SAP</label>
                <textarea class="form-control" id="link-reason" name="reason" rows="3" minlength="{{ \App\Application\Purchasing\LinkPurchaseRequestToSap::MIN_REASON_LENGTH }}" maxlength="1000" required>{{ old('reason') }}</textarea>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="link-confirm" name="confirm" value="1" required>
                    <label class="form-check-label" for="link-confirm">Saya sudah memverifikasi di SAP bahwa DocEntry {{ $docEntry }} adalah hasil PR portal #{{ $document->id }}.</label>
                </div>
                <button class="btn btn-sm btn-warning mt-3" type="submit"><i class="fa-solid fa-link me-1"></i> Tautkan ke DocEntry {{ $docEntry }}</button>
                <span class="small text-secondary ms-2">SAP dibaca ulang sebelum dicatat. Tidak ada POST ke SAP.</span>
            </form>
        </section>
    @endif
@endsection
