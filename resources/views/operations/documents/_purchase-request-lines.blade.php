@php
    $formatQuantity = fn (mixed $quantity): string => is_numeric($quantity)
        ? rtrim(rtrim(number_format((float) $quantity, 2, ',', '.'), '0'), ',')
        : '—';
    $shownLineKeys = ['item_code', 'item_name', 'quantity', 'manual_uom', 'inventory_uom', 'ending_km', 'free_text'];
    $otherLineLabels = ['uom' => 'UoM', 'required_date' => 'Tanggal kebutuhan', 'warehouse_code' => 'Gudang', 'notes' => 'Catatan'];
@endphp

<section class="operational-panel mb-3">
    <header class="panel-header"><h2 class="panel-title">Detail Baris</h2><span class="badge text-bg-light border">{{ $lines->count() }} baris</span></header>
    @if($lines->isEmpty())
        <div class="empty-state"><i class="fa-regular fa-file-lines"></i>Dokumen ini tidak memiliki detail baris pada cache portal.</div>
    @else
        <div class="pr-lines" data-purchase-request-lines>
            <div class="pr-lines-head" aria-hidden="true">
                <span>#</span><span>Item</span><span class="text-end">Qty</span><span>Nopol</span><span class="text-end">KM Akhir</span><span>Keterangan</span>
            </div>
            @foreach($lines as $index => $line)
                @php
                    $item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null;
                    $inventoryUom = $item?->inventory_uom ?? ($line['inventory_uom'] ?? null);
                    $manualUom = filled($line['manual_uom'] ?? null) ? trim((string) $line['manual_uom']) : null;
                    $vehicleCode = data_get($line, 'vehicle_cost_center.center_code') ?? data_get($normalizedData, 'vehicle_cost_center.center_code');
                    $vehicleName = data_get($line, 'vehicle_cost_center.center_name');
                    $endingKm = $line['ending_km'] ?? ($normalizedData['ending_km'] ?? null);
                    $note = filled($line['free_text'] ?? null) ? trim((string) $line['free_text']) : null;
                    $secondary = collect([
                        'UoM stok' => $inventoryUom,
                        'UoM manual' => $manualUom,
                        'Nama kendaraan' => filled($vehicleName) && $vehicleName !== $vehicleCode ? $vehicleName : null,
                    ])->merge(
                        collect($line)
                            ->reject(fn (mixed $value, int|string $key): bool => is_array($value) || in_array($key, $shownLineKeys, true) || $key === 'id' || str_ends_with((string) $key, '_id'))
                            ->mapWithKeys(fn (mixed $value, int|string $key): array => [
                                $otherLineLabels[$key] ?? str((string) $key)->replace('_', ' ')->ucfirst()->toString() => is_bool($value) ? ($value ? 'Ya' : 'Tidak') : $value,
                            ])
                    )->filter(fn (mixed $value): bool => filled($value));
                @endphp
                <article class="pr-line" data-purchase-request-line>
                    <div class="pr-line-no">{{ $index + 1 }}</div>
                    <div class="pr-line-item">
                        <div class="pr-line-code">{{ $item?->item_code ?? $line['item_code'] ?? '—' }}</div>
                        <div class="pr-line-name">{{ $item?->item_name ?? $line['item_name'] ?? '—' }}</div>
                    </div>
                    <div class="pr-line-qty pr-line-number">
                        <span class="pr-line-label">Qty</span>
                        <strong>{{ $formatQuantity($line['quantity'] ?? null) }}</strong>
                        @if(filled($manualUom ?? $inventoryUom))<span class="pr-line-uom">{{ $manualUom ?? $inventoryUom }}</span>@endif
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
                        <span class="pr-line-label">Keterangan</span>
                        @if($note !== null){{ $note }}@else<span class="pr-line-empty">—</span>@endif
                    </div>
                    @if($secondary->isNotEmpty() || $item)
                        <details class="pr-line-more">
                            <summary>Rincian</summary>
                            <dl>
                                @foreach($secondary as $label => $value)
                                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                @endforeach
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
