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

#[Fillable(['company_db', 'pool_id', 'department_id', 'document_type', 'sap_entity', 'sap_doc_entry', 'sap_doc_num', 'source', 'fpk_number', 'transaction_uuid', 'sap_business_status', 'integration_status', 'created_from_web', 'normalized_data'])]
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
