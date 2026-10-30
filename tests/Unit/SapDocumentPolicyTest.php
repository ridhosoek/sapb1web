<?php

namespace Tests\Unit;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SapDocumentPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_warehouse_user_cannot_view_document_from_another_warehouse(): void
    {
        [$user, $ownPool] = $this->operationalUser(UserRole::AdminOperational);
        $document = $this->documentFor($ownPool, warehouseCode: 'MDN-01');

        $this->assertFalse(Gate::forUser($user)->allows('view', $document));
    }

    public function test_warehouse_user_can_view_document_from_own_warehouse(): void
    {
        [$user, $ownPool] = $this->operationalUser(UserRole::AdminOperational);
        $document = $this->documentFor($ownPool, warehouseCode: 'DMI-01');

        $this->assertTrue(Gate::forUser($user)->allows('view', $document));
    }

    public function test_manager_operational_is_limited_to_documents_from_its_own_warehouse(): void
    {
        [$user, $ownPool, $otherPool] = $this->operationalUser(UserRole::ManagerOperational);

        $this->assertTrue(Gate::forUser($user)->allows('view', $this->documentFor($otherPool, warehouseCode: 'DMI-01')));
        $this->assertFalse(Gate::forUser($user)->allows('view', $this->documentFor($ownPool, warehouseCode: 'MDN-01')));
    }

    public function test_document_list_scope_matches_the_users_warehouse_visibility(): void
    {
        [$user, $ownPool, $otherPool] = $this->operationalUser(UserRole::AdminOperational);
        $ownDocument = $this->documentFor($otherPool, warehouseCode: 'DMI-01');
        $this->documentFor($ownPool, warehouseCode: 'MDN-01');

        $visibleIds = SapDocument::query()->visibleTo($user)->pluck('id')->all();

        $this->assertSame([$ownDocument->id], $visibleIds);
    }

    public function test_legacy_document_uses_its_first_line_warehouse_for_visibility(): void
    {
        [$user, $ownPool] = $this->operationalUser(UserRole::AdminOperational);
        $document = $this->documentFor($ownPool, warehouseCode: '');
        $document->update([
            'normalized_data' => ['lines' => [['WarehouseCode' => 'DMI-01']]],
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('view', $document->refresh()));
        $this->assertSame([$document->id], SapDocument::query()->visibleTo($user)->pluck('id')->all());
    }

    #[DataProvider('privilegedRoles')]
    public function test_privileged_role_can_view_document_from_another_warehouse(UserRole $role): void
    {
        [$user, , $otherPool] = $this->operationalUser($role);
        $document = $this->documentFor($otherPool, warehouseCode: 'MDN-01');

        $this->assertTrue(Gate::forUser($user)->allows('view', $document));
    }

    public function test_admin_cannot_view_document_from_another_sap_company_database(): void
    {
        [$user, $ownPool] = $this->operationalUser(UserRole::SuperAdmin);
        $document = $this->documentFor($ownPool, 'SECOND_DB');

        $this->assertFalse(Gate::forUser($user)->allows('view', $document));
    }

    #[DataProvider('transactionRoles')]
    public function test_only_transaction_roles_can_create_documents(UserRole $role, bool $expected): void
    {
        [$user] = $this->operationalUser($role);

        $this->assertSame($expected, Gate::forUser($user)->allows('create', SapDocument::class));
    }

    /** @return array<string, array{UserRole}> */
    public static function privilegedRoles(): array
    {
        return [
            'admin HO' => [UserRole::AdminHo],
            'manager admin HO' => [UserRole::ManagerAdminHo],
            'super admin' => [UserRole::SuperAdmin],
        ];
    }

    /** @return array<string, array{UserRole, bool}> */
    public static function transactionRoles(): array
    {
        return [
            'admin operational' => [UserRole::AdminOperational, true],
            'manager operational' => [UserRole::ManagerOperational, false],
            'admin HO' => [UserRole::AdminHo, false],
            'manager admin HO' => [UserRole::ManagerAdminHo, false],
            'super admin' => [UserRole::SuperAdmin, true],
        ];
    }

    /** @return array{User, ControlPool, ControlPool} */
    private function operationalUser(UserRole $role): array
    {
        $ownPool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai']);
        $otherPool = ControlPool::query()->create(['code' => 'MEDAN', 'name' => 'Medan']);
        $department = ControlDepartment::query()->create([
            'pool_id' => $ownPool->id,
            'external_id' => 'dept-tsp',
            'code' => 'TSP',
            'name' => 'Transport',
        ]);
        $user = User::factory()->create(['role' => $role]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $ownPool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        return [$user, $ownPool, $otherPool];
    }

    private function documentFor(ControlPool $pool, string $companyDb = 'TEST_DB', string $warehouseCode = 'DMI-01'): SapDocument
    {
        return SapDocument::query()->create([
            'company_db' => $companyDb,
            'pool_id' => $pool->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'normalized_data' => ['warehouse_code' => $warehouseCode],
        ]);
    }
}
