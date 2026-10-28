<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_db', 'correlation_id', 'method', 'endpoint', 'response_status', 'duration_ms', 'result', 'error_code', 'error_message', 'created_at'])]
class SapApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sap_api_logs';

    public function scopeForCompany(Builder $query, string $companyDb): Builder
    {
        return $query->where('company_db', $companyDb);
    }
}
