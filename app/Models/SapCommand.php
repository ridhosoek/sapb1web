<?php

namespace App\Models;

use App\Domain\Sap\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_db', 'transaction_uuid', 'command_type', 'sap_entity', 'status', 'request_payload', 'sap_doc_entry', 'sap_doc_num', 'attempts', 'last_attempt_at', 'last_error'])]
class SapCommand extends Model
{
    protected function casts(): array
    {
        return [
            'status' => IntegrationStatus::class,
            'request_payload' => 'array',
            'last_attempt_at' => 'datetime',
        ];
    }
}
