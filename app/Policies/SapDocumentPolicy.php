<?php

namespace App\Policies;

use App\Domain\Shared\Enums\UserRole;
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

        // BP-10: a Goods Receipt PO posted from the portal is shown to its first sender only, whatever the role.
        if ($document->isWebGoodsReceipt() && ! $document->isWebGoodsReceiptCreatedBy($user)) {
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

    public function checkPurchaseRequest(User $user, SapDocument $document): bool
    {
        return $user->is_active
            && $user->hasRole(UserRole::AdminHo, UserRole::SuperAdmin)
            && $document->company_db === $this->companies->current()
            && $document->document_type === 'PURCHASE_REQUEST'
            && $document->admin_ho_checked_by === null
            && $document->approval()->where('status', '<>', 'REJECTED')->exists();
    }
}
