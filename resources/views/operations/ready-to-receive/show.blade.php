@extends('layouts.app')

@section('title', 'Goods Receipt PO')
@section('page-title', 'Goods Receipt PO')
@section('page-subtitle', 'Terima barang Purchase Order '.$purchaseOrder->sap_doc_num.' ke SAP dan simpan bukti penerimaannya di portal.')

@section('page-actions')
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('documents.print', $purchaseOrder) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print me-1"></i> Cetak Form GRPO</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('ready-to-receive.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
    </div>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>{{ session('status') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><strong>Goods Receipt PO belum diproses.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="alert alert-info py-2">
        <i class="fa-solid fa-shield-halved me-1"></i>
        Data penerimaan dikirim ke SAP sebagai <strong>Goods Receipt PO</strong>. Foto tetap disimpan privat di Web SAP dan <strong>tidak dikirim</strong> ke attachment SAP Business One.
    </div>
    @if($serialConfigurationWarning)
        <div class="alert alert-warning py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ $serialConfigurationWarning }}</div>
    @endif
    @if($itemMetadataWarning)
        <div class="alert alert-warning py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ $itemMetadataWarning }}</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-xl-7">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Referensi Purchase Order</h2><span class="badge {{ $goodsReceipt ? 'text-bg-success' : 'text-bg-light border' }}">{{ $goodsReceipt ? 'GRPO Dibuat' : 'Siap GRPO' }}</span></header>
                <div class="transaction-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4"><label class="form-label">Nomor PO</label><div class="readonly-value mono-field">{{ $purchaseOrder->sap_doc_num ?? '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">DocEntry</label><div class="readonly-value mono-field">{{ $purchaseOrder->sap_doc_entry ?? '—' }}</div></div>
                        <div class="col-sm-6 col-lg-4"><label class="form-label">FPK</label><div class="readonly-value mono-field">{{ $purchaseOrder->fpk_number ?? '—' }}</div></div>
                        <div class="col-12"><label class="form-label">Vendor</label><div class="readonly-value">{{ data_get($normalizedData, 'vendor_name', data_get($normalizedData, 'document.CardName', '—')) }}</div></div>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-xl-5">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>Konteks Penerimaan</h2><i class="fa-solid fa-lock text-secondary"></i></header>
                <div class="transaction-body">
                    <dl class="row mb-0 detail-list">
                        <dt class="col-5">Database</dt><dd class="col-7 mono">{{ $purchaseOrder->company_db }}</dd>
                        <dt class="col-5">Pool</dt><dd class="col-7 mono">{{ $purchaseOrder->pool?->code ?? '—' }}</dd>
                        <dt class="col-5">Department</dt><dd class="col-7 mono">{{ $purchaseOrder->department?->code ?? '—' }}</dd>
                        <dt class="col-5">Status PO</dt><dd class="col-7">{{ str($purchaseOrder->sap_business_status->value)->title() }}</dd>
                        <dt class="col-5">Nomor GRPO</dt><dd class="col-7 mono">{{ $goodsReceipt?->sap_doc_num ?? 'Belum dibuat' }}</dd>
                        <dt class="col-5">Terakhir sync</dt><dd class="col-7">{{ $purchaseOrder->updated_at->format('d/m/Y H:i') }}</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Barang yang Akan Diterima</h2><span class="badge text-bg-light border">{{ $lines->count() }} baris</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle mb-0">
                <thead><tr><th>#</th><th>Item</th><th>Description</th><th>Nopol</th><th>Detail</th><th class="text-end">Order Qty</th><th class="text-end">Open Qty</th><th style="min-width: 130px">Qty Diterima</th><th style="min-width: 250px">Serial Numbers — Setup</th><th>UoM</th><th>Warehouse</th></tr></thead>
                <tbody>
                @forelse($lines as $index => $line)
                    @php
                        $itemCode = $line['item_code'] ?? $line['ItemCode'] ?? '';
                        $item = $items->get($itemCode);
                        $serialRequirement = $item?->serialReceiptRequirement();
                        $lineNumber = (int) ($line['LineNum'] ?? $line['line_num'] ?? $index);
                        $openQuantity = (float) ($line['open_quantity'] ?? $line['RemainingOpenQuantity'] ?? $line['OpenQty'] ?? $line['quantity'] ?? $line['Quantity'] ?? 0);
                        $savedSerials = data_get($goodsReceipt?->normalized_data, 'lines.'.$index.'.serial_numbers', []);
                        $serialRecords = old('lines.'.$index.'.serial_numbers', is_array($savedSerials) ? $savedSerials : []);
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="mono fw-semibold">{{ $item?->item_code ?? $line['item_code'] ?? $line['ItemCode'] ?? '—' }}</td>
                        <td>{{ $item?->item_name ?? $line['item_name'] ?? $line['description'] ?? $line['ItemDescription'] ?? $line['Dscription'] ?? '—' }}</td>
                        <td class="mono">{{ $line['vehicle_code'] ?? $line[config('sap.purchase_request.vehicle_costing_field')] ?? '—' }}</td>
                        <td>
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#item-detail-modal"
                                data-item-detail
                                data-item-code="{{ $item?->item_code ?? $itemCode }}"
                                data-item-name="{{ $item?->item_name ?? $line['ItemDescription'] ?? '—' }}"
                                data-item-group="{{ $item?->item_group_name ?: '—' }}"
                                data-item-group-code="{{ $item?->item_group_code ?? '—' }}"
                                data-item-uom="{{ $item?->inventory_uom ?? '—' }}"
                                data-item-inventory="{{ $item?->is_inventory_item ? 'Ya' : 'Tidak' }}"
                                data-item-purchase="{{ $item?->is_purchase_item ? 'Ya' : 'Tidak' }}"
                                data-item-serial="{{ $serialRequirement?->label() ?? 'Belum diketahui' }}"
                                data-item-method="{{ $item?->serial_management_method ?: '—' }}"
                                data-item-metadata="{{ $item?->metadataStatusLabel() ?? 'Belum tersedia' }}"
                                data-item-synced="{{ $item?->synced_at?->format('d/m/Y H:i') ?? '—' }}"
                            ><i class="fa-solid fa-circle-info me-1"></i> Lihat</button>
                        </td>
                        <td class="text-end mono">{{ number_format((float) ($line['quantity'] ?? $line['Quantity'] ?? 0), 2, ',', '.') }}</td>
                        <td class="text-end mono">{{ number_format($openQuantity, 2, ',', '.') }}</td>
                        <td>
                            @if($goodsReceipt)
                                <span class="mono">{{ number_format((float) data_get($goodsReceipt->normalized_data, 'lines.'.$index.'.quantity', 0), 2, ',', '.') }}</span>
                            @else
                                <input form="goods-receipt-form" type="hidden" name="lines[{{ $index }}][line_num]" value="{{ $lineNumber }}">
                                <input form="goods-receipt-form" class="form-control form-control-sm @error('lines.'.$index.'.quantity') is-invalid @enderror" type="number" name="lines[{{ $index }}][quantity]" min="0.000001" max="{{ $openQuantity }}" step="0.000001" value="{{ old('lines.'.$index.'.quantity', $openQuantity) }}" data-receipt-quantity required>
                            @endif
                        </td>
                        <td>
                            @if($goodsReceipt)
                                <div class="serial-record-list">
                                    @forelse($savedSerials as $serialNumber)
                                        @php
                                            $serialLabel = is_array($serialNumber)
                                                ? collect([
                                                    $serialNumber['manufacturer_serial_number'] ?? null,
                                                    $serialNumber['internal_serial_number'] ?? null,
                                                    $serialNumber['lot_number'] ?? null,
                                                ])->filter()->join(' / ')
                                                : (string) $serialNumber;
                                        @endphp
                                        <span class="badge text-bg-light border mono">{{ $serialLabel ?: 'Tanpa identitas' }}</span>
                                    @empty
                                        <span class="text-secondary">—</span>
                                    @endforelse
                                </div>
                            @elseif($serialRequirement === \App\Domain\Sap\Enums\SerialReceiptRequirement::SerialRequired)
                                <div
                                    data-serial-line
                                    data-line-index="{{ $index }}"
                                    data-item-code="{{ $itemCode }}"
                                    data-item-name="{{ $item?->item_name ?? $line['ItemDescription'] ?? '—' }}"
                                    data-warehouse="{{ $line['warehouse_code'] ?? $line['WarehouseCode'] ?? '—' }}"
                                    data-po-number="{{ $purchaseOrder->sap_doc_num }}"
                                >
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-serial-setup>
                                            <i class="fa-solid fa-barcode me-1"></i> Atur Serial
                                        </button>
                                        <span class="badge text-bg-warning" data-serial-status>0 / 0 dibuat</span>
                                    </div>
                                    <div class="form-text">Wajib untuk item serial-managed. Data diisi satu kali sebelum GRPO dikirim.</div>
                                    <div data-serial-hidden-inputs>
                                        @foreach(is_array($serialRecords) ? $serialRecords : [] as $serialIndex => $serialNumber)
                                            @php($serialNumber = is_array($serialNumber) ? $serialNumber : ['internal_serial_number' => $serialNumber])
                                            <div data-serial-record>
                                                @foreach(['manufacturer_serial_number', 'internal_serial_number', 'lot_number', 'expiry_date', 'manufacture_date', 'reception_date', 'location', 'notes'] as $serialField)
                                                    <input form="goods-receipt-form" type="hidden" name="lines[{{ $index }}][serial_numbers][{{ $serialIndex }}][{{ $serialField }}]" value="{{ $serialNumber[$serialField] ?? '' }}" data-serial-field="{{ $serialField }}">
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                    @error('lines.'.$index.'.serial_numbers')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                </div>
                            @elseif($serialRequirement === \App\Domain\Sap\Enums\SerialReceiptRequirement::NonSerial)
                                <span class="small text-secondary">Tidak dikelola serial</span>
                            @elseif($serialRequirement === \App\Domain\Sap\Enums\SerialReceiptRequirement::SerialNotRequiredOnReceipt)
                                <span class="small text-secondary">Serial dikelola SAP ({{ $item->serial_management_method }}); tidak diminta saat GRPO</span>
                            @elseif($serialRequirement === \App\Domain\Sap\Enums\SerialReceiptRequirement::Unsupported)
                                <span class="badge text-bg-danger">Konfigurasi serial belum didukung</span>
                                <div class="form-text">Metode {{ $item->serial_management_method }} belum didukung untuk item BAN. GRPO akan ditolak; hubungi administrator SAP.</div>
                            @elseif($item?->hasCompleteMasterMetadata())
                                <span class="badge text-bg-warning">Serial belum dapat dipastikan</span>
                                <div class="form-text">Konfigurasi serial item tidak terbaca dengan jelas dari SAP. GRPO akan ditolak sampai metadata dapat dibaca.</div>
                            @else
                                <span class="badge text-bg-warning">Metadata belum tersedia</span>
                            @endif
                        </td>
                        <td class="mono">{{ $item?->inventory_uom ?? $line['uom'] ?? $line['UoMCode'] ?? $line['MeasureUnit'] ?? '—' }}</td>
                        <td class="mono">{{ $line['warehouse_code'] ?? $line['WarehouseCode'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11"><div class="empty-state"><i class="fa-regular fa-file-lines"></i>Detail item Purchase Order belum tersedia pada cache portal.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="modal fade" id="item-detail-modal" tabindex="-1" aria-labelledby="item-detail-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="item-detail-title">Detail Item Master</h2>
                        <div class="small text-secondary">Metadata item dibaca langsung dari database SAP yang sedang dipilih.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <dl class="row mb-0 detail-list">
                        <dt class="col-sm-4">Item Code</dt><dd class="col-sm-8 mono" data-item-detail-value="code">—</dd>
                        <dt class="col-sm-4">Nama Item</dt><dd class="col-sm-8" data-item-detail-value="name">—</dd>
                        <dt class="col-sm-4">Item Group</dt><dd class="col-sm-8"><span data-item-detail-value="group">—</span> <span class="text-secondary mono" data-item-detail-value="group-code"></span></dd>
                        <dt class="col-sm-4">Inventory UoM</dt><dd class="col-sm-8 mono" data-item-detail-value="uom">—</dd>
                        <dt class="col-sm-4">Inventory Item</dt><dd class="col-sm-8" data-item-detail-value="inventory">—</dd>
                        <dt class="col-sm-4">Purchase Item</dt><dd class="col-sm-8" data-item-detail-value="purchase">—</dd>
                        <dt class="col-sm-4">Manage Item By</dt><dd class="col-sm-8" data-item-detail-value="serial">—</dd>
                        <dt class="col-sm-4">Management Method</dt><dd class="col-sm-8 mono" data-item-detail-value="method">—</dd>
                        <dt class="col-sm-4">Status Metadata</dt><dd class="col-sm-8" data-item-detail-value="metadata">—</dd>
                        <dt class="col-sm-4">Terakhir Dibaca</dt><dd class="col-sm-8" data-item-detail-value="synced">—</dd>
                    </dl>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    @unless($goodsReceipt)
        <div class="modal fade" id="serial-number-setup-modal" tabindex="-1" aria-labelledby="serial-number-setup-title" aria-hidden="true" data-serial-unique-field="{{ $serialUniqueField }}" data-serial-unique-label="{{ $serialUniqueFieldLabel }}">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="serial-number-setup-title">Serial Numbers — Setup</h2>
                            <div class="small text-secondary">Buat satu record serial lengkap untuk setiap unit yang diterima.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="serial-setup-summary mb-3">
                            <div><span>PO</span><strong class="mono" data-serial-summary="po"></strong></div>
                            <div><span>Item</span><strong class="mono" data-serial-summary="item"></strong></div>
                            <div><span>Description</span><strong data-serial-summary="description"></strong></div>
                            <div><span>Warehouse</span><strong class="mono" data-serial-summary="warehouse"></strong></div>
                            <div><span>Total Needed</span><strong class="mono" data-serial-summary="needed"></strong></div>
                            <div><span>Total Created</span><strong class="mono" data-serial-summary="created"></strong></div>
                        </div>
                        <div class="alert alert-info py-2 small">
                            Kolom unik SAP: <strong data-serial-policy-label>{{ $serialUniqueFieldLabel }}</strong>.
                            Nomor sistem SAP tidak diisi karena GRPO membuat serial baru.
                        </div>
                        <div class="serial-bulk-entry mb-3">
                            <label class="form-label" for="serial-bulk-values">Tempel serial sekaligus</label>
                            <div class="input-group">
                                <textarea class="form-control mono-field" id="serial-bulk-values" rows="2" placeholder="Satu nilai per baris"></textarea>
                                <button class="btn btn-outline-secondary" type="button" data-serial-apply-bulk>Terapkan ke {{ $serialUniqueField === 'none' || $serialUniqueField === 'unknown' ? 'Serial Number' : $serialUniqueFieldLabel }}</button>
                            </div>
                        </div>
                        <div class="table-responsive serial-setup-table-wrap">
                            <table class="table table-sm table-bordered align-middle serial-setup-table mb-0">
                                <thead><tr><th>#</th><th data-serial-column="manufacturer_serial_number">Mfr Serial No.</th><th data-serial-column="internal_serial_number">Serial Number</th><th data-serial-column="lot_number">Lot Number</th><th>Expiration Date</th><th>Mfr Date</th><th>Admission Date</th><th>Location</th><th>Notes</th></tr></thead>
                                <tbody data-serial-modal-rows></tbody>
                            </table>
                        </div>
                        <div class="invalid-feedback d-none mt-2" data-serial-modal-error></div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <span class="small text-secondary"><strong data-serial-footer-created>0</strong> dari <strong data-serial-footer-needed>0</strong> serial dibuat</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="button" class="btn btn-primary" data-serial-save><i class="fa-solid fa-check me-1"></i> Terapkan Serial</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endunless

    <div class="row g-3">
        @can('create', \App\Models\SapDocument::class)
        <div class="col-xl-4">
            <section class="transaction-section h-100">
                <header class="section-heading"><h2>{{ $goodsReceipt ? 'Tambah Dokumentasi' : 'Buat GRPO dan Dokumentasi' }}</h2><span class="badge text-bg-light border">Foto Portal Only</span></header>
                <form id="goods-receipt-form" class="transaction-body" method="POST" action="{{ $goodsReceipt ? route('ready-to-receive.attachments.store', $purchaseOrder) : route('ready-to-receive.receive', $purchaseOrder) }}" enctype="multipart/form-data">
                    @csrf
                    @unless($goodsReceipt)
                    <div class="mb-3">
                        <label class="form-label" for="receipt-date">Tanggal Penerimaan *</label>
                        <input class="form-control @error('receipt_date') is-invalid @enderror" id="receipt-date" name="receipt_date" type="date" max="{{ now()->toDateString() }}" value="{{ old('receipt_date', now()->toDateString()) }}" required>
                        <div class="form-text">Tanggal ini menjadi Posting Date, Due Date, dan Document Date GRPO di SAP.</div>
                    </div>
                    @endunless
                    <div class="mb-3">
                        <label class="form-label" for="receipt-images">Foto Barang / Surat Jalan {{ $goodsReceipt ? '*' : '(opsional)' }}</label>
                        <input class="form-control @error('images') is-invalid @enderror @error('images.*') is-invalid @enderror" id="receipt-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple {{ $goodsReceipt ? 'required' : '' }}>
                        <div class="form-text">JPG, PNG, atau WebP. Maksimal {{ config('sap.documents.goods_receipt_attachments.max_files_per_upload', 5) }} foto, masing-masing {{ number_format(config('sap.documents.goods_receipt_attachments.max_size_kb', 5120) / 1024, 0) }} MB.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="receipt-caption">Keterangan</label>
                        <textarea class="form-control" id="receipt-caption" name="caption" rows="3" maxlength="255" placeholder="Contoh: Barang diterima lengkap dan kondisi baik">{{ old('caption') }}</textarea>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="fa-solid {{ $goodsReceipt ? 'fa-cloud-arrow-up' : 'fa-truck-ramp-box' }} me-1"></i>
                        {{ $goodsReceipt ? 'Simpan Dokumentasi' : 'Terima Barang & Kirim GRPO ke SAP' }}
                    </button>
                </form>
            </section>
        </div>
        @endcan
        <div class="{{ auth()->user()->can('create', \App\Models\SapDocument::class) ? 'col-xl-8' : 'col-12' }}">
            <section class="operational-panel h-100">
                <header class="panel-header"><h2 class="panel-title">Galeri Dokumentasi</h2><span class="badge text-bg-light border">{{ $attachments->count() }} foto</span></header>
                <div class="panel-body">
                    @if($attachments->isEmpty())
                        <div class="empty-state"><i class="fa-regular fa-images"></i>Belum ada foto penerimaan untuk Purchase Order ini.</div>
                    @else
                        <div class="receipt-gallery">
                            @foreach($attachments as $attachment)
                                <figure class="receipt-photo-card">
                                    <a href="{{ route('document-attachments.show', $attachment) }}" target="_blank" rel="noopener">
                                        <img src="{{ route('document-attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy">
                                    </a>
                                    <figcaption>
                                        <strong title="{{ $attachment->original_name }}">{{ $attachment->original_name }}</strong>
                                        <span>{{ $attachment->caption ?: 'Tanpa keterangan' }}</span>
                                        <small>{{ $attachment->uploader?->name ?? 'User tidak tersedia' }} · {{ $attachment->created_at->format('d/m/Y H:i') }}</small>
                                    </figcaption>
                                </figure>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const itemDetailModal = document.getElementById('item-detail-modal');

    itemDetailModal?.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;

        if (! button?.matches('[data-item-detail]')) {
            return;
        }

        ['code', 'name', 'group', 'group-code', 'uom', 'inventory', 'purchase', 'serial', 'method', 'metadata', 'synced']
            .forEach((field) => {
                const target = itemDetailModal.querySelector(`[data-item-detail-value="${field}"]`);
                const datasetKey = `item${field.split('-').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('')}`;

                if (target) {
                    target.textContent = button.dataset[datasetKey] || '—';
                }
            });

        const groupCode = itemDetailModal.querySelector('[data-item-detail-value="group-code"]');

        if (groupCode) {
            groupCode.textContent = button.dataset.itemGroupCode && button.dataset.itemGroupCode !== '—'
                ? `(Code ${button.dataset.itemGroupCode})`
                : '';
        }
    });

    const modalElement = document.getElementById('serial-number-setup-modal');
    const receiptForm = document.getElementById('goods-receipt-form');

    if (! modalElement || ! receiptForm) {
        return;
    }

    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
    const modalRows = modalElement.querySelector('[data-serial-modal-rows]');
    const modalError = modalElement.querySelector('[data-serial-modal-error]');
    const serialFields = [
        'manufacturer_serial_number',
        'internal_serial_number',
        'lot_number',
        'expiry_date',
        'manufacture_date',
        'reception_date',
        'location',
        'notes',
    ];
    const identifierFields = serialFields.slice(0, 3);
    const uniqueField = identifierFields.includes(modalElement.dataset.serialUniqueField)
        ? modalElement.dataset.serialUniqueField
        : null;
    const bulkField = uniqueField || 'internal_serial_number';
    let activeLine = null;

    const requiredQuantity = (line) => {
        const quantity = Number(line.closest('tr').querySelector('[data-receipt-quantity]').value);

        return Number.isInteger(quantity) && quantity > 0 ? quantity : null;
    };

    const hiddenRecords = (line) => Array.from(line.querySelectorAll('[data-serial-record]')).map((record) => {
        const values = {};

        serialFields.forEach((field) => {
            values[field] = record.querySelector(`[data-serial-field="${field}"]`)?.value.trim() || '';
        });

        return values;
    });

    const recordIsComplete = (record) => uniqueField
        ? Boolean(record[uniqueField]?.trim())
        : identifierFields.some((field) => Boolean(record[field]?.trim()));

    const updateLineStatus = (line) => {
        const needed = requiredQuantity(line);
        const records = hiddenRecords(line);
        const created = records.filter(recordIsComplete).length;
        const status = line.querySelector('[data-serial-status]');
        const complete = needed !== null && records.length === needed && created === needed;

        status.textContent = `${created} / ${needed ?? '?'} dibuat`;
        status.classList.toggle('text-bg-success', complete);
        status.classList.toggle('text-bg-warning', ! complete);

        return complete;
    };

    const showModalError = (message = '') => {
        modalError.textContent = message;
        modalError.classList.toggle('d-none', message === '');
    };

    const createField = (field, value) => {
        const input = document.createElement('input');
        input.className = 'form-control form-control-sm';
        input.dataset.serialModalField = field;
        input.value = value || '';

        if (field.endsWith('_date')) {
            input.type = 'date';
        } else {
            input.type = 'text';
            input.maxLength = field === 'location' ? 100 : (field === 'notes' ? 254 : 32);
        }

        if (field === uniqueField) {
            input.required = true;
            input.setAttribute('aria-label', `${modalElement.dataset.serialUniqueLabel} wajib`);
        }

        return input;
    };

    const appendModalRow = (record, rowIndex) => {
        const row = document.createElement('tr');
        const sequence = document.createElement('td');
        sequence.className = 'text-center mono';
        sequence.textContent = String(rowIndex + 1);
        row.append(sequence);

        serialFields.forEach((field) => {
            const cell = document.createElement('td');
            cell.append(createField(field, record[field]));
            row.append(cell);
        });

        modalRows.append(row);
    };

    const modalRecords = () => Array.from(modalRows.querySelectorAll('tr')).map((row) => {
        const record = {};

        serialFields.forEach((field) => {
            record[field] = row.querySelector(`[data-serial-modal-field="${field}"]`).value.trim();
        });

        return record;
    });

    const updateModalCounts = () => {
        const needed = activeLine ? requiredQuantity(activeLine) : 0;
        const created = modalRecords().filter(recordIsComplete).length;

        modalElement.querySelector('[data-serial-summary="created"]').textContent = String(created);
        modalElement.querySelector('[data-serial-footer-created]').textContent = String(created);
        modalElement.querySelector('[data-serial-footer-needed]').textContent = String(needed ?? 0);
    };

    const openSetup = (line, message = '') => {
        const needed = requiredQuantity(line);

        if (needed === null) {
            line.closest('tr').querySelector('[data-receipt-quantity]').setCustomValidity('Qty item serial harus berupa bilangan bulat lebih dari nol.');
            line.closest('tr').querySelector('[data-receipt-quantity]').reportValidity();

            return;
        }

        line.closest('tr').querySelector('[data-receipt-quantity]').setCustomValidity('');
        activeLine = line;
        const currentRecords = hiddenRecords(line);
        modalRows.replaceChildren();

        for (let index = 0; index < needed; index += 1) {
            appendModalRow(currentRecords[index] || {}, index);
        }

        modalElement.querySelector('[data-serial-summary="po"]').textContent = line.dataset.poNumber;
        modalElement.querySelector('[data-serial-summary="item"]').textContent = line.dataset.itemCode;
        modalElement.querySelector('[data-serial-summary="description"]').textContent = line.dataset.itemName;
        modalElement.querySelector('[data-serial-summary="warehouse"]').textContent = line.dataset.warehouse;
        modalElement.querySelector('[data-serial-summary="needed"]').textContent = String(needed);
        modalElement.querySelector('#serial-bulk-values').value = '';
        showModalError(message);
        updateModalCounts();
        modal.show();
    };

    const saveHiddenRecords = (line, records) => {
        const container = line.querySelector('[data-serial-hidden-inputs]');
        container.replaceChildren();

        records.forEach((record, serialIndex) => {
            const wrapper = document.createElement('div');
            wrapper.dataset.serialRecord = '';

            serialFields.forEach((field) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.setAttribute('form', 'goods-receipt-form');
                input.name = `lines[${line.dataset.lineIndex}][serial_numbers][${serialIndex}][${field}]`;
                input.value = record[field] || '';
                input.dataset.serialField = field;
                wrapper.append(input);
            });

            container.append(wrapper);
        });
    };

    const validateRecords = (records, needed) => {
        if (records.length !== needed) {
            return `Jumlah record serial harus tepat ${needed} sesuai Qty Diterima.`;
        }

        for (let index = 0; index < records.length; index += 1) {
            if (! recordIsComplete(records[index])) {
                return uniqueField
                    ? `${modalElement.dataset.serialUniqueLabel} wajib diisi pada baris ${index + 1}.`
                    : `Isi salah satu identitas serial pada baris ${index + 1}.`;
            }
        }

        const keys = records.map((record) => uniqueField
            ? record[uniqueField].toLocaleUpperCase('id-ID')
            : identifierFields.map((field) => record[field].toLocaleUpperCase('id-ID')).join('|'));

        if (new Set(keys).size !== keys.length) {
            return `${modalElement.dataset.serialUniqueLabel} tidak boleh duplikat.`;
        }

        return '';
    };

    modalElement.querySelectorAll('[data-serial-column]').forEach((heading) => {
        if (heading.dataset.serialColumn === uniqueField) {
            heading.classList.add('serial-required-column');
            heading.append(document.createTextNode(' *'));
        }
    });

    document.querySelectorAll('[data-serial-line]').forEach((line) => {
        updateLineStatus(line);
        line.querySelector('[data-serial-setup]').addEventListener('click', () => openSetup(line));
        line.closest('tr').querySelector('[data-receipt-quantity]').addEventListener('input', (event) => {
            event.target.setCustomValidity('');
            updateLineStatus(line);
        });
    });

    modalRows.addEventListener('input', updateModalCounts);

    modalElement.querySelector('[data-serial-apply-bulk]').addEventListener('click', () => {
        const values = modalElement.querySelector('#serial-bulk-values').value
            .split(/\r?\n|,|;/)
            .map((value) => value.trim())
            .filter(Boolean);
        const rows = Array.from(modalRows.querySelectorAll('tr'));

        if (values.length > rows.length) {
            showModalError(`Terdapat ${values.length} nilai, sedangkan Qty Diterima hanya ${rows.length}.`);

            return;
        }

        rows.forEach((row, index) => {
            if (values[index] !== undefined) {
                row.querySelector(`[data-serial-modal-field="${bulkField}"]`).value = values[index];
            }
        });
        showModalError();
        updateModalCounts();
    });

    modalElement.querySelector('[data-serial-save]').addEventListener('click', () => {
        const needed = requiredQuantity(activeLine);
        const records = modalRecords();
        const error = validateRecords(records, needed);

        if (error) {
            showModalError(error);

            return;
        }

        saveHiddenRecords(activeLine, records);
        updateLineStatus(activeLine);
        showModalError();
        modal.hide();
    });

    receiptForm.addEventListener('submit', (event) => {
        const incompleteLine = Array.from(document.querySelectorAll('[data-serial-line]'))
            .find((line) => ! updateLineStatus(line));

        if (! incompleteLine) {
            return;
        }

        event.preventDefault();
        openSetup(incompleteLine, 'Lengkapi seluruh serial sesuai Qty Diterima sebelum GRPO dikirim ke SAP.');
    });
});
</script>
@endpush
