<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Jobs\SendApprovalRequestEmail;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\DocumentApproval;
use App\Models\DocumentApprovalStep;
use App\Models\IssueType;
use App\Models\SapCommand;
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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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
        config()->set('sap.fields.web_created_by_user', 'U_UserWebSAP');
        config()->set('sap.dimensions.department', 1);
        config()->set('sap.dimensions.pool', 2);
        config()->set('sap.goods_issue.account_codes.BAN-MT', '610100');
        config()->set('sap.goods_issue.serial_issue_type_codes', ['BAN-MT']);
        config()->set('sap.goods_issue.issue_type_field', 'U_VTPI');
        config()->set('sap.goods_issue.current_km_field', 'U_KM1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_goods_issue_posts_fpk_dimensions_account_and_serial_numbers_to_sap(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $transactionUuid = (string) Str::uuid();

        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_UserWebSAP" Type="Edm.String" /></EntityType><ComplexType Name="Addon"><Property Name="U_FPK" Type="Edm.String" /><Property Name="U_Pool" Type="Edm.String" /></ComplexType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="InventoryGenExits" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/InventoryGenExits' => Http::response(['DocEntry' => 131, 'DocNum' => 70000131]),
        ]);

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => $transactionUuid,
            'fpk_number' => 'FPK0000001',
            'fpk_date' => '2026-10-21',
            'complaint' => 'Ban depan retak',
            'damage_analysis' => 'Ban tidak layak pakai',
            'attachments' => [UploadedFile::fake()->create('scan-fpk.jpg', 200, 'image/jpeg')],
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicle->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 2,
                'quantity' => 2,
                'ending_km' => 125000,
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
        $this->assertSame('scan-fpk.jpg', $attachment->original_name);
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
        Http::assertSent(function (Request $request) use ($user): bool {
            if ($request->url() !== 'https://sap.test/b1s/v2/InventoryGenExits') {
                return false;
            }

            return $request['DocDate'] === '2026-10-21'
                && $request['Reference2'] === 'FPK0000001'
                && $request['Comments'] === 'FPK0000001 - BK8748ES - Dumai'
                && $request['U_UserWebSAP'] === $user->name
                && ! isset($request['U_FPK'], $request['U_Pool'])
                && $request['DocumentLines'][0]['ItemCode'] === 'I00078'
                && $request['DocumentLines'][0]['AccountCode'] === '610100'
                && $request['DocumentLines'][0]['CostingCode'] === 'TSP'
                && $request['DocumentLines'][0]['CostingCode2'] === 'DMI'
                && $request['DocumentLines'][0]['CostingCode3'] === 'BK8748ES'
                && $request['DocumentLines'][0]['U_VTPI'] === 'BAN-MT'
                && $request['DocumentLines'][0]['U_KM1'] === 125000
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
            ->assertSee('name="lines[0][vehicle_cost_center_id]"', false)
            ->assertSee('name="lines[0][ending_km]"', false)
            ->assertDontSee('name="ending_km"', false)
            ->assertSee('accept="image/jpeg,image/png"', false)
            ->assertSee('data-max-size-bytes="1048576"', false)
            ->assertSee('Keluhan / Indikasi Kerusakan');
    }

    public function test_goods_issue_submission_rejects_pdf_and_photo_larger_than_one_megabyte(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        Http::preventStrayRequests();
        $payload = [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK0000006',
            'fpk_date' => '2026-10-21',
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicle->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 1,
                'quantity' => 1,
                'ending_km' => 125000,
                'serial_numbers' => 'SN-BAN-001',
            ]],
        ];

        $this->actingAs($user)->post(route('goods-issues.store'), $payload + [
            'attachments' => [UploadedFile::fake()->create('scan-fpk.pdf', 250, 'application/pdf')],
        ])->assertSessionHasErrors('attachments.0');

        $payload['transaction_uuid'] = (string) Str::uuid();
        $this->actingAs($user)->post(route('goods-issues.store'), $payload + [
            'attachments' => [UploadedFile::fake()->create('scan-fpk.png', 1025, 'image/png')],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('sap_documents', 0);
        Http::assertNothingSent();
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
        $secondIssueType = IssueType::query()->updateOrCreate(['code' => 'SPR-MT'], [
            'name' => 'Sparepart Mobil Tangki',
            'is_active' => true,
        ]);
        config()->set('sap.goods_issue.account_codes.SPR-MT', '5114.5001');
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
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
            'complaint' => 'Ban dan baterai perlu diganti',
            'damage_analysis' => 'Keduanya tidak layak pakai',
            'lines' => [
                [
                    'item_id' => $firstItem->id,
                    'vehicle_cost_center_id' => $vehicle->id,
                    'issue_type_id' => $firstIssueType->id,
                    'requested_quantity' => 1,
                    'quantity' => 1,
                    'ending_km' => 125000,
                    'serial_numbers' => 'SN-BAN-001',
                ],
                [
                    'item_id' => $secondItem->id,
                    'vehicle_cost_center_id' => $vehicle->id,
                    'issue_type_id' => $secondIssueType->id,
                    'requested_quantity' => 1,
                    'quantity' => 1,
                    'ending_km' => 130000,
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
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicle->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 2,
                'quantity' => 2,
                'ending_km' => 125000,
                'serial_numbers' => 'SN-BAN-001',
            ]],
        ]);

        $response->assertSessionHasErrors('lines.0.serial_numbers');
        Http::assertNothingSent();
    }

    public function test_goods_issue_maps_optional_nopol_per_detail_line_and_aggregates_duplicate_item_stock(): void
    {
        [$user, $firstVehicle, $item, $issueType] = $this->operationalContext();
        $secondVehicle = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'BK9921XY',
            'center_name' => 'BK9921XY',
            'dimension' => 3,
            'is_active' => true,
        ]);
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $transactionUuid = (string) Str::uuid();

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="InventoryGenExits" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/InventoryGenExits' => Http::response(['DocEntry' => 133, 'DocNum' => 70000133]),
        ]);

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => $transactionUuid,
            'fpk_number' => 'FPK0000007',
            'fpk_date' => '2026-10-21',
            'complaint' => 'Ban aus pada beberapa kendaraan',
            'damage_analysis' => 'Tread habis',
            'lines' => [
                [
                    'item_id' => $item->id,
                    'vehicle_cost_center_id' => $firstVehicle->id,
                    'issue_type_id' => $issueType->id,
                    'requested_quantity' => 1,
                    'quantity' => 1,
                    'ending_km' => 125000,
                    'serial_numbers' => 'SN-BAN-101',
                ],
                [
                    'item_id' => $item->id,
                    'vehicle_cost_center_id' => $secondVehicle->id,
                    'issue_type_id' => $issueType->id,
                    'requested_quantity' => 1,
                    'quantity' => 1,
                    'ending_km' => 130000,
                    'serial_numbers' => 'SN-BAN-102',
                ],
                [
                    'item_id' => $item->id,
                    'issue_type_id' => $issueType->id,
                    'requested_quantity' => 1,
                    'quantity' => 1,
                    'ending_km' => 0,
                    'serial_numbers' => 'SN-BAN-103',
                ],
            ],
        ]);

        $response->assertRedirect(route('goods-issues.index'))->assertSessionHasNoErrors();
        $document = SapDocument::query()->with('approval.steps')->where('transaction_uuid', $transactionUuid)->firstOrFail();
        $this->assertSame('BK8748ES', data_get($document->normalized_data, 'lines.0.vehicle_cost_center.center_code'));
        $this->assertSame('BK9921XY', data_get($document->normalized_data, 'lines.1.vehicle_cost_center.center_code'));
        $this->assertNull(data_get($document->normalized_data, 'lines.2.vehicle_cost_center'));

        $this->actingAs($approver)->post(route('goods-issue-approvals.decide', $document->approval->steps->first()), ['decision' => 'APPROVE'])->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://sap.test/b1s/v2/InventoryGenExits') {
                return false;
            }

            return $request['DocumentLines'][0]['CostingCode3'] === 'BK8748ES'
                && $request['DocumentLines'][1]['CostingCode3'] === 'BK9921XY'
                && ! isset($request['DocumentLines'][2]['CostingCode3']);
        });
        $this->assertDatabaseHas('sap_item_warehouse_stocks', [
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => 'DMI-01',
            'in_stock' => 7,
            'available_stock' => 6,
        ]);
    }

    public function test_goods_issue_submission_is_rejected_without_leaving_records_when_only_another_company_has_a_workflow(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'OTHER_DB',
            'submitter_id' => $user->id,
            'pool_id' => $user->operationalProfile->pool_id,
            'document_type' => 'GOODS_ISSUE',
            'name' => 'Approval Admin HO company lain',
            'is_active' => true,
        ]);
        $workflow->steps()->create(['approver_id' => User::factory()->create()->id, 'step_order' => 1]);
        Queue::fake();
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK0000004',
            'fpk_date' => '2026-10-21',
            'complaint' => 'Ban depan retak',
            'damage_analysis' => 'Ban tidak layak pakai',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicle->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 1,
                'quantity' => 1,
                'ending_km' => 125000,
                'serial_numbers' => 'SN-BAN-001',
            ]],
        ]);

        $response->assertSessionHasErrors('sap');
        $this->assertStringContainsString('pada database TEST_DB belum dikonfigurasi', session('errors')->first('sap'));
        $this->assertSame(0, SapDocument::query()->count());
        $this->assertSame(0, SapCommand::query()->count());
        $this->assertSame(0, DocumentApproval::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('acceptedFpkDates')]
    public function test_goods_issue_accepts_an_fpk_date_of_today_or_yesterday(string $fpkDate): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $this->approvalWorkflow('GOODS_ISSUE', $user);

        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, $fpkDate)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('goods-issues.index'));

        $this->assertSame($fpkDate, SapDocument::query()->sole()->normalized_data['fpk_date']);
    }

    /** @return array<string, array{string}> */
    public static function acceptedFpkDates(): array
    {
        return ['today' => ['2026-10-21'], 'yesterday (H-1)' => ['2026-10-20']];
    }

    #[DataProvider('rejectedFpkDates')]
    public function test_goods_issue_rejects_an_fpk_date_older_than_h_minus_1_or_in_the_future(string $fpkDate): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $this->approvalWorkflow('GOODS_ISSUE', $user);

        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, $fpkDate)
            ->assertSessionHasErrors(['fpk_date' => 'Tanggal FPK hanya boleh hari ini atau H-1.']);

        $this->assertSame(0, SapDocument::query()->count());
        $this->assertSame(0, SapCommand::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function rejectedFpkDates(): array
    {
        return [
            'two days ago (H-2)' => ['2026-10-19'],
            'tomorrow' => ['2026-10-22'],
            'far past' => ['2025-01-01'],
        ];
    }

    public function test_goods_issue_form_offers_only_today_and_yesterday_and_mirrors_the_sap_dates(): void
    {
        [$user] = $this->operationalContext();

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('goods-issues.create'))
            ->assertOk()
            ->assertSee('name="fpk_date" type="date" min="2026-10-20" max="2026-10-21" value="2026-10-21"', false)
            ->assertSee('Hanya hari ini atau H-1 (20/10/2026)')
            ->assertSee('data-fpk-date-mirror>21/10/2026<', false);
    }

    public function test_goods_issue_posts_the_fpk_date_as_both_sap_dates_for_an_h_minus_1_fpk(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $this->fakeSapGoodsIssuePost();

        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-20')->assertSessionHasNoErrors();
        $this->approveGoodsIssue($approver)->assertRedirect();

        $this->assertGoodsIssuePostedToSapTimes(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/InventoryGenExits'
            && $request['DocDate'] === '2026-10-20'
            && $request['TaxDate'] === '2026-10-20');
        $this->assertSame('IN_SYNC', SapDocument::query()->sole()->integration_status->value);
    }

    public function test_goods_issue_still_posts_when_final_approval_happens_the_next_day_and_the_fpk_is_now_h_minus_1(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $this->fakeSapGoodsIssuePost();
        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-21')->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-22 08:00:00');
        $this->approveGoodsIssue($approver)->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/InventoryGenExits'
            && $request['DocDate'] === '2026-10-21'
            && $request['TaxDate'] === '2026-10-21');
        $this->assertSame('APPROVED', DocumentApproval::query()->sole()->status);
    }

    public function test_goods_issue_with_an_fpk_that_became_older_than_h_minus_1_is_rejected_before_approved_and_before_posting(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $this->fakeSapGoodsIssuePost();
        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-20', 'FPK0000010')->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-22 08:00:00');
        $response = $this->approveGoodsIssue($approver);

        $document = SapDocument::query()->sole();
        $response->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'ditolak otomatis')
                && str_contains($message, 'FPK fisik baru')
                && str_contains($message, 'nomor FPK baru')
                && str_contains($message, 'tanggal FPK baru'));
        Http::assertNothingSent();
        $approval = DocumentApproval::query()->with('steps')->sole();
        $this->assertSame('REJECTED', $approval->status);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame('REJECTED', $approval->steps->sole()->status);
        $this->assertStringContainsString('FPK fisik baru', $approval->steps->sole()->decision_note);
        $this->assertSame('REJECTED', $document->normalized_data['approval_status']);
        $this->assertSame('FPK_DATE_EXPIRED', $document->normalized_data['approval_rejection_reason']);
        $this->assertTrue($document->normalized_data['approval_rejected_automatically']);
        $this->assertSame('PENDING_POST', $document->integration_status->value);
        $this->assertNull($document->sap_doc_entry);
        $command = SapCommand::query()->sole();
        $this->assertSame('PENDING_POST', $command->status->value);
        $this->assertSame(0, $command->attempts);
        $this->assertNull($command->request_payload);
        $this->assertStringContainsString('FPK fisik baru', $command->last_error);
        $audit = AuditLog::query()->where('event', 'goods_issue.rejected_expired_fpk_date')->sole();
        $this->assertSame($approver->id, $audit->actor_id);
        $this->assertSame($document->id, $audit->auditable_id);
        $this->assertSame('2026-10-20', $audit->metadata['fpk_date']);
        $this->assertSame('2026-10-21', $audit->metadata['earliest_allowed_date']);
        $this->assertTrue($audit->metadata['automatic']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'document.approved']);
        $this->assertDatabaseHas('sap_item_warehouse_stocks', ['sap_item_cache_id' => $item->id, 'available_stock' => 9]);
    }

    public function test_an_automatically_rejected_goods_issue_cannot_be_decided_or_posted_again(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $this->fakeSapGoodsIssuePost();
        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-20')->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-22 08:00:00');
        $this->approveGoodsIssue($approver);

        $this->approveGoodsIssue($approver)->assertSessionHasErrors('approval');
        $this->artisan('sap:post-approved')->assertSuccessful();
        $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        UserOperationalProfile::query()->create([
            'user_id' => $superAdmin->id,
            'pool_id' => $user->operationalProfile->pool_id,
            'department_id' => $user->operationalProfile->department_id,
            'warehouse_code' => $user->operationalProfile->warehouse_code,
        ]);
        $this->actingAs($superAdmin)
            ->post(route('documents.post-approved', SapDocument::query()->sole()))
            ->assertSessionHasErrors(['sap' => 'Dokumen belum mendapatkan approval final.']);

        Http::assertNothingSent();
        $this->assertSame(1, AuditLog::query()->where('event', 'goods_issue.rejected_expired_fpk_date')->count());
        $this->assertSame('REJECTED', DocumentApproval::query()->sole()->status);
    }

    public function test_an_fpk_number_rejected_for_an_expired_date_cannot_be_submitted_again(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $this->fakeSapGoodsIssuePost();
        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-20', 'FPK0000010')->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-10-22 08:00:00');
        $this->approveGoodsIssue($approver);

        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-22', 'FPK0000010')
            ->assertSessionHasErrors('fpk_number');
        $this->assertSame(1, SapDocument::query()->count());

        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-22', 'FPK0000011')
            ->assertSessionHasNoErrors();
        $this->assertSame(2, SapDocument::query()->count());
    }

    public function test_manual_rejection_of_a_goods_issue_is_not_marked_as_an_automatic_date_rejection(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $this->fakeSapGoodsIssuePost();
        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-20', 'FPK0000012')->assertSessionHasNoErrors();

        $this->approveGoodsIssue($approver, 'REJECT', 'Foto FPK tidak terbaca')->assertRedirect();

        Http::assertNothingSent();
        $document = SapDocument::query()->sole();
        $this->assertSame('REJECTED', DocumentApproval::query()->sole()->status);
        $this->assertSame('REJECTED', $document->normalized_data['approval_status']);
        $this->assertArrayNotHasKey('approval_rejection_reason', $document->normalized_data);
        $this->assertDatabaseHas('audit_logs', ['event' => 'document.rejected']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'goods_issue.rejected_expired_fpk_date']);

        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-21', 'FPK0000012')->assertSessionHasNoErrors();
    }

    public function test_sap_rejection_after_final_approval_keeps_the_fpk_dates_and_records_status_and_audit(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        $approver = $this->approvalWorkflow('GOODS_ISSUE', $user);
        $this->fakeSapGoodsIssuePost(['error' => ['message' => ['value' => 'Fake SAP rejection: posting period is closed']]], 400);
        $this->submitGoodsIssue($user, $vehicle, $item, $issueType, '2026-10-20', 'FPK0000013')->assertSessionHasNoErrors();

        $this->approveGoodsIssue($approver)->assertRedirect();

        $this->assertGoodsIssuePostedToSapTimes(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/InventoryGenExits'
            && $request['DocDate'] === '2026-10-20'
            && $request['TaxDate'] === '2026-10-20');
        $document = SapDocument::query()->sole();
        $this->assertSame('SAP_ERROR', $document->integration_status->value);
        $this->assertSame('APPROVED', DocumentApproval::query()->sole()->status);
        $command = SapCommand::query()->sole();
        $this->assertSame('SAP_ERROR', $command->status->value);
        $this->assertSame('2026-10-20', $command->request_payload['DocDate']);
        $this->assertStringContainsString('posting period is closed', $command->last_error);
        $audit = AuditLog::query()->where('event', 'goods_issue.sap_post_rejected')->sole();
        $this->assertSame('2026-10-20', $audit->metadata['fpk_date']);
        $this->assertStringContainsString('posting period is closed', $audit->metadata['sap_error']);
        $this->assertDatabaseHas('sap_item_warehouse_stocks', ['sap_item_cache_id' => $item->id, 'available_stock' => 9]);
    }

    public function test_goods_issue_rejects_an_fpk_number_longer_than_the_sap_reference_field(): void
    {
        [$user, $vehicle, $item, $issueType] = $this->operationalContext();
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK-NUMBER-TOO-LONG',
            'fpk_date' => '2026-10-21',
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicle->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 1,
                'quantity' => 1,
                'ending_km' => 125000,
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
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicle->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 2,
                'quantity' => 2,
                'ending_km' => 125000,
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
        $issueType = IssueType::query()->updateOrCreate(['code' => 'BAN-MT'], [
            'name' => 'Ban Mobil Tangki',
            'is_active' => true,
        ]);

        return [$user, $vehicle, $item, $issueType];
    }

    private function submitGoodsIssue(User $user, SapCostCenterCache $vehicle, SapItemCache $item, IssueType $issueType, string $fpkDate, string $fpkNumber = 'FPK0000003'): TestResponse
    {
        return $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => $fpkNumber,
            'fpk_date' => $fpkDate,
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicle->id,
                'issue_type_id' => $issueType->id,
                'requested_quantity' => 1,
                'quantity' => 1,
                'ending_km' => 125000,
                'serial_numbers' => 'SN-BAN-001',
            ]],
        ]);
    }

    private function approveGoodsIssue(User $approver, string $decision = 'APPROVE', ?string $note = null): TestResponse
    {
        $step = DocumentApprovalStep::query()->latest('id')->firstOrFail();

        return $this->actingAs($approver)->post(route('goods-issue-approvals.decide', $step), [
            'decision' => $decision,
            'note' => $note,
        ]);
    }

    private function assertGoodsIssuePostedToSapTimes(int $times): void
    {
        $this->assertCount($times, collect(Http::recorded())->filter(
            fn (array $exchange): bool => $exchange[0]->method() === 'POST'
                && $exchange[0]->url() === 'https://sap.test/b1s/v2/InventoryGenExits',
        ));
    }

    /** @param array<string, mixed> $response */
    private function fakeSapGoodsIssuePost(array $response = ['DocEntry' => 131, 'DocNum' => 70000131], int $status = 201): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_UserWebSAP" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="InventoryGenExits" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/InventoryGenExits' => Http::response($response, $status),
        ]);
    }

    private function approvalWorkflow(string $documentType, User $submitter): User
    {
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'TEST_DB',
            'submitter_id' => $submitter->id,
            'pool_id' => $submitter->operationalProfile->pool_id,
            'document_type' => $documentType,
            'name' => 'Approval Admin HO',
            'is_active' => true,
        ]);
        $approver = User::factory()->create(['role' => UserRole::AdminHo]);
        $submitterProfile = $submitter->operationalProfile;
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
