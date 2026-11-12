<?php

namespace App\Application\Inventory;

use Carbon\CarbonImmutable;

/**
 * Batas tanggal FPK Goods Issue menurut timezone aplikasi: tidak boleh melewati hari ini. Keputusan Product Owner
 * 2026-11-12 menghapus batas tanggal mundur (sebelumnya hanya hari ini atau H-1), jadi tidak ada tanggal paling
 * awal. Dipakai bersama oleh form dan validasi pengajuan.
 */
class GoodsIssueFpkDateWindow
{
    public function latest(): string
    {
        return CarbonImmutable::now()->toDateString();
    }
}
