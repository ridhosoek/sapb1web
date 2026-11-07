<?php

namespace App\Models;

use App\Domain\Sap\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_db', 'transaction_uuid', 'command_type', 'sap_entity', 'base_document_entry', 'status', 'request_payload', 'sap_doc_entry', 'sap_doc_num', 'attempts', 'last_attempt_at', 'last_error'])]
class SapCommand extends Model
{
    public const CREATE_GOODS_RECEIPT_PO = 'CREATE_GOODS_RECEIPT_PO';

    protected function casts(): array
    {
        return [
            'status' => IntegrationStatus::class,
            'request_payload' => 'array',
            'last_attempt_at' => 'datetime',
        ];
    }

    /**
     * Goods Receipt PO commands whose POST outcome is not certain. While one exists for a Purchase Order the
     * remaining open quantity is unknown, so no further receipt may be posted for that PO.
     */
    public function scopeUncertainGoodsReceipts(Builder $query, string $companyDb, ?int $purchaseOrderDocEntry = null): Builder
    {
        return $query
            ->where('company_db', $companyDb)
            ->where('command_type', self::CREATE_GOODS_RECEIPT_PO)
            ->whereIn('status', [IntegrationStatus::Posting->value, IntegrationStatus::PostingUnknown->value])
            ->when($purchaseOrderDocEntry !== null, fn (Builder $query) => $query->where('base_document_entry', $purchaseOrderDocEntry));
    }
}
