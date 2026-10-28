<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_db', 'center_code', 'center_name', 'dimension', 'is_active', 'source_payload', 'synced_at'])]
class SapCostCenterCache extends Model
{
    protected function casts(): array
    {
        return [
            'dimension' => 'integer',
            'is_active' => 'boolean',
            'source_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, string $companyDb): Builder
    {
        return $query->where('company_db', $companyDb);
    }
}
