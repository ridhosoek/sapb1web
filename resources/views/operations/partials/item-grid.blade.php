@php
    $isPurchaseRequest = $context === 'purchase-request';
    $lines = old('lines', [['quantity' => 1, 'requested_quantity' => 1]]);
@endphp

<div class="table-responsive">
    <table class="table table-operational detail-grid" data-item-grid aria-label="Detail barang">
        <thead>
        <tr>
            <th style="width: 42px">#</th>
            <th style="min-width: 300px">Item No</th>
            <th style="min-width: 200px">Description</th>
            @if($isPurchaseRequest)
                <th style="width: 120px">Required Qty</th>
                <th style="width: 90px">UoM</th>
                <th style="min-width: 260px">Free Text</th>
            @else
                <th style="min-width: 300px">Tipe Pengeluaran / COA</th>
                <th style="width: 120px">Qty FPK</th>
                <th style="width: 120px">Qty Issue</th>
                <th style="width: 120px">Available</th>
                <th style="min-width: 230px">Serial Number</th>
            @endif
            @unless($isPurchaseRequest)<th style="width: 90px">UoM</th>@endunless
            <th class="text-end" style="width: 90px">Action</th>
        </tr>
        </thead>
        <tbody>
        @foreach($lines as $index => $line)
            @php($selectedItem = $selectedItems->get((int) ($line['item_id'] ?? 0)))
            @php($selectedStock = $isPurchaseRequest ? null : $selectedItem?->warehouseStocks?->first())
            <tr>
                <td class="text-secondary">{{ $loop->iteration }}</td>
                <td>
                    <div class="item-combobox" data-item-combobox data-url="{{ route('lookups.items', ['context' => $context]) }}">
                        <input type="hidden" name="lines[{{ $index }}][item_id]" value="{{ $line['item_id'] ?? '' }}" data-item-value>
                        <button class="item-combobox-toggle" type="button" data-item-toggle aria-haspopup="listbox" aria-expanded="false">
                            <span data-item-label>{{ $selectedItem ? $selectedItem->item_code.' — '.$selectedItem->item_name : 'Pilih atau cari item' }}</span>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="item-combobox-dropdown d-none" data-item-dropdown>
                            <div class="item-combobox-search">
                                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                <input type="search" data-item-search placeholder="Cari ItemCode atau ItemName..." autocomplete="off" aria-label="Cari item">
                            </div>
                            <div class="item-combobox-results" data-item-results role="listbox"></div>
                            <button class="item-combobox-more d-none" type="button" data-item-more>Muat item berikutnya</button>
                        </div>
                        <div class="invalid-feedback">Pilih item terlebih dahulu.</div>
                    </div>
                </td>
                <td class="description-cell" data-item-description>{{ $selectedItem?->item_name ?? '—' }}</td>
                @if($isPurchaseRequest)
                    <td><input class="form-control form-control-sm" type="number" name="lines[{{ $index }}][quantity]" min="0.01" step="0.01" value="{{ $line['quantity'] ?? 1 }}" data-quantity required></td>
                    <td class="mono" data-item-uom>{{ $selectedItem?->inventory_uom ?? '—' }}</td>
                    <td><input class="form-control form-control-sm" name="lines[{{ $index }}][free_text]" maxlength="100" value="{{ $line['free_text'] ?? '' }}" placeholder="Keterangan baris (opsional)"></td>
                @else
                    <td>
                        <select class="form-select form-select-sm" name="lines[{{ $index }}][issue_type_id]" required>
                            <option value="">Pilih tipe dan COA</option>
                            @foreach($issueTypes as $issueType)
                                @php($accountCode = config("sap.goods_issue.account_codes.{$issueType->code}"))
                                <option value="{{ $issueType->id }}" @selected((string) ($line['issue_type_id'] ?? '') === (string) $issueType->id) @disabled(blank($accountCode))>
                                    {{ $issueType->code }} — {{ $issueType->name }}{{ blank($accountCode) ? ' (COA belum dipetakan)' : ' / '.$accountCode }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td><input class="form-control form-control-sm" type="number" name="lines[{{ $index }}][requested_quantity]" min="0.01" step="0.01" value="{{ $line['requested_quantity'] ?? 1 }}" required></td>
                    <td><input class="form-control form-control-sm" type="number" name="lines[{{ $index }}][quantity]" min="0.01" step="0.01" max="{{ $selectedStock?->available_stock }}" value="{{ $line['quantity'] ?? 1 }}" data-quantity required></td>
                    <td class="mono text-end" data-item-available>{{ $selectedStock ? number_format((float) $selectedStock->available_stock, 2, ',', '.') : '—' }}</td>
                    <td><textarea class="form-control form-control-sm mono-field" name="lines[{{ $index }}][serial_numbers]" rows="1" maxlength="2000" placeholder="Wajib untuk ban">{{ is_array($line['serial_numbers'] ?? null) ? implode(PHP_EOL, $line['serial_numbers']) : ($line['serial_numbers'] ?? '') }}</textarea></td>
                @endif
                @unless($isPurchaseRequest)<td class="mono" data-item-uom>{{ $selectedItem?->inventory_uom ?? '—' }}</td>@endunless
                <td class="text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-line aria-label="Hapus baris"><i class="fa-solid fa-trash"></i></button></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<template data-line-template>
    <tr>
        <td class="text-secondary">+</td>
        <td>
            <div class="item-combobox" data-item-combobox data-url="{{ route('lookups.items', ['context' => $context]) }}">
                <input type="hidden" name="lines[__INDEX__][item_id]" data-item-value>
                <button class="item-combobox-toggle" type="button" data-item-toggle aria-haspopup="listbox" aria-expanded="false">
                    <span data-item-label>Pilih atau cari item</span>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </button>
                <div class="item-combobox-dropdown d-none" data-item-dropdown>
                    <div class="item-combobox-search">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" data-item-search placeholder="Cari ItemCode atau ItemName..." autocomplete="off" aria-label="Cari item">
                    </div>
                    <div class="item-combobox-results" data-item-results role="listbox"></div>
                    <button class="item-combobox-more d-none" type="button" data-item-more>Muat item berikutnya</button>
                </div>
                <div class="invalid-feedback">Pilih item terlebih dahulu.</div>
            </div>
        </td>
        <td class="description-cell" data-item-description>—</td>
        @if($isPurchaseRequest)
            <td><input class="form-control form-control-sm" type="number" name="lines[__INDEX__][quantity]" min="0.01" step="0.01" value="1" data-quantity required></td>
            <td class="mono" data-item-uom>—</td>
            <td><input class="form-control form-control-sm" name="lines[__INDEX__][free_text]" maxlength="100" placeholder="Keterangan baris (opsional)"></td>
        @else
            <td>
                <select class="form-select form-select-sm" name="lines[__INDEX__][issue_type_id]" required>
                    <option value="">Pilih tipe dan COA</option>
                    @foreach($issueTypes as $issueType)
                        @php($accountCode = config("sap.goods_issue.account_codes.{$issueType->code}"))
                        <option value="{{ $issueType->id }}" @disabled(blank($accountCode))>
                            {{ $issueType->code }} — {{ $issueType->name }}{{ blank($accountCode) ? ' (COA belum dipetakan)' : ' / '.$accountCode }}
                        </option>
                    @endforeach
                </select>
            </td>
            <td><input class="form-control form-control-sm" type="number" name="lines[__INDEX__][requested_quantity]" min="0.01" step="0.01" value="1" required></td>
            <td><input class="form-control form-control-sm" type="number" name="lines[__INDEX__][quantity]" min="0.01" step="0.01" value="1" data-quantity required></td>
            <td class="mono text-end" data-item-available>—</td>
            <td><textarea class="form-control form-control-sm mono-field" name="lines[__INDEX__][serial_numbers]" rows="1" maxlength="2000" placeholder="Wajib untuk ban"></textarea></td>
        @endif
        @unless($isPurchaseRequest)<td class="mono" data-item-uom>—</td>@endunless
        <td class="text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-line aria-label="Hapus baris"><i class="fa-solid fa-trash"></i></button></td>
    </tr>
</template>
