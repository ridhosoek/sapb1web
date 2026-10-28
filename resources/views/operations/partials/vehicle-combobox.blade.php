@php
    $selectedVehicleLabel = $selectedVehicle
        ? $selectedVehicle->center_code.($selectedVehicle->center_name !== $selectedVehicle->center_code ? ' — '.$selectedVehicle->center_name : '')
        : 'Ketik minimal 2 karakter untuk mencari Nopol';
@endphp

<div class="item-combobox" data-vehicle-combobox data-url="{{ route('lookups.vehicles') }}">
    <input type="hidden" id="{{ $inputId }}" name="vehicle_cost_center_id" value="{{ old('vehicle_cost_center_id') }}" data-vehicle-value required>
    <button class="item-combobox-toggle @error('vehicle_cost_center_id') is-invalid @enderror" type="button" data-vehicle-toggle aria-haspopup="listbox" aria-expanded="false">
        <span data-vehicle-label>{{ $selectedVehicleLabel }}</span>
        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
    </button>
    <div class="item-combobox-dropdown d-none" data-vehicle-dropdown>
        <div class="item-combobox-search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" data-vehicle-search placeholder="Ketik Nopol atau nama kendaraan..." autocomplete="off" aria-label="Cari Nopol SAP">
        </div>
        <div class="item-combobox-results" data-vehicle-results role="listbox">
            <div class="item-combobox-state">Ketik minimal 2 karakter untuk mencari Nopol.</div>
        </div>
        <button class="item-combobox-more d-none" type="button" data-vehicle-more>Muat Nopol berikutnya</button>
    </div>
    @error('vehicle_cost_center_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @else
        <div class="invalid-feedback">Pilih Nopol terlebih dahulu.</div>
    @enderror
</div>
<div class="form-text">Data Nopol aktif dibaca dari cost center SAP database terpilih.</div>
