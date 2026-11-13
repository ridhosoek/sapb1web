<?php

namespace App\Domain\Sap\Enums;

/**
 * Whether a Goods Issue line must name SAP serial numbers (BP-12 / TD-13). The Item Master of the SAP company decides,
 * never the item group or the issue type. A value that is missing or not recognised is never interpreted: the line is
 * held until the SAP data is complete.
 */
enum SerialIssueRequirement: string
{
    case NonSerial = 'NON_SERIAL';
    case SerialRequired = 'SERIAL_REQUIRED';
    case Undetermined = 'UNDETERMINED';

    public static function resolve(mixed $manageSerialNumbers): self
    {
        return match ($manageSerialNumbers) {
            'tNO' => self::NonSerial,
            'tYES' => self::SerialRequired,
            default => self::Undetermined,
        };
    }
}
