<ul class="nav nav-tabs mb-3" aria-label="Tampilan Goods Receipt PO" data-goods-receipt-tabs>
    <li class="nav-item">
        <a class="nav-link {{ $active === 'ready' ? 'active' : '' }}" href="{{ route('ready-to-receive.index') }}" @if($active === 'ready') aria-current="page" @endif><i class="fa-solid fa-truck-ramp-box me-1"></i> Siap Diterima</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $active === 'history' ? 'active' : '' }}" href="{{ route('ready-to-receive.history') }}" @if($active === 'history') aria-current="page" @endif><i class="fa-solid fa-clock-rotate-left me-1"></i> Riwayat GRPO Web SAP</a>
    </li>
</ul>
