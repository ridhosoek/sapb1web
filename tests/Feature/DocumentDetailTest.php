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
            'vehicle_cost_center' => ['center_code' => 'BK8815FG'],
            'lines' => [[
                'item_id' => $item->id,
                'item_code' => 'I05358',
                'item_name' => 'Baterai GS NS 70',
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
            'lines' => [],
        ], null, 'PURCHASE_ORDER', '260002001');
        $paymentRequest = $this->document($pool, $department, [
            'status' => 'NEW',
            'type' => 'VENDOR',
            'lines' => [],
        ], null, 'PAYMENT_REQUEST', '260305001');

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
            'warehouse_code' => $poolCode.'-01',
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
            'normalized_data' => $normalizedData,
        ]);
    }
}
