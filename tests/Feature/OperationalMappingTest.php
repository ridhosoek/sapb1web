<?php

namespace Tests\Feature;

use App\Application\Items\SyncSapItems;
use App\Application\Purchasing\SubmitPurchaseRequest;
use App\Application\Users\OperationalMapping;
use App\Domain\Shared\Enums\UserRole;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\ApprovalWorkflow;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TD-09: Pool, Department and Warehouse are mapped per user per SAP CompanyDB. TEST_DB plays IA and SECOND_DB plays
 * IAL_DEV; all SAP data here is fake.
 */
class OperationalMappingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
        config()->set('sap.dimensions.department', 1);
        config()->set('sap.dimensions.pool', 2);
        config()->set('sap.dimensions.vehicle', 3);
    }

    public function test_each_company_submits_with_its_own_mapping_and_never_leaks_into_the_other(): void
    {
        [$user, $items] = $this->userMappedInBothCompanies();
        Http::preventStrayRequests();

        $this->submitPurchaseRequest($user, $items['TEST_DB'], 'TEST_DB')->assertSessionHasNoErrors();
        $this->submitPurchaseRequest($user, $items['SECOND_DB'], 'SECOND_DB')->assertSessionHasNoErrors();

        $first = SapDocument::query()->forCompany('TEST_DB')->sole();
        $second = SapDocument::query()->forCompany('SECOND_DB')->sole();
        $this->assertSame('DMI-01', data_get($first->normalized_data, 'warehouse_code'));
        $this->assertSame('SMD-01', data_get($second->normalized_data, 'warehouse_code'));
        $this->assertSame('DMI', data_get($first->normalized_data, 'operational_snapshot.pool_code'));
        $this->assertSame('SMD', data_get($second->normalized_data, 'operational_snapshot.pool_code'));
        $this->assertSame('ENG', data_get($second->normalized_data, 'operational_snapshot.department_code'));
        $this->assertSame('SECOND_DB', data_get($second->normalized_data, 'operational_snapshot.company_db'));
        $this->assertSame(
            SapCostCenterCache::query()->forCompany('SECOND_DB')->where('center_code', 'SMD')->value('id'),
            data_get($second->normalized_data, 'sap_context.pool_cost_center.id'),
        );
        $this->assertSame(
            SapCostCenterCache::query()->forCompany('TEST_DB')->where('center_code', 'DMI')->value('id'),
            data_get($first->normalized_data, 'sap_context.pool_cost_center.id'),
        );
        Http::assertNothingSent();
    }

    public function test_the_same_user_sees_only_the_documents_of_the_selected_company_and_its_warehouse(): void
    {
        [$user, $items] = $this->userMappedInBothCompanies();
        $this->submitPurchaseRequest($user, $items['TEST_DB'], 'TEST_DB')->assertSessionHasNoErrors();
        $this->submitPurchaseRequest($user, $items['SECOND_DB'], 'SECOND_DB')->assertSessionHasNoErrors();
        $user->forceFill(['role' => UserRole::AdminOperational])->save();
        $firstId = SapDocument::query()->forCompany('TEST_DB')->value('id');
        $secondId = SapDocument::query()->forCompany('SECOND_DB')->value('id');

        $this->withoutVite()->actingAs($user)->withSession(['sap_company_db' => 'TEST_DB'])
            ->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee(route('documents.show', $firstId), false)
            ->assertDontSee(route('documents.show', $secondId), false);
        $this->withoutVite()->actingAs($user)->withSession(['sap_company_db' => 'SECOND_DB'])
            ->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee(route('documents.show', $secondId), false)
            ->assertDontSee(route('documents.show', $firstId), false);
        $this->actingAs($user)->withSession(['sap_company_db' => 'SECOND_DB'])
            ->get(route('documents.show', $firstId))
            ->assertNotFound();
    }

    public function test_user_without_a_mapping_for_the_selected_company_is_stopped_with_a_clear_message(): void
    {
        [$user] = $this->mappedUser('TEST_DB', 'DMI', 'DMI-01');

        $response = $this->actingAs($user)->withSession(['sap_company_db' => 'SECOND_DB'])->get(route('dashboard'));

        $response->assertForbidden();
        $this->assertStringContainsString('database SAP SECOND_DB belum ditetapkan oleh Admin HO', (string) $response->getContent());
        $this->actingAs($user)->withSession(['sap_company_db' => 'TEST_DB'])->get(route('dashboard'))->assertOk();
    }

    public function test_a_legacy_profile_without_company_is_never_used(): void
    {
        $user = User::factory()->create();
        $pool = ControlPool::query()->create(['code' => 'DMI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create(['pool_id' => $pool->id, 'external_id' => 'd', 'code' => 'ENG', 'name' => 'Engineering', 'is_active' => true]);
        UserOperationalProfile::query()->create(['user_id' => $user->id, 'company_db' => null, 'pool_id' => $pool->id, 'department_id' => $department->id, 'warehouse_code' => 'DMI-01']);

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->assertNull($user->operationalProfileFor('TEST_DB'));
    }

    public function test_user_managers_can_still_open_user_management_for_a_company_they_are_not_mapped_in(): void
    {
        [$adminHo] = $this->mappedUser('TEST_DB', 'DMI', 'DMI-01', UserRole::AdminHo);

        $this->withoutVite()->actingAs($adminHo)->withSession(['sap_company_db' => 'SECOND_DB'])
            ->get(route('admin.users.index'))
            ->assertOk();
        $this->actingAs($adminHo)->withSession(['sap_company_db' => 'SECOND_DB'])
            ->get(route('goods-issues.index'))
            ->assertForbidden();
    }

    /** @return array<string, array{callable(UserOperationalProfile): void, string}> */
    public static function invalidMappings(): array
    {
        return [
            'no mapping row' => [fn (UserOperationalProfile $profile) => $profile->delete(), 'belum ditetapkan'],
            'blank warehouse' => [fn (UserOperationalProfile $profile) => $profile->update(['warehouse_code' => '']), 'Warehouse'],
            'inactive pool' => [fn (UserOperationalProfile $profile) => $profile->pool->update(['is_active' => false]), 'Pool'],
            'inactive department' => [fn (UserOperationalProfile $profile) => $profile->department->update(['is_active' => false]), 'Department'],
            'pool cost center deactivated in SAP' => [fn () => SapCostCenterCache::query()->forCompany('SECOND_DB')->where('dimension', 2)->update(['is_active' => false]), 'Pool [SMD]'],
            'department cost center missing in SAP' => [fn () => SapCostCenterCache::query()->forCompany('SECOND_DB')->where('dimension', 1)->delete(), 'Department [ENG]'],
            'pool code only exists in another dimension' => [fn () => SapCostCenterCache::query()->forCompany('SECOND_DB')->where('dimension', 2)->update(['dimension' => 3]), 'Pool [SMD]'],
        ];
    }

    #[DataProvider('invalidMappings')]
    public function test_incomplete_inactive_or_wrong_dimension_mapping_stops_the_submission(callable $break, string $expectedMessage): void
    {
        [$user, $items] = $this->userMappedInBothCompanies();
        app(SapCompanyContext::class)->select('SECOND_DB');
        $break($user->operationalProfileFor('SECOND_DB')->load('pool', 'department'));
        Http::preventStrayRequests();

        try {
            app(SubmitPurchaseRequest::class)->handle($this->purchaseRequestData($items['SECOND_DB']), $user);
            $this->fail('A broken mapping must stop the submission.');
        } catch (SapSubmissionException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    public function test_a_similar_name_is_never_accepted_as_the_mapping(): void
    {
        [$user, $items] = $this->userMappedInBothCompanies();
        app(SapCompanyContext::class)->select('SECOND_DB');
        // SECOND_DB keeps a Pool called "Samarinda" but under another code than the mapped Pool code.
        SapCostCenterCache::query()->forCompany('SECOND_DB')->where('center_code', 'SMD')->update(['center_code' => 'SMD-NEW']);
        Http::preventStrayRequests();

        $this->expectException(SapSubmissionException::class);
        $this->expectExceptionMessage('Pool [SMD]');

        try {
            app(SubmitPurchaseRequest::class)->handle($this->purchaseRequestData($items['SECOND_DB']), $user);
        } finally {
            $this->assertDatabaseCount('sap_documents', 0);
        }
    }

    public function test_resolving_the_first_company_still_works_after_the_second_company_is_broken(): void
    {
        [$user] = $this->userMappedInBothCompanies();
        $user->operationalProfileFor('SECOND_DB')->delete();

        $context = app(OperationalMapping::class)->resolve($user, 'TEST_DB');

        $this->assertSame('DMI', $context->poolCostCenter->center_code);
        $this->assertSame('DMI-01', $context->warehouseCode());
    }

    public function test_item_and_stock_sync_only_queries_warehouses_mapped_for_that_company(): void
    {
        $this->userMappedInBothCompanies();
        $method = new ReflectionMethod(SyncSapItems::class, 'warehouseCodes');

        $this->assertSame(['DMI-01'], $method->invoke(app(SyncSapItems::class), 'TEST_DB'));
        $this->assertSame(['SMD-01'], $method->invoke(app(SyncSapItems::class), 'SECOND_DB'));
    }

    public function test_backfill_command_attaches_a_legacy_profile_only_when_sap_proves_pool_department_and_warehouse(): void
    {
        config()->set('sap.company_databases', ['IA', 'IAL_DEV']);
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/Login')) {
                return Http::response(['SessionId' => 'session-1']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['value' => (int) ($query['$skip'] ?? 0) > 0 ? [] : [['WarehouseCode' => 'DMI-01'], ['WarehouseCode' => 'MDN-01']]]);
        });
        foreach (['IA' => ['DMI' => 'Dumai', 'MDN' => 'Medan'], 'IAL_DEV' => ['DMI' => 'Dumai']] as $companyDb => $pools) {
            foreach ($pools as $code => $name) {
                SapCostCenterCache::query()->create(['company_db' => $companyDb, 'center_code' => $code, 'center_name' => $name, 'dimension' => 2, 'is_active' => true]);
            }
            SapCostCenterCache::query()->create(['company_db' => $companyDb, 'center_code' => 'ENG', 'center_name' => 'Engineering', 'dimension' => 1, 'is_active' => true]);
        }
        $proven = $this->legacyProfile('dmi', 'eng', 'DMI-01');
        $inactiveWarehouse = $this->legacyProfile('MDN', 'ENG', 'MDN-99');
        $nameOnly = $this->legacyProfile('DUMAI', 'ENG', 'DMI-01');
        $poolMissingInIa = $this->legacyProfile('SMD', 'ENG', 'DMI-01');
        $departmentMissingInIa = $this->legacyProfile('MDN', 'OPS', 'MDN-01');
        $company = fn (int $id): ?string => DB::table('user_operational_profiles')->where('id', $id)->value('company_db');

        $this->artisan('sap:operational-profiles:backfill', ['--company-db' => 'IA'])->assertSuccessful();
        $this->assertNull($company($proven), 'A dry run must not write anything.');

        $this->artisan('sap:operational-profiles:backfill', ['--company-db' => 'IA', '--apply' => true])->assertSuccessful();

        $this->assertSame('IA', $company($proven));
        foreach ([$inactiveWarehouse, $nameOnly, $poolMissingInIa, $departmentMissingInIa] as $unproven) {
            $this->assertNull($company($unproven));
        }
        $this->assertSame(0, DB::table('user_operational_profiles')->where('company_db', 'IAL_DEV')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.operational_profile_backfilled', 'auditable_id' => $proven]);
    }

    public function test_backfill_command_changes_nothing_when_the_company_warehouses_cannot_be_read(): void
    {
        config()->set('sap.company_databases', ['IA']);
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/Warehouses*' => Http::response([], 500),
        ]);
        SapCostCenterCache::query()->create(['company_db' => 'IA', 'center_code' => 'DMI', 'center_name' => 'Dumai', 'dimension' => 2, 'is_active' => true]);
        SapCostCenterCache::query()->create(['company_db' => 'IA', 'center_code' => 'ENG', 'center_name' => 'Engineering', 'dimension' => 1, 'is_active' => true]);
        $legacy = $this->legacyProfile('DMI', 'ENG', 'DMI-01');

        $this->artisan('sap:operational-profiles:backfill', ['--company-db' => 'IA', '--apply' => true])->assertFailed();

        $this->assertNull(DB::table('user_operational_profiles')->where('id', $legacy)->value('company_db'));
    }

    public function test_the_migration_rollback_refuses_to_run_instead_of_deleting_company_mappings(): void
    {
        [$user] = $this->userMappedInBothCompanies();
        $migration = require database_path('migrations/2026_11_09_113740_add_company_db_to_user_operational_profiles_table.php');

        try {
            $migration->down();
            $this->fail('The rollback must be refused while company mappings exist.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tidak menghapus pemetaan', $exception->getMessage());
        }

        $this->assertSame(2, $user->operationalProfiles()->count());
    }

    private function legacyProfile(string $poolCode, string $departmentCode, string $warehouse): int
    {
        $pool = ControlPool::query()->firstOrCreate(['code' => strtoupper($poolCode)], ['name' => $poolCode, 'is_active' => true]);
        $department = ControlDepartment::query()->create(['pool_id' => $pool->id, 'external_id' => Str::uuid()->toString(), 'code' => strtoupper($departmentCode), 'name' => $departmentCode, 'is_active' => true]);

        return UserOperationalProfile::query()->create([
            'user_id' => User::factory()->create()->id,
            'company_db' => null,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => $warehouse,
        ])->id;
    }

    /** @return array{User, array<string, SapItemCache>} */
    private function userMappedInBothCompanies(): array
    {
        [$user, $firstItem] = $this->mappedUser('TEST_DB', 'DMI', 'DMI-01');
        $secondPool = ControlPool::query()->create(['code' => 'SMD', 'name' => 'Samarinda', 'is_active' => true]);
        $secondDepartment = ControlDepartment::query()->create(['pool_id' => $secondPool->id, 'external_id' => 'dept-eng', 'code' => 'ENG', 'name' => 'Engineering', 'is_active' => true]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'company_db' => 'SECOND_DB',
            'pool_id' => $secondPool->id,
            'department_id' => $secondDepartment->id,
            'warehouse_code' => 'SMD-01',
        ]);
        $this->costCenters('SECOND_DB', 'SMD', 'Samarinda', 'ENG', 'Engineering');
        $this->workflow('SECOND_DB', $user, $secondPool->id);

        return [$user, ['TEST_DB' => $firstItem, 'SECOND_DB' => $this->item('SECOND_DB')]];
    }

    /** @return array{User, SapItemCache} */
    private function mappedUser(string $companyDb, string $poolCode, string $warehouse, UserRole $role = UserRole::AdminOperational): array
    {
        $pool = ControlPool::query()->create(['code' => $poolCode, 'name' => 'Pool '.$poolCode, 'is_active' => true]);
        $department = ControlDepartment::query()->create(['pool_id' => $pool->id, 'external_id' => 'dept-tsp', 'code' => 'TSP', 'name' => 'Transport', 'is_active' => true]);
        $user = User::factory()->create(['role' => $role]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'company_db' => $companyDb,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => $warehouse,
        ]);
        $this->costCenters($companyDb, $poolCode, 'Pool '.$poolCode, 'TSP', 'Transport');
        $this->workflow($companyDb, $user, $pool->id);

        return [$user, $this->item($companyDb)];
    }

    private function costCenters(string $companyDb, string $poolCode, string $poolName, string $departmentCode, string $departmentName): void
    {
        SapCostCenterCache::query()->create(['company_db' => $companyDb, 'center_code' => $poolCode, 'center_name' => $poolName, 'dimension' => 2, 'is_active' => true]);
        SapCostCenterCache::query()->create(['company_db' => $companyDb, 'center_code' => $departmentCode, 'center_name' => $departmentName, 'dimension' => 1, 'is_active' => true]);
    }

    private function workflow(string $companyDb, User $submitter, int $poolId): void
    {
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => $companyDb,
            'submitter_id' => $submitter->id,
            'pool_id' => $poolId,
            'document_type' => 'PURCHASE_REQUEST',
            'name' => 'Workflow '.$companyDb,
            'is_active' => true,
        ]);
        $approver = User::factory()->create();
        $workflow->steps()->create(['approver_id' => $approver->id, 'step_order' => 1, 'notification_email' => $approver->email]);
    }

    private function item(string $companyDb): SapItemCache
    {
        return SapItemCache::query()->create([
            'company_db' => $companyDb,
            'item_code' => 'I05358',
            'item_name' => 'Baterai GS NS 70',
            'inventory_uom' => 'PCS',
            'is_inventory_item' => false,
            'is_purchase_item' => true,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function purchaseRequestData(SapItemCache $item): array
    {
        return [
            'transaction_uuid' => (string) Str::uuid(),
            'required_date' => now()->addDay()->toDateString(),
            'priority' => 'NORMAL',
            'lines' => [['item_id' => $item->id, 'vehicle_cost_center_id' => null, 'quantity' => 1, 'ending_km' => 1000]],
        ];
    }

    private function submitPurchaseRequest(User $user, SapItemCache $item, string $companyDb): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['sap_company_db' => $companyDb])
            ->post(route('purchase-requests.store'), $this->purchaseRequestData($item));
    }
}
