@php
    $formatQuantity = fn (mixed $quantity): string => is_numeric($quantity)
        ? rtrim(rtrim(number_format((float) $quantity, 2, ',', '.'), '0'), ',')
        : '—';
@endphp

<section class="operational-panel mb-3">
    <header class="panel-header"><h2 class="panel-title">Detail Baris</h2><span class="badge text-bg-light border">{{ $lines->count() }} baris</span></header>
    @if($lines->isEmpty())
        <div class="empty-state"><i class="fa-regular fa-file-lines"></i>Dokumen ini tidak memiliki detail baris pada cache portal.</div>
    @else
        <div class="pr-lines" data-goods-issue-lines>
            <div class="pr-lines-head" aria-hidden="true">
                <span>#</span><span>Item</span><span class="text-end">Qty</span><span>Nopol</span><span class="text-end">KM Akhir</span><span>Tipe Pengeluaran</span>
            </div>
            @foreach($lines as $index => $line)
                @php
                    $item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null;
                    $inventoryUom = $item?->inventory_uom ?? ($line['inventory_uom'] ?? null);
                    $vehicleCode = data_get($line, 'vehicle_cost_center.center_code') ?? data_get($normalizedData, 'vehicle_cost_center.center_code');
                    $vehicleName = data_get($line, 'vehicle_cost_center.center_name');
                    $endingKm = $line['ending_km'] ?? ($normalizedData['ending_km'] ?? null);
                    $issueTypeCode = data_get($line, 'issue_type.code');
                    $issueTypeName = data_get($line, 'issue_type.name');
                    $accountCode = data_get($line, 'issue_type.account_code');
                    $selectedSerials = data_get($line, 'serial_selection.serials') ?: ($line['serial_numbers'] ?? []);
                    $serialValues = is_array($selectedSerials) ? $selectedSerials : preg_split('/\r\n|\r|\n/', (string) $selectedSerials);
                    $serialLabels = collect($serialValues)->map(fn (mixed $serial): string => trim((string) (is_array($serial)
                        ? ($serial['internal_serial'] ?? $serial['internal_serial_number'] ?? $serial['manufacturer_serial_number'] ?? $serial['system_number'] ?? '')
                        : $serial)))->filter()->values();
                    $hasDetails = $serialLabels->isNotEmpty()
                        || filled($accountCode)
                        || filled($line['requested_quantity'] ?? null)
                        || filled($line['free_text'] ?? null)
                        || (filled($vehicleName) && $vehicleName !== $vehicleCode)
                        || filled($line['warehouse_code'] ?? null)
                        || $item;
                @endphp
                <article class="pr-line" data-goods-issue-line>
                    <div class="pr-line-no">{{ $index + 1 }}</div>
                    <div class="pr-line-item">
                        <div class="pr-line-code">{{ $item?->item_code ?? $line['item_code'] ?? '—' }}</div>
                        <div class="pr-line-name">{{ $item?->item_name ?? $line['item_name'] ?? '—' }}</div>
                    </div>
                    <div class="pr-line-qty pr-line-number">
                        <span class="pr-line-label">Qty</span>
                        <strong>{{ $formatQuantity($line['quantity'] ?? null) }}</strong>
                        @if(filled($inventoryUom))<span class="pr-line-uom">{{ $inventoryUom }}</span>@endif
                    </div>
                    <div class="pr-line-vehicle">
                        <span class="pr-line-label">Nopol</span>
                        @if(filled($vehicleCode))<span class="pr-line-code">{{ $vehicleCode }}</span>@else<span class="pr-line-empty">—</span>@endif
                    </div>
                    <div class="pr-line-km pr-line-number">
                        <span class="pr-line-label">KM akhir</span>
                        @if(is_numeric($endingKm)){{ number_format((int) $endingKm, 0, ',', '.') }}@else<span class="pr-line-empty">—</span>@endif
                    </div>
                    <div class="pr-line-note">
                        <span class="pr-line-label">Tipe pengeluaran</span>
                        @if(filled($issueTypeCode) || filled($issueTypeName)){{ collect([$issueTypeCode, $issueTypeName])->filter(fn (mixed $value): bool => filled($value))->join(' — ') }}@else<span class="pr-line-empty">—</span>@endif
                    </div>
                    @if($hasDetails)
                        <details class="pr-line-more">
                            <summary>Rincian</summary>
                            <dl>
                                @if(filled($line['requested_quantity'] ?? null))
                                    <div><dt>Qty diminta</dt><dd>{{ $formatQuantity($line['requested_quantity']) }}</dd></div>
                                @endif
                                @if(filled($vehicleName) && $vehicleName !== $vehicleCode)
                                    <div><dt>Nama kendaraan</dt><dd>{{ $vehicleName }}</dd></div>
                                @endif
                                @if(filled($line['warehouse_code'] ?? null))
                                    <div><dt>Gudang</dt><dd>{{ $line['warehouse_code'] }}</dd></div>
                                @endif
                                @if(filled($line['free_text'] ?? null))
                                    <div><dt>Keterangan</dt><dd>{{ $line['free_text'] }}</dd></div>
                                @endif
                                @if($serialLabels->isNotEmpty())
                                    <div><dt>Serial Number</dt><dd class="mono">{{ $serialLabels->join(', ') }}</dd></div>
                                @endif
                                @if(filled($accountCode))
                                    <div><dt>Akun G/L</dt><dd class="mono">{{ $accountCode }}</dd></div>
                                @endif
                                @if($item)
                                    <div><dt>Item Master</dt><dd><a href="{{ route('item-master.show', $item) }}">Buka {{ $item->item_code }}</a></dd></div>
                                @endif
                            </dl>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>
