<?php

namespace App\Models;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use Database\Factories\SapDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_db', 'pool_id', 'department_id', 'document_type', 'sap_entity', 'sap_doc_entry', 'sap_doc_num', 'source', 'fpk_number', 'transaction_uuid', 'sap_business_status', 'integration_status', 'created_from_web', 'web_created_by', 'normalized_data'])]
class SapDocument extends Model
{
    /** @use HasFactory<SapDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sap_business_status' => BusinessStatus::class,
            'integration_status' => IntegrationStatus::class,
            'created_from_web' => 'boolean',
            'admin_ho_checked_at' => 'datetime',
            'normalized_data' => 'array',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ControlPool::class, 'pool_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ControlDepartment::class, 'department_id');
    }

    public function adminHoChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_ho_checked_by');
    }

    /** The user who first sent this Goods Receipt PO from the portal; never the reconciler or a photo uploader. */
    public function webCreator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'web_created_by');
    }

    public function watcher(): HasOne
    {
        return $this->hasOne(SapWatcher::class);
    }

    public function approval(): HasOne
    {
        return $this->hasOne(DocumentApproval::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SapDocumentAttachment::class);
    }

    public function childRelations(): HasMany
    {
        return $this->hasMany(SapDocumentRelation::class, 'parent_document_id');
    }

    public function parentRelations(): HasMany
    {
        return $this->hasMany(SapDocumentRelation::class, 'child_document_id');
    }

    public function scopeForCompany(Builder $query, string $companyDb): Builder
    {
        return $query->where('company_db', $companyDb);
    }

    /** A Goods Receipt PO that the portal itself posted, as opposed to one read back from SAP. */
    public function isWebGoodsReceipt(): bool
    {
        return $this->document_type === 'GOODS_RECEIPT_PO' && $this->created_from_web;
    }

    /**
     * BP-10: a Goods Receipt PO posted from the portal belongs to its first sender only. A receipt whose sender is
     * not recorded belongs to nobody.
     */
    public function isWebGoodsReceiptCreatedBy(User $user): bool
    {
        return $this->isWebGoodsReceipt()
            && $this->web_created_by !== null
            && (int) $this->web_created_by === (int) $user->id;
    }

    /** Goods Receipt POs that the user posted from the portal and that SAP is known to hold. */
    public function scopeWebGoodsReceiptsCreatedBy(Builder $query, User $user): Builder
    {
        return $query
            ->where('document_type', 'GOODS_RECEIPT_PO')
            ->where('created_from_web', true)
            ->where('web_created_by', $user->id)
            ->where('integration_status', IntegrationStatus::InSync->value)
            ->whereNotNull('sap_doc_entry');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->canViewAllDocuments()) {
            return $query;
        }

        $warehouseCode = trim((string) $user->operationalProfile?->warehouse_code);

        return $warehouseCode === ''
            ? $query->whereRaw('1 = 0')
            : $query->where(function (Builder $query) use ($warehouseCode): void {
                $driver = $query->getConnection()->getDriverName();
                $lineWarehouseExpression = $driver === 'sqlite'
                    ? "COALESCE(json_extract(normalized_data, '$.lines[0].warehouse_code'), json_extract(normalized_data, '$.lines[0].WarehouseCode'))"
                    : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(normalized_data, '$.lines[0].warehouse_code')), JSON_UNQUOTE(JSON_EXTRACT(normalized_data, '$.lines[0].WarehouseCode')))";

                $query->where('normalized_data->warehouse_code', $warehouseCode)
                    ->orWhereRaw("{$lineWarehouseExpression} = ?", [$warehouseCode]);
            });
    }
}
