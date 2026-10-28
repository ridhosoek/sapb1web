<div>
    <label class="form-label" for="item-search">Cari ItemCode atau ItemName</label>
    <div class="input-group">
        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input wire:model.live.debounce.300ms="search" class="form-control" id="item-search" type="search" placeholder="contoh: kain" autocomplete="off">
    </div>
    <div wire:loading class="small text-secondary mt-2">Mencari item...</div>
    @if(mb_strlen(trim($search)) >= 2)
        <div class="mt-2" wire:loading.remove>
            @forelse($items as $item)
                <div class="item-result" wire:key="item-{{ $item->id }}">
                    <div><div class="item-code">{{ $item->item_code }}</div><div class="small text-secondary">{{ $item->item_name }}</div></div>
                    <span class="badge text-bg-light border">{{ $item->inventory_uom ?: '—' }}</span>
                </div>
            @empty
                <p class="small text-secondary mb-0 py-2">Item aktif tidak ditemukan.</p>
            @endforelse
        </div>
    @else
        <p class="small text-secondary mb-0 mt-2">Masukkan minimal 2 karakter.</p>
    @endif
</div>
