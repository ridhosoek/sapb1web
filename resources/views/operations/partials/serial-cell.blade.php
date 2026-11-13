@php
    $picked = collect($picked ?? [])->map(fn ($number) => (int) $number)->filter()->values();
    $requirement = $requirement ?? '';
@endphp
<td data-serial-cell>
    <div class="text-secondary small {{ $requirement === '' ? '' : 'd-none' }}" data-serial-idle>Pilih item terlebih dahulu.</div>
    <div class="text-secondary small d-none" data-serial-none>Tidak dikelola serial — cukup isi Qty.</div>
    <div class="small text-danger d-none" data-serial-unknown><i class="fa-solid fa-triangle-exclamation me-1"></i>Data serial item belum terbaca dari SAP. Baris ini ditahan; hubungi tim SAP.</div>
    <div class="d-none" data-serial-picker>
        <button class="btn btn-sm btn-outline-primary text-nowrap" type="button" data-serial-open><i class="fa-solid fa-barcode me-1"></i> Pilih Serial · <span data-serial-count>{{ $picked->count() }}</span> dari <span data-serial-need>0</span></button>
        <div class="serial-chips" data-serial-chips></div>
        <div data-serial-inputs>@foreach($picked as $number)<input type="hidden" name="lines[{{ $index }}][serial_system_numbers][]" value="{{ $number }}" data-serial-input>@endforeach</div>
        <div class="small text-danger" data-serial-note></div>
    </div>
</td>