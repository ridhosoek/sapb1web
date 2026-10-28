<?php

namespace Tests\Feature;

use App\Models\ApprovalWorkflow;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseRequestSubmissionTest extends TestCase
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
        config()->set('sap.dimensions.vehicle', 3);
        config()->set('sap.purchase_request.department_costing_field', 'CostingCode');
        config()->set('sap.purchase_request.pool_costing_field', 'CostingCode2');
        config()->set('sap.purchase_request.vehicle_costing_field', 'CostingCode3');
        config()->set('sap.fields.priority', 'U_VPRIOR');
        config()->set('sap.fields.current_km', 'U_KMAKHIR');
        config()->set('sap.fields.web_created_by_user', 'U_UserWebSAP');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_purchase_request_posts_only_the_sap_fields_used_by_the_actual_process(): void
    {
        [$user, $vehicleCostCenter, $item] = $this->operationalContext();
        $this->approvalWorkflow('PURCHASE_REQUEST', $user->operationalProfile->pool_id, 2);
        $transactionUuid = (string) Str::uuid();

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_WebTxnId" Type="Edm.String" /><Property Name="U_UserWebSAP" Type="Edm.String" /><Property Name="U_VPRIOR" Type="Edm.String" /><Property Name="U_KMAKHIR" Type="Edm.Int32" /><Property Name="RequesterEmail" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseRequests" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/PurchaseRequests' => Http::response(['DocEntry' => 91, 'DocNum' => 260000091]),
            'https://sap.test/b1s/v1/SQLQueries*' => Http::response(['value' => [[
                'RefDate' => '2026-10-01',
                'FPK' => 'FPK-HISTORY',
                'ItemCode' => 'I05358',
                'Description' => 'Baterai GS NS 70',
                'WhsCode' => 'DMI-01',
                'WhsName' => 'Gudang Dumai',
                'Nopol' => 'L8568UZ',
                'Quantity' => 1,
                'UnitPrice' => 425000,
                'Amount' => 425000,
                'DocEntry' => 601,
                'DocNum' => 260000601,
                'Transaction' => 'Goods Issue',
                'TransId' => 601,
            ]]]),
        ]);

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => $transactionUuid,
            'vehicle_cost_center_id' => $vehicleCostCenter->id,
            'required_date' => '2026-10-21',
            'priority' => 'URGENT',
            'ending_km' => 125000,
            'notes' => 'U/ L8568UZ - POOL KENDARI',
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => 2,
                'free_text' => 'U/ L8568UZ, MREK GS',
            ]],
        ]);

        $response->assertRedirect(route('purchase-requests.index'));
        $response->assertSessionHasNoErrors();
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/PurchaseRequests');
        $document = SapDocument::query()->with('approval.steps')->where('transaction_uuid', $transactionUuid)->firstOrFail();
        $this->assertSame('PENDING_POST', $document->integration_status->value);
        $this->assertSame('I05358', data_get($document->normalized_data, 'lines.0.item_code'));
        $this->assertSame('Baterai GS NS 70', data_get($document->normalized_data, 'lines.0.item_name'));
        $this->assertSame('PCS', data_get($document->normalized_data, 'lines.0.inventory_uom'));
        $this->assertSame('FOUND', data_get($document->normalized_data, 'lines.0.usage_history.status'));
        $this->assertSame('2026-10-01', data_get($document->normalized_data, 'lines.0.usage_history.last_goods_issue_date'));
        $this->assertSame(20, data_get($document->normalized_data, 'lines.0.usage_history.days_in_use'));
        $this->assertSame(425000, data_get($document->normalized_data, 'lines.0.usage_history.expense_amount'));
        $steps = $document->approval->steps()->orderBy('step_order')->get();
        config()->set('sap.company_db', 'SECOND_DB');
        $this->post($steps[0]->approvalUrl(), ['decision' => 'APPROVE'])->assertRedirect();
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/PurchaseRequests');
        $finalApprovalResponse = $this->post($steps[1]->approvalUrl(), ['decision' => 'APPROVE']);
        $finalApprovalResponse->assertRedirect()->assertSessionMissing('warning')->assertSessionHasNoErrors();
        Http::assertSent(function (Request $request) use ($transactionUuid, $user): bool {
            if ($request->url() !== 'https://sap.test/b1s/v2/PurchaseRequests') {
                return false;
            }

            $line = $request['DocumentLines'][0];

            return $request['DocDate'] === '2026-10-21'
                && $request['DocDueDate'] === '2026-10-21'
                && $request['RequriedDate'] === '2026-10-21'
                && $request['Comments'] === 'U/ L8568UZ - POOL KENDARI'
                && $request['U_WebTxnId'] === $transactionUuid
                && $request['U_UserWebSAP'] === $user->name
                && $request['U_VPRIOR'] === 'URGENT'
                && $request['U_KMAKHIR'] === 125000
                && $request['RequesterEmail'] === $user->email
                && ! isset($request['U_Nopol'], $request['U_Pool'], $request['U_Department'])
                && $line['ItemCode'] === 'I05358'
                && (float) $line['Quantity'] === 2.0
                && $line['WarehouseCode'] === 'DMI-01'
                && $line['RequiredDate'] === '2026-10-21'
                && $line['CostingCode'] === 'TSP'
                && $line['CostingCode2'] === 'DUMAI'
                && $line['CostingCode3'] === 'L8568UZ'
                && $line['FreeText'] === 'U/ L8568UZ, MREK GS'
                && ! isset($line['UnitPrice']);
        });
        $this->assertDatabaseHas('sap_commands', [
            'transaction_uuid' => $transactionUuid,
            'status' => 'IN_SYNC',
            'sap_doc_entry' => 91,
        ]);
        $this->assertDatabaseHas('sap_api_logs', [
            'company_db' => 'TEST_DB',
            'method' => 'POST',
            'endpoint' => '/PurchaseRequests',
            'result' => 'SUCCESS',
        ]);
        $this->assertDatabaseHas('sap_documents', [
            'transaction_uuid' => $transactionUuid,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_doc_num' => '260000091',
        ]);
        $document->refresh();
        $this->assertDatabaseHas('sap_watchers', [
            'sap_document_id' => $document->id,
            'watch_status' => 'ACTIVE',
            'consecutive_failures' => 0,
        ]);
        $this->assertSame(
            'WAITING_QUOTATION',
            $document->normalized_data['progress_status'],
        );
    }

    public function test_purchase_request_maps_pool_name_to_the_company_specific_sap_cost_center_code(): void
    {
        [$user, $vehicleCostCenter, $item] = $this->operationalContext('DMI');
        $this->approvalWorkflow('PURCHASE_REQUEST', $user->operationalProfile->pool_id);
        $transactionUuid = (string) Str::uuid();

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_UserWebSAP" Type="Edm.String" /><Property Name="U_VPRIOR" Type="Edm.String" /><Property Name="RequesterEmail" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseRequests" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/PurchaseRequests' => Http::response(['DocEntry' => 92, 'DocNum' => 260000092]),
            'https://sap.test/b1s/v1/SQLQueries*' => Http::response(['value' => []]),
        ]);

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => $transactionUuid,
            'vehicle_cost_center_id' => $vehicleCostCenter->id,
            'required_date' => '2026-10-21',
            'priority' => 'NORMAL',
            'ending_km' => 125000,
            'lines' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertSessionHasNoErrors();
        $document = SapDocument::query()->with('approval.steps')->where('transaction_uuid', $transactionUuid)->firstOrFail();
        $this->post($document->approval->steps()->firstOrFail()->approvalUrl(), ['decision' => 'APPROVE'])->assertRedirect();
        Http::assertSent(function (Request $request) use ($user): bool {
            return $request->url() === 'https://sap.test/b1s/v2/PurchaseRequests'
                && $request['DocumentLines'][0]['CostingCode2'] === 'DMI'
                && $request['U_VPRIOR'] === 'NORMAL'
                && $request['U_UserWebSAP'] === $user->name
                && $request['RequesterEmail'] === $user->email
                && ! isset($request['U_WebTxnId']);
        });
    }

    public function test_purchase_request_rejects_missing_required_sap_fields_before_calling_sap(): void
    {
        [$user] = $this->operationalContext();
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors([
            'vehicle_cost_center_id',
            'required_date',
            'priority',
            'ending_km',
            'lines',
        ]);
        Http::assertNothingSent();
    }

    public function test_purchase_request_rejects_a_nopol_cost_center_from_another_company(): void
    {
        [$user, , $item] = $this->operationalContext();
        $otherCompanyCostCenter = SapCostCenterCache::query()->create([
            'company_db' => 'SECOND_DB',
            'center_code' => 'BK9999XX',
            'center_name' => 'BK9999XX',
            'dimension' => 3,
            'is_active' => true,
        ]);
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'vehicle_cost_center_id' => $otherCompanyCostCenter->id,
            'required_date' => '2026-10-21',
            'priority' => 'NORMAL',
            'ending_km' => 125000,
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => 1,
            ]],
        ]);

        $response->assertSessionHasErrors('vehicle_cost_center_id');
        Http::assertNothingSent();
    }

    public function test_purchase_request_rejects_an_unknown_priority_before_calling_sap(): void
    {
        [$user, $vehicleCostCenter, $item] = $this->operationalContext();
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'vehicle_cost_center_id' => $vehicleCostCenter->id,
            'required_date' => '2026-10-21',
            'priority' => 'CRITICAL',
            'ending_km' => 125000,
            'lines' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response->assertSessionHasErrors('priority');
        Http::assertNothingSent();
    }

    /** @return array{User, SapCostCenterCache, SapItemCache} */
    private function operationalContext(string $sapPoolCode = 'DUMAI'): array
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
            'center_code' => $sapPoolCode,
            'center_name' => 'Dumai',
            'dimension' => 2,
            'is_active' => true,
        ]);
        $vehicleCostCenter = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'L8568UZ',
            'center_name' => 'L8568UZ',
            'dimension' => 3,
            'is_active' => true,
        ]);
        $item = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I05358',
            'item_name' => 'Baterai GS NS 70',
            'inventory_uom' => 'PCS',
            'is_inventory_item' => false,
            'is_purchase_item' => true,
            'is_active' => true,
        ]);

        return [$user, $vehicleCostCenter, $item];
    }

    /** @return array<int, User> */
    private function approvalWorkflow(string $documentType, int $poolId, int $steps = 1): array
    {
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $poolId,
            'document_type' => $documentType,
            'name' => 'Workflow Test',
            'is_active' => true,
        ]);
        $approvers = [];

        foreach (range(1, $steps) as $order) {
            $approver = User::factory()->create();
            $workflow->steps()->create([
                'approver_id' => $approver->id,
                'step_order' => $order,
                'notification_email' => $approver->email,
            ]);
            $approvers[] = $approver;
        }

        return $approvers;
    }
}
