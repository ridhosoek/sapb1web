<?php

namespace App\Policies;

use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\User;

class SapDocumentPolicy
{
    public function __construct(private readonly SapCompanyContext $companies) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->operationalProfile()->exists();
    }

    public function view(User $user, SapDocument $document): bool
    {
        if ($document->company_db !== $this->companies->current()) {
            return false;
        }

        if ($user->role->canViewAllDocuments()) {
            return true;
        }

        $userWarehouse = trim((string) $user->operationalProfile?->warehouse_code);
        $documentWarehouse = trim((string) (
            data_get($document->normalized_data, 'warehouse_code')
            ?? data_get($document->normalized_data, 'lines.0.warehouse_code')
            ?? data_get($document->normalized_data, 'lines.0.WarehouseCode')
            ?? ''
        ));

        return $userWarehouse !== ''
            && $documentWarehouse !== ''
            && strcasecmp($userWarehouse, $documentWarehouse) === 0;
    }

    public function create(User $user): bool
    {
        return $user->is_active
            && $user->role->canCreateOperationalDocuments()
            && $user->operationalProfile()->exists();
    }
}
