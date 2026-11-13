<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
    }

    public function test_user_can_open_document_detail_for_their_company_and_pool(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $item = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I05358',
            'item_name' => 'Baterai GS NS 70',
            'inventory_uom' => 'PCS',
            'is_purchase_item' => true,
            'is_active' => true,
        ]);
        $document = $this->document($pool, $department, [
            'priority' => 'URGENT',
            'required_date' => '2026-10-22',
            'notes' => 'Untuk kendaraan BK8815FG',
            'lines' => [[
                'item_id' => $item->id,
                'item_code' => 'I05358',
                'item_name' => 'Baterai GS NS 70',
                'vehicle_cost_center' => ['center_code' => 'BK8815FG'],
                'quantity' => 2,
                'free_text' => 'Merek GS',
                'usage_history' => [
                    'vehicle_code' => 'BK8815FG',
                    'status' => 'FOUND',
                    'last_goods_issue_date' => '2026-10-01',
                    'days_in_use' => 21,
                    'quantity' => 1,
                    'expense_amount' => 425000,
                    'fpk' => 'FPK-HISTORY',
                ],
            ]],
        ]);

        $response = $this->withoutVite()->actingAs($user)->get(route('documents.show', $document));

        $response->assertOk()
            ->assertSee('Detail Purchase Request')
            ->assertSee('260001426')
            ->assertSee('URGENT')
            ->assertSee('BK8815FG')
            ->assertSee('I05358')
            ->assertSee('Baterai GS NS 70')
            ->assertSee('Merek GS')
            ->assertSee('Snapshot Riwayat Pemakaian')
            ->assertSee('FPK-HISTORY')
            ->assertSee('425.000')
            ->assertSee('21 hari')
            ->assertDontSee('Lihat JSON yang dikirim ke SAP');
    }

    public function test_purchase_request_lines_are_shown_as_scannable_rows_without_internal_labels(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $longNote = str_repeat('Pengganti kampas rem depan kiri dan kanan ', 6);
        $lines = collect(range(1, 12))->map(fn (int $number): array => [
            'item_id' => 900 + $number,
            'item_code' => sprintf('I%05d', $number),
            'item_name' => 'Item uji nomor '.$number,
            'inventory_uom' => 'PCS',
            'vehicle_cost_center_id' => 5,
            'vehicle_cost_center' => ['id' => 5, 'center_code' => 'BK8815FG', 'center_name' => 'Truk Hino BK8815FG'],
            'quantity' => $number === 1 ? 1500.5 : 2,
            'ending_km' => 123456,
            'manual_uom' => $number === 2 ? 'DUS' : null,
            'free_text' => match ($number) {
                1 => $longNote,
                3 => '',
                default => null,
            },
        ])->all();
        $document = $this->document($pool, $department, ['lines' => $lines]);

        $response = $this->withoutVite()->actingAs($user)->get(route('documents.show', $document));

        $response->assertOk()
            ->assertSee('12 baris')
            ->assertSee('I00001')
            ->assertSee('I00012')
            ->assertSee('Item uji nomor 12')
            ->assertSee('BK8815FG')
            ->assertSee('123.456')
            ->assertSee('1.500,5')
            ->assertSee('DUS')
            ->assertSee(trim($longNote))
            ->assertSee('Rincian')
            ->assertSee('Nama kendaraan')
            ->assertSee('Truk Hino BK8815FG')
            ->assertDontSee('Vehicle Cost Center Id')
            ->assertDontSee('Manual Uom')
            ->assertDontSee('Inventory Uom')
            ->assertDontSee('Ending Km')
            ->assertDontSee('Free Text');
        $this->assertSame(12, substr_count($response->getContent(), 'data-purchase-request-line>'));
        $rows = substr($response->getContent(), strpos($response->getContent(), 'data-purchase-request-lines'));
        $this->assertSame(1, substr_count($rows, 'I00001'), 'Item code must appear once per line.');
        $this->assertSame(1, substr_count($rows, 'Item uji nomor 1<'), 'Item name must appear once per line.');
    }

    public function test_purchase_request_line_with_missing_fields_shows_placeholders_instead_of_errors(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $document = $this->document($pool, $department, ['lines' => [['item_id' => 999999, 'quantity' => 3]]]);

        $this->withoutVite()->actingAs($user)->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('data-purchase-request-line', false)
            ->assertSee('1 baris')
            ->assertDontSee('Rincian');
    }

    public function test_purchase_request_line_detail_escapes_user_text(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $document = $this->document($pool, $department, ['lines' => [[
            'item_id' => 1, 'item_code' => 'I1', 'item_name' => '<script>alert(1)</script>', 'quantity' => 1, 'free_text' => '<b>tebal</b>',
        ]]]);

        $this->withoutVite()->actingAs($user)->get(route('documents.show', $document))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>tebal</b>', false)
            ->assertSee('&lt;b&gt;tebal&lt;/b&gt;', false);
    }

    public function test_goods_issue_lines_keep_the_generic_detail_table(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $document = $this->document($pool, $department, ['lines' => [['item_code' => 'I1', 'item_name' => 'Oli', 'quantity' => 2]]], null, 'GOODS_ISSUE', '260004618');

        $this->withoutVite()->actingAs($user)->get(route('documents.show', $document))
            ->assertOk()
            ->assertDontSee('data-purchase-request-line', false)
            ->assertSee('Item / Dokumen Sumber');
    }

    public function test_admin_can_see_the_exact_sap_request_payload(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin, 'DUMAI');
        $transactionUuid = (string) Str::uuid();
        $document = $this->document($pool, $department, ['lines' => []], $transactionUuid);
        SapCommand::query()->create([
            'company_db' => 'TEST_DB',
            'transaction_uuid' => $transactionUuid,
            'command_type' => 'CREATE_PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'status' => 'IN_SYNC',
            'request_payload' => ['DocDate' => '2026-10-22', 'U_VPRIOR' => 'NORMAL'],
            'attempts' => 1,
        ]);

        $response = $this->withoutVite()->actingAs($admin)->get(route('documents.show', $document));

        $response->assertOk()
            ->assertSee('Informasi Integrasi')
            ->assertSee('Lihat JSON yang dikirim ke SAP')
            ->assertSee('U_VPRIOR')
            ->assertSee('NORMAL');
    }

    public function test_document_detail_does_not_leak_another_company_or_pool(): void
    {
        [$user] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        [, $otherPool, $otherDepartment] = $this->operationalUser(UserRole::AdminOperational, 'MEDAN');
        $otherPoolDocument = $this->document($otherPool, $otherDepartment, ['lines' => []]);
        $otherCompanyDocument = SapDocument::query()->create([
            'company_db' => 'SECOND_DB',
            'pool_id' => $otherPool->id,
            'department_id' => $otherDepartment->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_num' => '260009999',
            'sap_business_status' => 'OPEN',
            'integration_status' => 'IN_SYNC',
            'normalized_data' => ['lines' => []],
        ]);

        $this->withoutVite()->actingAs($user)->get(route('documents.show', $otherPoolDocument))->assertForbidden();
        $this->withoutVite()->actingAs($user)->get(route('documents.show', $otherCompanyDocument))->assertNotFound();
    }

    public function test_admin_can_open_registered_user_detail(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin, 'DUMAI');
        [$registeredUser] = $this->operationalUser(UserRole::AdminOperational, 'MEDAN');

        $response = $this->withoutVite()->actingAs($admin)->get(route('admin.users.show', $registeredUser));

        $response->assertOk()
            ->assertSee('Detail User')
            ->assertSee($registeredUser->name)
            ->assertSee($registeredUser->email)
            ->assertSee('MEDAN');
    }

    public function test_every_transaction_list_links_to_its_detail_page(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin, 'DUMAI');
        $purchaseRequest = $this->document($pool, $department, [
            'progress_status' => 'WAITING_PO',
            'lines' => [],
        ]);
        $goodsIssue = $this->document($pool, $department, ['lines' => []], null, 'GOODS_ISSUE', '260004618');
        $purchaseOrder = $this->document($pool, $department, [
            'progress_status' => 'READY_FOR_GRPO',
            'series' => 887,
            'document_date' => now()->toDateString(),
            'lines' => [],
        ], null, 'PURCHASE_ORDER', '260002001');
        $paymentRequest = $this->document($pool, $department, [
            'status' => 'NEW',
            'type' => 'VENDOR',
            'lines' => [],
        ], null, 'PAYMENT_REQUEST', '260305001');
        Http::fake([
            '*/Login' => Http::response(['SessionId' => 'document-list-session']),
            '*$crossjoin(PurchaseOrders*' => Http::response(['value' => [[
                'PurchaseOrders' => [
                    'DocEntry' => 1426,
                    'DocNum' => 260002001,
                    'Series' => 887,
                    'DocDate' => now()->toDateString(),
                    'DocumentStatus' => 'bost_Open',
                    'Cancelled' => 'tNO',
                    'AuthorizationStatus' => 'dasGenerated',
                    'CardName' => 'Vendor Test',
                ],
                'PurchaseOrders/DocumentLines' => [
                    'DocEntry' => 1426,
                    'LineNum' => 0,
                    'LineStatus' => 'bost_Open',
                    'ItemCode' => 'ITEM-001',
                    'Quantity' => 1,
                    'RemainingOpenQuantity' => 1,
                    'WarehouseCode' => 'DMI-01',
                ],
            ]]]),
            '*$crossjoin(PurchaseDeliveryNotes*' => Http::response(['value' => []]),
        ]);

        foreach ([
            'purchase-requests.index' => $purchaseRequest,
            'purchase-progress.index' => $purchaseRequest,
            'goods-issues.index' => $goodsIssue,
            'ready-to-receive.index' => $purchaseOrder,
            'payment-requests.index' => $paymentRequest,
            'payment-approvals.index' => $paymentRequest,
            'dashboard' => $purchaseRequest,
        ] as $route => $document) {
            $this->withoutVite()->actingAs($admin)->get(route($route))
                ->assertOk()
                ->assertSee('href="'.route('documents.show', $document).'"', false);
        }

        $this->withoutVite()->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.users.show', $admin).'"', false);
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalUser(UserRole $role, string $poolCode): array
    {
        $pool = ControlPool::query()->create([
            'code' => $poolCode,
            'name' => $poolCode,
            'is_active' => true,
        ]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.strtolower($poolCode),
            'code' => 'TSP-'.$poolCode,
            'name' => 'Transport '.$poolCode,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => $poolCode === 'DUMAI' ? 'DMI-01' : 'MDN-01',
        ]);

        return [$user, $pool, $department];
    }

    /** @param array<string, mixed> $normalizedData */
    private function document(
        ControlPool $pool,
        ControlDepartment $department,
        array $normalizedData,
        ?string $transactionUuid = null,
        string $documentType = 'PURCHASE_REQUEST',
        string $documentNumber = '260001426',
    ): SapDocument {
        return SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => $documentType,
            'sap_entity' => match ($documentType) {
                'GOODS_ISSUE' => 'InventoryGenExits',
                'PURCHASE_ORDER' => 'PurchaseOrders',
                'PAYMENT_REQUEST' => 'PaymentRequests',
                default => 'PurchaseRequests',
            },
            'sap_doc_entry' => 1426,
            'sap_doc_num' => $documentNumber,
            'source' => 'WEB',
            'transaction_uuid' => $transactionUuid,
            'sap_business_status' => 'OPEN',
            'integration_status' => 'IN_SYNC',
            'created_from_web' => true,
            'normalized_data' => [
                ...$normalizedData,
                'warehouse_code' => $pool->code === 'DUMAI' ? 'DMI-01' : 'MDN-01',
            ],
        ]);
    }
}
