<?php

namespace App\Domain\Sap\Enums;

/**
 * Result of one read-only SAP check for a Purchase Request whose POST outcome is not certain (TD-14). Only
 * `Reconciled` changes the integration status; every other result leaves the document held.
 */
enum PurchaseRequestReconciliationOutcome: string
{
    case Reconciled = 'RECONCILED';
    case AlreadySynced = 'ALREADY_SYNCED';
    case NotFound = 'NOT_FOUND';
    case MultipleMatches = 'MULTIPLE_MATCHES';
    case DataMismatch = 'DATA_MISMATCH';
    case LookupFailed = 'LOOKUP_FAILED';
    case InvalidResponse = 'INVALID_RESPONSE';
    case FieldUnsupported = 'FIELD_UNSUPPORTED';
    case UuidNotSent = 'UUID_NOT_SENT';
    case RecordingFailed = 'RECORDING_FAILED';
    case NotEligible = 'NOT_ELIGIBLE';
    case StillInFlight = 'STILL_IN_FLIGHT';
    case Busy = 'BUSY';

    /** Whether the scheduler may read SAP again later. Cases that need a person are never repeated automatically. */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::NotFound,
            self::LookupFailed,
            self::InvalidResponse,
            self::FieldUnsupported,
            self::RecordingFailed,
        ], true);
    }

    public function isResolved(): bool
    {
        return $this === self::Reconciled || $this === self::AlreadySynced;
    }

    public function message(): string
    {
        return match ($this) {
            self::Reconciled => 'Purchase Request ditemukan di SAP dan sudah dicatat di portal.',
            self::AlreadySynced => 'Purchase Request ini sudah tercatat sinkron.',
            self::NotFound => 'Belum ada Purchase Request dengan UUID ini di SAP. Hasil kosong tidak membuktikan POST tidak terjadi.',
            self::MultipleMatches => 'Lebih dari satu Purchase Request di SAP membawa UUID yang sama.',
            self::DataMismatch => 'Data Purchase Request di SAP tidak cocok dengan data yang dikirim portal.',
            self::LookupFailed => 'SAP tidak dapat dibaca.',
            self::InvalidResponse => 'Jawaban SAP tidak dapat diverifikasi.',
            self::FieldUnsupported => 'Database SAP ini tidak menampilkan field UUID pada Purchase Request, sehingga pencarian tidak dijalankan.',
            self::UuidNotSent => 'Payload yang tersimpan tidak memuat UUID pada field SAP yang dikonfigurasi, sehingga dokumen tidak dapat dicocokkan otomatis.',
            self::RecordingFailed => 'Purchase Request ditemukan di SAP, tetapi pencatatan di portal gagal.',
            self::NotEligible => 'Status dokumen dan command tidak memenuhi syarat rekonsiliasi.',
            self::StillInFlight => 'POST masih dianggap berjalan.',
            self::Busy => 'Dokumen ini sedang diperiksa oleh proses lain.',
        };
    }

    public function nextStep(): string
    {
        return match ($this) {
            self::Reconciled, self::AlreadySynced => 'Tidak ada tindakan lanjutan.',
            self::NotFound => 'Periksa lagi nanti. Jangan kirim ulang; tim IT harus memeriksa SAP secara langsung bila tetap kosong.',
            self::MultipleMatches, self::DataMismatch => 'Perlu investigasi manual bersama tim SAP. Jangan kirim ulang.',
            self::LookupFailed, self::InvalidResponse, self::RecordingFailed => 'Periksa Integration Monitor dan log aplikasi, lalu periksa lagi setelah penyebabnya diperbaiki.',
            self::FieldUnsupported => 'Minta tim SAP memeriksa UDF UUID pada Purchase Request di database ini, lalu periksa lagi setelah cache metadata diperbarui.',
            self::UuidNotSent => 'Cari kandidat secara manual di SAP (waktu dibuat, item, jumlah, gudang, pengaju). Jangan kirim ulang tanpa bukti.',
            self::NotEligible => 'Perlu investigasi manual oleh tim IT.',
            self::StillInFlight, self::Busy => 'Tunggu beberapa menit, lalu periksa lagi.',
        };
    }
}
