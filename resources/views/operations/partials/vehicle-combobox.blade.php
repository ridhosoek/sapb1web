@php
    $inputName = $inputName ?? 'vehicle_cost_center_id';
    $inputValue = $inputValue ?? old($inputName);
    $errorKey = $errorKey ?? $inputName;
    $required = $required ?? false;
    $selectedVehicleLabel = $selectedVehicle
        ? $selectedVehicle->center_code.($selectedVehicle->center_name !== $selectedVehicle->center_code ? ' — '.$selectedVehicle->center_name : '')
        : 'Opsional — cari Nopol';
@endphp

<div class="item-combobox" data-vehicle-combobox data-url="{{ route('lookups.vehicles') }}" data-required="{{ $required ? 'true' : 'false' }}">
    <input type="hidden" id="{{ $inputId }}" name="{{ $inputName }}" value="{{ $inputValue }}" data-vehicle-value>
    <button class="item-combobox-toggle {{ $errors->has($errorKey) ? 'is-invalid' : '' }}" type="button" data-vehicle-toggle aria-haspopup="listbox" aria-expanded="false">
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
        <button class="item-combobox-more" type="button" data-vehicle-clear>Tanpa Nopol</button>
    </div>
    @if($errors->has($errorKey))
        <div class="invalid-feedback">{{ $errors->first($errorKey) }}</div>
    @endif
</div>
