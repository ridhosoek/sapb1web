<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pool, Department and Warehouse of one user in one SAP CompanyDB (TD-09). A row with a NULL company_db is a legacy
 * profile that was never proven for any company; no operational flow reads it.
 */
#[Fillable(['user_id', 'company_db', 'pool_id', 'department_id', 'warehouse_code'])]
class UserOperationalProfile extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ControlPool::class, 'pool_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ControlDepartment::class, 'department_id');
    }

    public function scopeForCompany(Builder $query, string $companyDb): Builder
    {
        return $query->where($query->getModel()->getTable().'.company_db', $companyDb);
    }
}
