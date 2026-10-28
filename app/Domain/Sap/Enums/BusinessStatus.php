<?php

namespace App\Domain\Sap\Enums;

enum BusinessStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
