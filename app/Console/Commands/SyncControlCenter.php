<?php

namespace App\Console\Commands;

use App\Application\ControlCenter\SyncControlCenterCache;
use Illuminate\Console\Command;

class SyncControlCenter extends Command
{
    protected $signature = 'control-center:sync';

    protected $description = 'Synchronize pools, departments, and vehicles from Control Center';

    public function handle(SyncControlCenterCache $sync): int
    {
        $result = $sync->handle();
        $this->info("Synchronized {$result['pools']} pools, {$result['departments']} departments, and {$result['vehicles']} vehicles.");

        return self::SUCCESS;
    }
}
