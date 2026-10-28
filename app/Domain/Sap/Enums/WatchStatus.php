<?php

namespace App\Domain\Sap\Enums;

enum WatchStatus: string
{
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Stopped = 'STOPPED';
}
