<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Jobs\SendApprovalRequestEmail;
use App\Models\ApprovalWorkflow;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\IssueType;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoodsIssueSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21 09:00:00');
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
        config()->set('sap.dimensions.department', 1);
        config()->set('sap.dimensions.pool', 2);
        config()->set('sap.goods_issue.account_codes.BAN-MT', '610100');
        config()->set('sap.goods_issue.serial_issue_type_codes', ['BAN-MT']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_goods_issue_posts_fpk_dimensions_account_and_serial_numbers_to_sap(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user->operationalProfile->pool_id);
        $transactionUuid = (string) Str::uuid();

        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"></EntityType><ComplexType Name="Addon"><Property Name="U_FPK" Type="Edm.String" /><Property Name="U_Pool" Type="Edm.String" /></ComplexType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="InventoryGenExits" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/InventoryGenExits' => Http::response(['DocEntry' => 131, 'DocNum' => 70000131]),
        ]);

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => $transactionUuid,
            'fpk_number' => 'FPK0000001',
            'fpk_date' => '2026-10-21',
            'vehicle_cost_center_id' => $vehicle->id,
            'ending_km' => 125000,
            'complaint' => 'Ban depan retak',
            'damage_analysis' => 'Ban tidak layak pakai',
            'attachments' => [UploadedFile::fake()->create('scan-fpk.pdf', 200, 'application/pdf')],
            'lines' => [[
                'item_id' => $item->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 2,
                'quantity' => 2,
                'serial_numbers' => "SN-BAN-001\nSN-BAN-002",
            ]],
        ]);

        $response->assertRedirect(route('goods-issues.index'));
        $response->assertSessionHasNoErrors();
        Http::assertNothingSent();
        Queue::assertNotPushed(SendApprovalRequestEmail::class);
        $this->assertDatabaseHas('sap_item_warehouse_stocks', [
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => 'DMI-01',
            'in_stock' => 10,
            'available_stock' => 9,
        ]);
        $document = SapDocument::query()->with('approval.steps')->where('transaction_uuid', $transactionUuid)->firstOrFail();
        $attachment = $document->attachments()->sole();
        $this->assertSame('scan-fpk.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('I00078', data_get($document->normalized_data, 'lines.0.item_code'));
        $this->assertSame('Ban 245/70 R16', data_get($document->normalized_data, 'lines.0.item_name'));
        $this->assertSame('PCS', data_get($document->normalized_data, 'lines.0.inventory_uom'));
        $this->assertSame('BAN-MT', data_get($document->normalized_data, 'lines.0.issue_type.code'));
        $this->assertSame('610100', data_get($document->normalized_data, 'lines.0.issue_type.account_code'));
        $approvalStep = $document->approval->steps->first();
        $this->post($approvalStep->approvalUrl(), ['decision' => 'APPROVE'])->assertNotFound();
        $otherAdminHo = User::factory()->create(['role' => UserRole::AdminHo]);
        UserOperationalProfile::query()->create([
            'user_id' => $otherAdminHo->id,
            'pool_id' => $user->operationalProfile->pool_id,
            'department_id' => $user->operationalProfile->department_id,
            'warehouse_code' => $user->operationalProfile->warehouse_code,
        ]);
        $this->actingAs($otherAdminHo)->post(route('goods-issue-approvals.decide', $approvalStep), ['decision' => 'APPROVE'])->assertForbidden();
        Http::assertNothingSent();
        $this->withoutVite()->actingAs($approver)->get(route('goods-issue-approvals.index'))
            ->assertOk()
            ->assertSee('FPK0000001')
            ->assertSee('Review');
        $this->actingAs($approver)->post(route('goods-issue-approvals.decide', $approvalStep), ['decision' => 'APPROVE'])->assertRedirect();
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://sap.test/b1s/v2/InventoryGenExits') {
                return false;
            }

            return $request['DocDate'] === '2026-10-21'
                && $request['Reference2'] === 'FPK0000001'
                && $request['Comments'] === 'BK8748ES / FPK FPK0000001'
                && ! isset($request['U_FPK'], $request['U_Pool'])
                && $request['DocumentLines'][0]['ItemCode'] === 'I00078'
                && $request['DocumentLines'][0]['AccountCode'] === '610100'
                && $request['DocumentLines'][0]['CostingCode'] === 'TSP'
                && $request['DocumentLines'][0]['CostingCode2'] === 'DMI'
                && $request['DocumentLines'][0]['CostingCode3'] === 'BK8748ES'
                && $request['DocumentLines'][0]['SerialNumbers'][0]['InternalSerialNumber'] === 'SN-BAN-001'
                && $request['DocumentLines'][0]['SerialNumbers'][1]['InternalSerialNumber'] === 'SN-BAN-002';
        });
        $this->assertDatabaseHas('sap_documents', [
            'transaction_uuid' => $transactionUuid,
            'document_type' => 'GOODS_ISSUE',
            'fpk_number' => 'FPK0000001',
            'sap_doc_entry' => 131,
        ]);
        $this->assertDatabaseHas('sap_item_warehouse_stocks', [
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => 'DMI-01',
            'in_stock' => 8,
            'available_stock' => 7,
        ]);
    }

    public function test_goods_issue_form_excludes_fields_that_are_not_part_of_the_process(): void
    {
        [$user] = $this->operationalContext();

        $response = $this->withoutVite()->actingAs($user)->get(route('goods-issues.create'));

        $response->assertOk()
            ->assertDontSee('Nama Driver / AMT')
            ->assertDontSee('Dibuat / Diserahkan Oleh')
            ->assertDontSee('Keterangan Tambahan')
            ->assertDontSee('name="issue_type_id"', false)
            ->assertSee('name="lines[0][issue_type_id]"', false)
            ->assertSee('Keluhan / Indikasi Kerusakan');
    }

    public function test_each_goods_issue_line_posts_its_own_issue_type_account_code(): void
    {
        [$user, $vehicle, $firstItem, $firstIssueType] = $this->operationalContext();
        $secondItem = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I02042',
            'item_name' => 'Baterai Yuasa NS 70',
            'inventory_uom' => 'PCS',
            'is_inventory_item' => true,
            'is_active' => true,
        ]);
        SapItemWarehouseStock::query()->create([
            'company_db' => 'TEST_DB',
            'sap_item_cache_id' => $secondItem->id,
            'warehouse_code' => 'DMI-01',
            'in_stock' => 5,
            'committed_stock' => 0,
            'available_stock' => 5,
            'ordered_stock' => 0,
            'minimum_stock' => 0,
            'is_locked' => false,
            'synced_at' => now(),
        ]);
        $secondIssueType = IssueType::query()->create([
            'code' => 'SPR-MT',
            'name' => 'Sparepart Mobil Tangki',
            'is_active' => true,
        ]);
        config()->set('sap.goods_issue.account_codes.SPR-MT', '5114.5001');
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user->operationalProfile->pool_id);
        $transactionUuid = (string) Str::uuid();

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="InventoryGenExits" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/InventoryGenExits' => Http::response(['DocEntry' => 132, 'DocNum' => 70000132]),
        ]);

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => $transactionUuid,
            'fpk_number' => 'FPK0000005',
            'fpk_date' => '2026-10-21',
            'vehicle_cost_center_id' => $vehicle->id,
            'ending_km' => 125000,
            'complaint' => 'Ban dan baterai perlu diganti',
            'damage_analysis' => 'Keduanya tidak layak pakai',
            'lines' => [
                [
                    'item_id' => $firstItem->id,
                    'issue_type_id' => $firstIssueType->id,
                    'requested_quantity' => 1,
                    'quantity' => 1,
                    'serial_numbers' => 'SN-BAN-001',
                ],
                [
                    'item_id' => $secondItem->id,
                    'issue_type_id' => $secondIssueType->id,
                    'requested_quantity' => 1,
                    'quantity' => 1,
                    'serial_numbers' => '',
                ],
            ],
        ]);

        $response->assertRedirect(route('goods-issues.index'))->assertSessionHasNoErrors();
        $document = SapDocument::query()->with('approval.steps')->where('transaction_uuid', $transactionUuid)->firstOrFail();
        $this->actingAs($approver)->post(route('goods-issue-approvals.decide', $document->approval->steps->first()), ['decision' => 'APPROVE'])->assertRedirect();
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://sap.test/b1s/v2/InventoryGenExits') {
                return false;
            }

            return $request['DocumentLines'][0]['AccountCode'] === '610100'
                && $request['DocumentLines'][1]['AccountCode'] === '5114.5001'
                && $request['DocumentLines'][0]['ItemCode'] === 'I00078'
                && $request['DocumentLines'][1]['ItemCode'] === 'I02042';
        });
    }

    public function test_tire_issue_requires_one_serial_number_per_issued_unit(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        Http::fake();

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK0000002',
            'fpk_date' => '2026-10-21',
            'vehicle_cost_center_id' => $vehicle->id,
            'ending_km' => 125000,
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 2,
                'quantity' => 2,
                'serial_numbers' => 'SN-BAN-001',
            ]],
        ]);

        $response->assertSessionHasErrors('lines.0.serial_numbers');
        Http::assertNothingSent();
    }

    public function test_goods_issue_cannot_use_a_past_fpk_date(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK0000003',
            'fpk_date' => '2026-10-20',
            'vehicle_cost_center_id' => $vehicle->id,
            'ending_km' => 125000,
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 1,
                'quantity' => 1,
                'serial_numbers' => 'SN-BAN-001',
            ]],
        ]);

        $response->assertSessionHasErrors('fpk_date');
    }

    public function test_goods_issue_rejects_an_fpk_number_longer_than_the_sap_reference_field(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK-NUMBER-TOO-LONG',
            'fpk_date' => '2026-10-21',
            'vehicle_cost_center_id' => $vehicle->id,
            'ending_km' => 125000,
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 1,
                'quantity' => 1,
                'serial_numbers' => 'SN-BAN-001',
            ]],
        ]);

        $response->assertSessionHasErrors('fpk_number');
        Http::assertNothingSent();
    }

    public function test_goods_issue_rejects_quantity_above_available_user_warehouse_stock(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        SapItemWarehouseStock::query()->where('sap_item_cache_id', $item->id)->update([
            'in_stock' => 3,
            'committed_stock' => 2,
            'available_stock' => 1,
        ]);
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK0000004',
            'fpk_date' => '2026-10-21',
            'vehicle_cost_center_id' => $vehicle->id,
            'ending_km' => 125000,
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 2,
                'quantity' => 2,
                'serial_numbers' => "SN-BAN-001\nSN-BAN-002",
            ]],
        ]);

        $response->assertSessionHasErrors('lines.0.quantity');
        Http::assertNothingSent();
    }

    /** @return array{User, SapCostCenterCache, SapItemCache, IssueType} */
    private function operationalContext(): array
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-tsp',
            'code' => 'TSP',
            'name' => 'Transport',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);
        SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'TSP',
            'center_name' => 'Transport',
            'dimension' => 1,
            'is_active' => true,
        ]);
        SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'DMI',
            'center_name' => 'Dumai',
            'dimension' => 2,
            'is_active' => true,
        ]);
        $vehicle = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'BK8748ES',
            'center_name' => 'Mobil Tangki',
            'dimension' => 3,
            'is_active' => true,
        ]);
        $item = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00078',
            'item_name' => 'Ban 245/70 R16',
            'inventory_uom' => 'PCS',
            'is_inventory_item' => true,
            'is_active' => true,
        ]);
        SapItemWarehouseStock::query()->create([
            'company_db' => 'TEST_DB',
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => 'DMI-01',
            'in_stock' => 10,
            'committed_stock' => 1,
            'available_stock' => 9,
            'ordered_stock' => 2,
            'minimum_stock' => 2,
            'is_locked' => false,
            'synced_at' => now(),
        ]);
        $issueType = IssueType::query()->create([
            'code' => 'BAN-MT',
            'name' => 'Ban Mobil Tangki',
            'is_active' => true,
        ]);

        return [$user, $vehicle, $item, $issueType];
    }

    private function approvalWorkflow(string $documentType, int $poolId): User
    {
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $poolId,
            'document_type' => $documentType,
            'name' => 'Approval Admin HO',
            'is_active' => true,
        ]);
        $approver = User::factory()->create(['role' => UserRole::AdminHo]);
        $submitterProfile = UserOperationalProfile::query()->where('pool_id', $poolId)->firstOrFail();
        UserOperationalProfile::query()->create([
            'user_id' => $approver->id,
            'pool_id' => $submitterProfile->pool_id,
            'department_id' => $submitterProfile->department_id,
            'warehouse_code' => $submitterProfile->warehouse_code,
        ]);
        $workflow->steps()->create([
            'approver_id' => $approver->id,
            'step_order' => 1,
            'notification_email' => $approver->email,
        ]);

        return $approver;
    }
}
