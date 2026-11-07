<?php

namespace App\Domain\Sap\Enums;

/**
 * SAP `AuthorizationStatus` of a Purchase Order and the interim portal policy for Goods Receipt PO (TD-01).
 *
 * The policy is a fail-closed allowlist based on the statuses that produced real GRPOs in IA. It is not a
 * statement about SAP semantics: it can hide a valid PO, and unknown or missing values are never receivable.
 */
enum PurchaseOrderAuthorization: string
{
    case Without = 'dasWithout';
    case Pending = 'dasPending';
    case Approved = 'dasApproved';
    case Rejected = 'dasRejected';
    case Generated = 'dasGenerated';
    case GeneratedByAuthorizer = 'dasGeneratedbyAuthorizer';
    case Cancelled = 'dasCancelled';

    public static function allowsGoodsReceipt(mixed $sapValue): bool
    {
        return in_array(self::tryFrom((string) $sapValue), [self::Without, self::Generated, self::GeneratedByAuthorizer], true);
    }

    /** Operational explanation, or null when the status allows a Goods Receipt PO. */
    public static function holdReason(mixed $sapValue): ?string
    {
        if (self::allowsGoodsReceipt($sapValue)) {
            return null;
        }

        $value = trim((string) $sapValue);

        return match (self::tryFrom($value)) {
            self::Pending => 'PO masih menunggu approval di SAP.',
            self::Approved => 'Status approval SAP "approved" belum diizinkan untuk GRPO (kebijakan sementara: SAP pernah menolak GRPO dari PO berstatus ini). Hubungi tim SAP untuk memastikan PO sudah final.',
            self::Rejected => 'Approval PO ditolak di SAP.',
            self::Cancelled => 'Approval PO dibatalkan di SAP.',
            default => $value === ''
                ? 'Status approval PO tidak terbaca dari SAP, sehingga PO ditahan sampai statusnya jelas.'
                : "Status approval SAP \"{$value}\" tidak dikenal portal, sehingga PO ditahan sampai statusnya jelas.",
        };
    }
}
