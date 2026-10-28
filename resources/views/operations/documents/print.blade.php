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
        .brand-mark { align-items: center; background: #17324d; border-radius: 4px; color: #fff; display: flex; font-size: 18px; font-weight: 700; height: 38px; justify-content: center; width: 42px; }
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
        .approval { display: grid; gap: 10px; grid-template-columns: repeat(3, 1fr); margin-top: 20px; }
        .signature { border: 1px solid #9fafba; min-height: 105px; padding: 7px; text-align: center; }
        .signature strong { display: block; margin-bottom: 65px; }
        .photos { display: grid; gap: 10px; grid-template-columns: repeat(3, 1fr); }
        .photo { break-inside: avoid; border: 1px solid #bdc9d1; padding: 6px; }
        .photo img { display: block; height: 165px; object-fit: contain; width: 100%; }
        .photo small { display: block; margin-top: 5px; }
        .footer { border-top: 1px solid #bdc9d1; color: #647786; display: flex; justify-content: space-between; margin-top: 18px; padding-top: 7px; }
        @page { margin: 10mm; size: A4 landscape; }
        @media print {
            .toolbar { display: none; }
            .sheet { max-width: none; padding: 0; }
            thead { display: table-header-group; }
            tr, .signature, .photo { break-inside: avoid; }
        }
    </style>
</head>
<body>
@php
    $headerDocument = is_array($normalizedData['document'] ?? null) ? $normalizedData['document'] : [];
    $vehicle = data_get($normalizedData, 'vehicle_cost_center.center_code') ?? data_get($normalizedData, 'vehicle.license_plate') ?? ($normalizedData['vehicle'] ?? '—');
    $documentDate = $normalizedData['document_date'] ?? $normalizedData['fpk_date'] ?? $headerDocument['DocDate'] ?? $document->created_at->toDateString();
    $warehouse = $normalizedData['warehouse_code'] ?? data_get($lines->first(), 'warehouse_code') ?? data_get($lines->first(), 'WarehouseCode') ?? '—';
    $remarks = $normalizedData['notes'] ?? $normalizedData['remarks'] ?? $headerDocument['Comments'] ?? '—';
@endphp
<div class="toolbar"><a href="javascript:window.close()">Tutup</a><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
<main class="sheet">
    <header class="header">
        <div class="brand"><div class="brand-mark">B1</div><div><h1>{{ $documentTitle }}</h1><div class="muted">SAP B1 Operational Web Portal</div></div></div>
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
        <div><label>Nopol</label><span class="mono">{{ is_scalar($vehicle) ? $vehicle : '—' }}</span></div>
        <div><label>Vendor</label>{{ $normalizedData['vendor_name'] ?? $headerDocument['CardName'] ?? '—' }}</div>
        <div><label>Status Approval</label>{{ str($document->approval?->status ?? ($normalizedData['approval_status'] ?? '—'))->replace('_', ' ')->title() }}</div>
        <div><label>Status Integrasi</label>{{ str($document->integration_status->value)->replace('_', ' ')->title() }}</div>
        <div style="grid-column: span 4"><label>Remarks</label>{{ $remarks }}</div>
    </section>

    <h2>Detail Barang</h2>
    <table>
        <thead><tr><th style="width: 28px">#</th><th>Item Code</th><th>Description</th>@if($document->document_type === 'GOODS_ISSUE')<th>Tipe Pengeluaran / COA</th>@endif<th class="number">Qty</th><th>UoM</th><th>Warehouse</th><th>Tanggal Kebutuhan</th><th>Free Text</th></tr></thead>
        <tbody>
        @forelse($lines as $index => $line)
            @php($item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="mono">{{ $item?->item_code ?? $line['item_code'] ?? $line['ItemCode'] ?? '—' }}</td>
                <td>{{ $item?->item_name ?? $line['item_name'] ?? $line['description'] ?? $line['ItemDescription'] ?? $line['Dscription'] ?? '—' }}</td>
                @if($document->document_type === 'GOODS_ISSUE')
                    <td>{{ data_get($line, 'issue_type.code', '—') }}<br><span class="mono">{{ data_get($line, 'issue_type.account_code', '—') }}</span></td>
                @endif
                <td class="number mono">{{ number_format((float) ($line['quantity'] ?? $line['Quantity'] ?? 0), 2, ',', '.') }}</td>
                <td class="mono">{{ $item?->inventory_uom ?? $line['uom'] ?? $line['UoMCode'] ?? $line['MeasureUnit'] ?? '—' }}</td>
                <td class="mono">{{ $line['warehouse_code'] ?? $line['WarehouseCode'] ?? $warehouse }}</td>
                <td>{{ $line['required_date'] ?? $line['RequiredDate'] ?? $normalizedData['required_date'] ?? '—' }}</td>
                <td>{{ $line['free_text'] ?? $line['FreeText'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $document->document_type === 'GOODS_ISSUE' ? 9 : 8 }}" style="text-align: center">Detail baris belum tersedia pada cache portal.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if($document->approval?->steps?->isNotEmpty())
        <h2>Riwayat Approval</h2>
        <table>
            <thead><tr><th>Tahap</th><th>Approver</th><th>Status</th><th>Waktu</th><th>Catatan</th></tr></thead>
            <tbody>@foreach($document->approval->steps as $step)<tr><td>{{ $step->step_order }}</td><td>{{ $step->approver_name }}</td><td>{{ str($step->status)->title() }}</td><td>{{ $step->decided_at?->format('d/m/Y H:i') ?? '—' }}</td><td>{{ $step->decision_note ?: '—' }}</td></tr>@endforeach</tbody>
        </table>
    @endif

    @if($document->document_type === 'PURCHASE_ORDER' && $document->attachments->isNotEmpty())
        <h2>Dokumentasi Penerimaan</h2>
        <div class="photos">
            @foreach($document->attachments as $attachment)
                <div class="photo"><img src="{{ route('document-attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}"><small><strong>{{ $attachment->original_name }}</strong><br>{{ $attachment->caption ?: 'Tanpa keterangan' }} · {{ $attachment->created_at->format('d/m/Y H:i') }}</small></div>
            @endforeach
        </div>
    @endif

    <div class="approval">
        <div class="signature"><strong>Dibuat / Diminta Oleh</strong>Nama & Tanda Tangan</div>
        <div class="signature"><strong>Diperiksa / Disetujui Oleh</strong>Nama & Tanda Tangan</div>
        <div class="signature"><strong>{{ $document->document_type === 'PURCHASE_ORDER' ? 'Diterima Oleh' : 'Diproses Oleh' }}</strong>Nama & Tanda Tangan</div>
    </div>
    <footer class="footer"><span>Dicetak dari Web SAP pada {{ $generatedAt->format('d/m/Y H:i') }}</span><span>Portal ID #{{ $document->id }}</span></footer>
</main>
</body>
</html>
