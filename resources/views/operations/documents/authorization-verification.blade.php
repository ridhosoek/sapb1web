<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Verifikasi {{ $status === 'CHECKED' ? 'Pemeriksaan' : 'Persetujuan' }} Dokumen</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-body-tertiary">
<main class="container py-4" style="max-width: 620px">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
                <img src="{{ asset('images/logo-indra-angkola.jpg') }}" alt="PT Indra Angkola" style="height: 48px; width: 68px; object-fit: contain">
                <div>
                    <div class="fw-bold">PT INDRA ANGKOLA</div>
                    <h1 class="h5 mb-0">Verifikasi {{ $status === 'CHECKED' ? 'Checked by' : 'Approved by' }}</h1>
                </div>
            </div>

            <div class="alert alert-success d-flex align-items-center gap-2">
                <i class="fa-solid fa-shield-check"></i>
                <span>Tautan valid dan ditandatangani oleh SAP B1 Operational Portal.</span>
            </div>

            <dl class="row detail-list mb-0">
                <dt class="col-5">Status</dt>
                <dd class="col-7"><span class="badge text-bg-success">{{ $status }}</span></dd>
                <dt class="col-5">Nama</dt>
                <dd class="col-7 fw-semibold">{{ $authorizedUser->name }}</dd>
                <dt class="col-5">Jabatan</dt>
                <dd class="col-7">{{ $authorizedUser->job_title ?: '—' }}</dd>
                <dt class="col-5">Tanggal {{ $status === 'CHECKED' ? 'Checked' : 'Approved' }}</dt>
                <dd class="col-7">{{ $authorizedAt->format('d/m/Y H:i') }}</dd>
                <dt class="col-5">Nomor Dokumen SAP</dt>
                <dd class="col-7 font-monospace">{{ $document->sap_doc_num ?? 'BELUM TERBIT' }}</dd>
                <dt class="col-5">Tanggal Dokumen SAP</dt>
                <dd class="col-7">{{ $documentDate }}</dd>
            </dl>
        </div>
    </div>
</main>
</body>
</html>
