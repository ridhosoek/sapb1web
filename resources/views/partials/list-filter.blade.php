<section class="operational-panel mb-3">
    <div class="panel-body">
        <form class="row g-2 align-items-end" method="GET" action="{{ $action }}">
            <div class="{{ !empty($statusOptions) ? 'col-md-7 col-xl-8' : 'col-md-10' }}">
                <label class="form-label" for="list-search-{{ $id ?? 'default' }}">Cari Data</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input class="form-control" id="list-search-{{ $id ?? 'default' }}" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="{{ $placeholder ?? 'Ketik nomor dokumen, FPK, Nopol, atau kata kunci' }}">
                </div>
            </div>
            @if(!empty($statusOptions))
                <div class="col-md-3 col-xl-2">
                    <label class="form-label" for="list-status-{{ $id ?? 'default' }}">{{ $statusLabel ?? 'Status' }}</label>
                    <select class="form-select" id="list-status-{{ $id ?? 'default' }}" name="status">
                        <option value="">Semua Status</option>
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit"><i class="fa-solid fa-filter me-1"></i> Cari</button>
                <a class="btn btn-outline-secondary" href="{{ $action }}" title="Reset filter" aria-label="Reset filter"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>
    </div>
</section>
