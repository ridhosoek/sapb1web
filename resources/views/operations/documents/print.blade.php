<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }} {{ $document->sap_doc_num ?? '#'.$document->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body { color: #172b3a; font-family: Arial, sans-serif; font-size: 11px; margin: 0; }
        .toolbar { background: #eef3f6; border-bottom: 1px solid #ccd6de; display: flex; gap: 8px; justify-content: flex-end; padding: 10px 18px; }
        .toolbar button, .toolbar a { background: #fff; border: 1px solid #62798a; border-radius: 4px; color: #17324d; cursor: pointer; font: inherit; padding: 7px 12px; text-decoration: none; }
        .toolbar button { background: #2d6a8f; border-color: #2d6a8f; color: #fff; }
        .sheet { margin: 0 auto; max-width: 1120px; padding: 20px; }
        .header { align-items: flex-start; border-bottom: 3px solid #17324d; display: flex; justify-content: space-between; padding-bottom: 12px; }
        .brand { align-items: center; display: flex; gap: 10px; }
        .brand-logo { display: block; flex: 0 0 72px; height: 52px !important; max-height: 52px; max-width: 72px; object-fit: contain; width: 72px !important; }
        h1 { font-size: 20px; margin: 0 0 3px; }
        h2 { font-size: 13px; margin: 18px 0 7px; }
        .muted { color: #647786; }
        .doc-number { text-align: right; }
        .doc-number strong { display: block; font-family: Consolas, monospace; font-size: 16px; }
        .notice { background: #f5f8fa; border: 1px solid #d1dae1; margin-top: 12px; padding: 8px 10px; }
        .meta { border: 1px solid #bdc9d1; display: grid; grid-template-columns: repeat(4, 1fr); margin-top: 14px; }
        .meta > div { border-bottom: 1px solid #d8e0e5; border-right: 1px solid #d8e0e5; min-height: 45px; padding: 6px 8px; }
        .meta > div:nth-child(4n) { border-right: 0; }
        .meta label { color: #647786; display: block; font-size: 9px; font-weight: 700; margin-bottom: 4px; text-transform: uppercase; }
        .mono { font-family: Consolas, monospace; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #9fafba; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #e7edf1; font-size: 9px; text-transform: uppercase; }
        .number { text-align: right; white-space: nowrap; }
        .pr-sheet { color: #000; font-family: Arial, sans-serif; margin: 0 auto; max-width: 1120px; padding: 26px 38px 18px; }
        .pr-top { align-items: flex-start; display: grid; grid-template-columns: 1fr 315px; min-height: 102px; }
        .pr-company { font-size: 12px; font-weight: 700; padding-top: 8px; }
        .pr-page { font-size: 11px; margin-bottom: 8px; text-align: right; }
        .pr-meta { border: 0; font-size: 12px; margin-left: auto; width: 100%; }
        .pr-meta td { border: 0; padding: 2px 4px; }
        .pr-meta td:nth-child(1) { width: 95px; }
        .pr-meta td:nth-child(2) { text-align: center; width: 12px; }
        .pr-meta td:last-child { border: 1px solid #111; padding-right: 7px; text-align: right; }
        .pr-title { font-size: 21px; font-weight: 700; margin: 0 0 18px; text-align: center; }
        .pr-items { border: 1px solid #000; }
        .pr-items th, .pr-items td { border-color: #000; color: #000; font-family: "Times New Roman", serif; font-size: 13px; padding: 5px 6px; }
        .pr-items th { background: #fff; border-bottom: 4px double #000; font-size: 12px; font-weight: 400; text-align: center; }
        .pr-items td { border-bottom: 0; border-top: 0; height: 31px; }
        .pr-items tbody tr:first-child td { border-top: 1px solid #111; }
        .pr-items tbody tr:last-child td { border-bottom: 1px solid #111; }
        .pr-remarks { font-size: 12px; margin-top: 10px; }
        .pr-authorizations { align-items: start; display: grid; gap: 14px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 18px; }
        .pr-authorization { min-width: 0; text-align: center; }
        .pr-authorization-label { font-size: 12px; margin-bottom: 5px; }
        .digital-signature { border: 1px solid #000; height: 122px; padding: 5px; text-align: center; }
        .digital-signature-layout { align-items: center; display: flex; gap: 2px; height: 100%; justify-content: center; }
        .digital-signature-details { align-items: center; display: flex; flex: 1 1 auto; flex-direction: column; justify-content: center; min-width: 0; }
        .digital-signature-brand { align-items: center; display: flex; gap: 3px; justify-content: center; max-width: 100%; }
        .digital-signature-logo { display: block; height: 24px; object-fit: contain; width: 34px; }
        .digital-signature-company { font-size: 7px; font-weight: 700; line-height: 1.1; text-align: left; white-space: nowrap; }
        .digital-signature-role { font-size: 7px; margin-top: 4px; text-transform: uppercase; }
        .digital-signature-name { font-size: 12px; font-weight: 700; line-height: 1.05; margin-top: 3px; overflow-wrap: anywhere; text-transform: uppercase; }
        .digital-signature-meta { font-size: 7px; margin-top: 5px; text-transform: uppercase; }
        .digital-signature-qr { align-items: center; display: flex; flex: 0 0 110px; height: 110px; justify-content: center; }
        .verification-qr-svg { display: block; height: 110px; width: 110px; }
        .digital-signature-pending { align-items: center; border: 1px solid #000; color: #555; display: flex; font-size: 9px; height: 122px; justify-content: center; padding: 8px; text-transform: uppercase; }
        .wet-signature { border: 1px solid #000; height: 122px; padding: 7px 10px 6px; text-align: center; }
        .wet-signature-hint { font-size: 9px; }
        .wet-signature-space { height: 55px; }
        .wet-signature-name { border-bottom: 1px solid #000; font-size: 10px; font-weight: 700; margin: 0 auto; min-height: 16px; max-width: 165px; padding-bottom: 2px; text-transform: uppercase; }
        .wet-signature-role { font-size: 9px; margin-top: 5px; text-transform: uppercase; }
        .pr-approval-note { color: #333; font-size: 9px; margin-top: 10px; }
        .pr-print-date { font-size: 9px; margin-top: 9px; }
        .photos { display: grid; gap: 10px; grid-template-columns: repeat(3, 1fr); }
        .photo { break-inside: avoid; border: 1px solid #bdc9d1; padding: 6px; }
        .photo img { display: block; height: 165px; object-fit: contain; width: 100%; }
        .photo small { display: block; margin-top: 5px; }
        .footer { border-top: 1px solid #bdc9d1; color: #647786; display: flex; justify-content: space-between; margin-top: 18px; padding-top: 7px; }
        @page { margin: 10mm; size: A4 portrait; }
        @media print {
            .toolbar { display: none; }
            .sheet { max-width: 190mm; padding: 0; width: 190mm; }
            .pr-sheet { max-width: 190mm; padding: 0; width: 190mm; }
            .pr-top { grid-template-columns: 1fr 250px; min-height: 68px; }
            .pr-company { font-size: 10px; padding-top: 2px; }
            .pr-page { font-size: 9px; margin-bottom: 3px; }
            .pr-meta { font-size: 10px; }
            .pr-meta td { padding: 1px 3px; }
            .pr-meta td:nth-child(1) { width: 74px; }
            .pr-title { font-size: 18px; margin-bottom: 8px; }
            .pr-items th, .pr-items td { font-size: 10px; padding: 2px 3px; }
            .pr-items th { border-bottom-width: 3px; font-size: 9px; }
            .pr-items th:nth-child(1) { width: 28px !important; }
            .pr-items th:nth-child(2) { width: 80px !important; }
            .pr-items th:nth-child(4) { width: 64px !important; }
            .pr-items th:nth-child(5) { width: 42px !important; }
            .pr-items th:nth-child(6) { width: 52px !important; }
            .pr-items th:nth-child(7) { width: 62px !important; }
            .pr-items th:nth-child(8) { width: 24% !important; }
            .pr-items td { height: 22px; }
            .pr-remarks { font-size: 10px; margin-top: 6px; }
            .pr-authorizations { gap: 4px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 7px; }
            .pr-authorization-label { font-size: 8px; margin-bottom: 1px; }
            .digital-signature, .digital-signature-pending, .wet-signature { height: 90px; }
            .digital-signature { padding: 2px; }
            .digital-signature-layout { gap: 0; }
            .digital-signature.has-qr .digital-signature-details { flex: 1 1 0; }
            .digital-signature-logo { height: 14px; width: 20px; }
            .digital-signature-company { font-size: 5px; letter-spacing: -.05px; }
            .digital-signature-role { font-size: 6px; margin-top: 2px; }
            .digital-signature-name { font-size: 10px; margin-top: 2px; }
            .digital-signature-meta { font-size: 6px; margin-top: 3px; }
            .digital-signature-qr { flex-basis: 82px; height: 82px; }
            .digital-signature.has-qr .digital-signature-qr { margin-left: -6px; }
            .verification-qr-svg { height: 82px; width: 82px; }
            .wet-signature { padding-bottom: 4px; padding-top: 5px; }
            .wet-signature-space { height: 27px; }
            .pr-approval-note { margin-top: 4px; }
            .pr-print-date { margin-top: 3px; }
            .photos { grid-template-columns: repeat(2, 1fr); }
            thead { display: table-header-group; }
            tr, .photo, .pr-authorization { break-inside: avoid; }
        }
    </style>
</head>
<body>
@php
    $headerDocument = is_array($normalizedData['document'] ?? null) ? $normalizedData['document'] : [];
    $documentDate = $normalizedData['document_date'] ?? $normalizedData['fpk_date'] ?? $headerDocument['DocDate'] ?? $document->created_at->toDateString();
    $warehouse = $normalizedData['warehouse_code'] ?? data_get($lines->first(), 'warehouse_code') ?? data_get($lines->first(), 'WarehouseCode') ?? '—';
    $remarks = $normalizedData['notes'] ?? $normalizedData['remarks'] ?? $headerDocument['Comments'] ?? '—';
    $endingKm = $normalizedData['ending_km'] ?? '—';
@endphp
<div class="toolbar"><a href="javascript:window.close()">Tutup</a><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
@if($document->document_type === 'PURCHASE_REQUEST')
<main class="pr-sheet">
    <header class="pr-top">
        <div class="pr-company">PT INDRA ANGKOLA</div>
        <div>
            <div class="pr-page">Page 1 of 1</div>
            <table class="pr-meta">
                <tr><td>PR. No</td><td>:</td><td>{{ $document->sap_doc_num ?? '#'.$document->id }}</td></tr>
                <tr><td>PR. Date</td><td>:</td><td>{{ \Illuminate\Support\Carbon::parse($documentDate)->format('d-M-Y') }}</td></tr>
                <tr><td>DEPT</td><td>:</td><td>{{ $document->department?->name ?? $document->department?->code ?? '—' }}</td></tr>
                <tr><td>Warehouse</td><td>:</td><td>{{ $warehouse }}</td></tr>
            </table>
        </div>
    </header>

    <h1 class="pr-title">PURCHASE REQUEST</h1>

    <table class="pr-items">
        <thead>
            <tr><th style="width: 38px">NO.</th><th style="width: 110px">ITEM CODE</th><th>ITEM NAME</th><th style="width: 90px">NOPOL</th><th style="width: 70px">UOM</th><th style="width: 80px">QTY REQ</th><th style="width: 90px">KM</th><th style="width: 25%">DESCRIPTION</th></tr>
        </thead>
        <tbody>
        @forelse($lines as $index => $line)
            @php($item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null)
            <tr>
                <td class="number">{{ $index + 1 }}</td>
                <td>{{ $item?->item_code ?? $line['item_code'] ?? $line['ItemCode'] ?? '—' }}</td>
                <td>{{ $item?->item_name ?? $line['item_name'] ?? $line['description'] ?? $line['ItemDescription'] ?? '—' }}</td>
                <td class="mono">{{ data_get($line, 'vehicle_cost_center.center_code', data_get($normalizedData, 'vehicle_cost_center.center_code', '—')) }}</td>
                <td style="text-align: center">{{ $line['manual_uom'] ?? $item?->inventory_uom ?? $line['inventory_uom'] ?? $line['uom'] ?? '—' }}</td>
                <td class="number">{{ number_format((float) ($line['quantity'] ?? $line['Quantity'] ?? 0), 2, '.', ',') }}</td>
                <td class="number">{{ is_numeric($endingKm) ? number_format((float) $endingKm, 0, '.', ',') : $endingKm }}</td>
                <td>{{ $line['free_text'] ?? $line['FreeText'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align: center">Detail barang belum tersedia.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="pr-remarks"><strong>REMARKS :</strong> &nbsp; {{ $remarks }}</div>

    <div class="pr-authorizations">
        @foreach($authorizationBlocks as $authorization)
            <div class="pr-authorization">
                <div class="pr-authorization-label">{{ $authorization['label'] }},</div>
                @if($authorization['wet_signature'])
                    <div class="wet-signature">
                        <div class="wet-signature-hint">[Signature Name &amp; Date]</div>
                        <div class="wet-signature-space"></div>
                        <div class="wet-signature-name">{{ $authorization['name'] }}</div>
                        <div class="wet-signature-role">{{ $authorization['job_title'] }}</div>
                    </div>
                @elseif($authorization['stamp_status'])
                    <div @class(['digital-signature', 'has-qr' => filled($authorization['qr_svg'])])>
                        <div class="digital-signature-layout">
                            <div class="digital-signature-details">
                                <div class="digital-signature-brand">
                                    <img class="digital-signature-logo" src="{{ asset('images/logo-indra-angkola.jpg') }}" alt="Indra Angkola">
                                    <div class="digital-signature-company">PT INDRA ANGKOLA</div>
                                </div>
                                <div class="digital-signature-role">{{ $authorization['job_title'] }}</div>
                                <div class="digital-signature-name">{{ $authorization['name'] }}</div>
                                <div class="digital-signature-meta">{{ $authorization['stamp_status'] }} · {{ $authorization['signed_at']?->format('d.m.Y H:i') ?? '—' }}</div>
                            </div>
                            @if($authorization['qr_svg'])
                                <div class="digital-signature-qr">
                                    {{ $authorization['qr_svg'] }}
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="digital-signature-pending">Belum disetujui {{ $authorization['name'] }}</div>
                @endif
            </div>
        @endforeach
    </div>

    @if($document->approval?->status === 'APPROVED' && $document->approval?->decided_at)
        <div class="pr-approval-note">PR disetujui pada {{ $document->approval->decided_at->format('d/m/Y H:i') }} melalui {{ $document->approval->steps->count() }} tahap approval di Web SAP.</div>
    @else
        <div class="pr-approval-note">Status approval PR: {{ str($document->approval?->status ?? 'BELUM ADA')->replace('_', ' ')->title() }}.</div>
    @endif
    <div class="pr-print-date">Tanggal Cetak: {{ $generatedAt->translatedFormat('d-F-Y') }}</div>
</main>
@else
<main class="sheet">
    <header class="header">
        <div class="brand"><img class="brand-logo" src="{{ asset('images/logo-indra-angkola.jpg') }}" alt="Indra Angkola" width="72" height="52"><div><h1>{{ $documentTitle }}</h1><div class="muted">SAP B1 Operational Web Portal</div></div></div>
        <div class="doc-number"><span class="muted">Nomor Dokumen</span><strong>{{ $document->sap_doc_num ?? '#'.$document->id }}</strong><span>{{ $document->company_db }}</span></div>
    </header>

    @if($document->document_type === 'PURCHASE_ORDER')
        <div class="notice"><strong>Form dokumentasi penerimaan.</strong> Dokumen ini dibuat berdasarkan Purchase Order SAP dan bukan bukti bahwa GRPO telah diposting ke SAP.</div>
    @endif

    <section class="meta">
        <div><label>Tanggal Dokumen</label>{{ \Illuminate\Support\Carbon::parse($documentDate)->format('d/m/Y') }}</div>
        <div><label>FPK</label><span class="mono">{{ $document->fpk_number ?: ($normalizedData['fpk_number'] ?? '—') }}</span></div>
        <div><label>Pool / Department</label>{{ $document->pool?->code ?? '—' }} / {{ $document->department?->code ?? '—' }}</div>
        <div><label>Warehouse</label><span class="mono">{{ $warehouse }}</span></div>
        <div><label>Vendor</label>{{ $normalizedData['vendor_name'] ?? $headerDocument['CardName'] ?? '—' }}</div>
        <div><label>Status Approval</label>{{ str($document->approval?->status ?? ($normalizedData['approval_status'] ?? '—'))->replace('_', ' ')->title() }}</div>
        <div><label>Status Integrasi</label>{{ str($document->integration_status->value)->replace('_', ' ')->title() }}</div>
        <div style="grid-column: span 4"><label>Remarks</label>{{ $remarks }}</div>
    </section>

    <h2>Detail Barang</h2>
    <table>
        <thead><tr><th style="width: 28px">#</th><th>Item Code</th><th>Description</th><th>Nopol</th>@if($document->document_type === 'GOODS_ISSUE')<th>Tipe Pengeluaran / COA</th>@endif<th class="number">Qty</th><th>UoM</th><th>Warehouse</th><th>Tanggal Kebutuhan</th><th>Free Text</th></tr></thead>
        <tbody>
        @forelse($lines as $index => $line)
            @php($item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="mono">{{ $item?->item_code ?? $line['item_code'] ?? $line['ItemCode'] ?? '—' }}</td>
                <td>{{ $item?->item_name ?? $line['item_name'] ?? $line['description'] ?? $line['ItemDescription'] ?? $line['Dscription'] ?? '—' }}</td>
                <td class="mono">{{ data_get($line, 'vehicle_cost_center.center_code', $line['vehicle_code'] ?? $line[config('sap.purchase_request.vehicle_costing_field')] ?? data_get($normalizedData, 'vehicle_cost_center.center_code', '—')) }}</td>
                @if($document->document_type === 'GOODS_ISSUE')
                    <td>{{ data_get($line, 'issue_type.code', '—') }}<br><span class="mono">{{ data_get($line, 'issue_type.account_code', '—') }}</span></td>
                @endif
                <td class="number mono">{{ number_format((float) ($line['quantity'] ?? $line['Quantity'] ?? 0), 2, ',', '.') }}</td>
                <td class="mono">{{ $line['manual_uom'] ?? $item?->inventory_uom ?? $line['uom'] ?? $line['UoMCode'] ?? $line['MeasureUnit'] ?? '—' }}</td>
                <td class="mono">{{ $line['warehouse_code'] ?? $line['WarehouseCode'] ?? $warehouse }}</td>
                <td>{{ $line['required_date'] ?? $line['RequiredDate'] ?? $normalizedData['required_date'] ?? '—' }}</td>
                <td>{{ $line['free_text'] ?? $line['FreeText'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $document->document_type === 'GOODS_ISSUE' ? 10 : 9 }}" style="text-align: center">Detail baris belum tersedia pada cache portal.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if($document->document_type === 'PURCHASE_ORDER' && $document->attachments->isNotEmpty())
        <h2>Dokumentasi Penerimaan</h2>
        <div class="photos">
            @foreach($document->attachments as $attachment)
                <div class="photo"><img src="{{ route('document-attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}"><small><strong>{{ $attachment->original_name }}</strong><br>{{ $attachment->caption ?: 'Tanpa keterangan' }} · {{ $attachment->created_at->format('d/m/Y H:i') }}</small></div>
            @endforeach
        </div>
    @endif

    <footer class="footer"><span>Dicetak dari Web SAP pada {{ $generatedAt->format('d/m/Y H:i') }}</span><span>Portal ID #{{ $document->id }}</span></footer>
</main>
@endif
</body>
</html>
