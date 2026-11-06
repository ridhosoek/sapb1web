<?php

namespace App\Application\Inventory;

use Carbon\CarbonImmutable;

/**
 * Jendela tanggal FPK Goods Issue: hari ini atau H-1 menurut timezone aplikasi.
 * Dipakai bersama oleh form, validasi pengajuan, dan approval final.
 */
class GoodsIssueFpkDateWindow
{
    public function latest(): string
    {
        return CarbonImmutable::now()->toDateString();
    }

    public function earliest(): string
    {
        return CarbonImmutable::now()->subDay()->toDateString();
    }

    public function allows(mixed $fpkDate): bool
    {
        return is_string($fpkDate)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fpkDate) === 1
            && $fpkDate >= $this->earliest()
            && $fpkDate <= $this->latest();
    }
}
