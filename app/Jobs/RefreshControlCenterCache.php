<?php

namespace App\Jobs;

use App\Application\ControlCenter\SyncControlCenterCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshControlCenterCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function handle(SyncControlCenterCache $sync): void
    {
        $sync->handle();
    }

    public function uniqueId(): string
    {
        return 'control-center-master-data';
    }
}
