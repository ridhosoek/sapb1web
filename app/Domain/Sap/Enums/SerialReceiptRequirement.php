<?php

namespace App\Domain\Sap\Enums;

/**
 * Kebutuhan serial saat GRPO yang dibaca dari Item Master SAP.
 * Dipakai bersama oleh validasi server dan semua tampilan agar keduanya tidak berbeda.
 * Nilai yang tidak dikenal tidak pernah ditafsirkan; hasilnya Undetermined dan GRPO ditolak.
 */
enum SerialReceiptRequirement: string
{
    case NonSerial = 'NON_SERIAL';
    case SerialRequired = 'SERIAL_REQUIRED';
    case SerialNotRequiredOnReceipt = 'SERIAL_NOT_REQUIRED_ON_RECEIPT';
    case Unsupported = 'UNSUPPORTED';
    case Undetermined = 'UNDETERMINED';

    public const OnEveryTransactionMethod = 'bomm_OnEveryTransaction';

    public const OnReleaseOnlyMethod = 'bomm_OnReleaseOnly';

    /**
     * @param  bool|null  $isTireItem  null bila grup item tidak dapat dipastikan
     */
    public static function resolve(mixed $manageSerialNumbers, mixed $managementMethod, ?bool $isTireItem): self
    {
        if ($manageSerialNumbers === 'tNO') {
            return self::NonSerial;
        }

        if ($manageSerialNumbers !== 'tYES') {
            return self::Undetermined;
        }

        return match ($managementMethod) {
            self::OnEveryTransactionMethod => self::SerialRequired,
            self::OnReleaseOnlyMethod => match ($isTireItem) {
                true => self::Unsupported,
                false => self::SerialNotRequiredOnReceipt,
                null => self::Undetermined,
            },
            default => self::Undetermined,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NonSerial => 'Tidak dikelola serial',
            self::SerialRequired => 'Serial Numbers',
            self::SerialNotRequiredOnReceipt => 'Serial Numbers (tidak diminta saat GRPO)',
            self::Unsupported => 'Serial Numbers — konfigurasi belum didukung',
            self::Undetermined => 'Belum dapat dipastikan',
        };
    }

    public function receiptText(): string
    {
        return match ($this) {
            self::NonSerial => 'Tidak',
            self::SerialRequired => 'Ya',
            self::SerialNotRequiredOnReceipt => 'Tidak (serial tidak diminta saat GRPO)',
            self::Unsupported => 'Belum didukung — GRPO ditolak',
            self::Undetermined => 'Belum dapat dipastikan — GRPO ditolak',
        };
    }
}
