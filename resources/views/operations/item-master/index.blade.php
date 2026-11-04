@extends('layouts.app')

@section('title', 'Item Master')
@section('page-title', 'Item Master')
@section('page-subtitle', 'Cari item terlebih dahulu agar portal tidak memuat seluruh master data SAP sekaligus.')

@section('content')
    <section class="operational-panel mb-3">
        <div class="panel-body">
            <form class="row g-2 align-items-end" method="GET" action="{{ route('item-master.index') }}">
                <div class="col-lg-9">
                    <label class="form-label" for="item-master-search">Cari Item</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input class="form-control @error('q') is-invalid @enderror" id="item-master-search" name="q" value="{{ $search }}" minlength="2" maxlength="100" placeholder="Ketik minimal 2 karakter Item Code atau nama item" required autofocus>
                    </div>
                    @error('q')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-lg-3 d-grid">
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i> Cari Item</button>
                </div>
            </form>
        </div>
    </section>

    <section class="operational-panel">
        <header class="panel-header">
            <h2 class="panel-title">Hasil Pencarian</h2>
            @if($items)<span class="badge text-bg-light border">{{ $items->total() }} item</span>@endif
        </header>
        @if($items === null)
            <div class="empty-state py-5"><i class="fa-solid fa-magnifying-glass"></i>Masukkan Item Code atau nama item untuk menampilkan data.</div>
        @elseif($items->isEmpty())
            <div class="empty-state py-5"><i class="fa-regular fa-folder-open"></i>Item dengan kata kunci <strong>{{ $search }}</strong> tidak ditemukan pada database SAP yang dipilih.</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover table-operational align-middle mb-0">
                    <thead><tr><th>Item Code</th><th>Nama Item</th><th>Item Group</th><th>UoM</th><th>Inventory</th><th>Purchase</th><th>Serial</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    @foreach($items as $item)
                        <tr>
                            <td class="mono fw-semibold">{{ $item->item_code }}</td>
                            <td>{{ $item->item_name }}</td>
                            <td>{{ $item->item_group_name ?: '—' }}</td>
                            <td class="mono">{{ $item->inventory_uom ?: '—' }}</td>
                            <td>{{ $item->is_inventory_item ? 'Ya' : 'Tidak' }}</td>
                            <td>{{ $item->is_purchase_item ? 'Ya' : 'Tidak' }}</td>
                            <td>{{ $item->manages_serial_numbers ? 'Dikelola' : 'Tidak' }}</td>
                            <td><span class="status-dot {{ $item->is_active ? 'success' : 'muted' }}"></span>{{ $item->is_active ? 'Aktif' : 'Nonaktif' }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('item-master.show', $item) }}">Detail</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($items->hasPages())<div class="panel-body border-top">{{ $items->links() }}</div>@endif
        @endif
    </section>
@endsection
