<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ApprovalWorkflow;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCommand;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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
        config()->set('sap.purchase_request.current_km_field', 'U_KM1');
        config()->set('sap.fields.web_created_by_user', 'U_UserWebSAP');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_purchase_request_form_shows_master_and_manual_uom_columns(): void
    {
        [$user] = $this->operationalContext();

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-requests.create'))
            ->assertOk()
            ->assertSee('UoM Master')
            ->assertSee('UoM Manual')
            ->assertSee('Tanpa Nopol')
            ->assertSee('name="lines[0][manual_uom]"', false)
            ->assertSee('name="lines[0][ending_km]"', false)
            ->assertDontSee('name="ending_km"', false);
    }

    public function test_purchase_request_posts_only_the_sap_fields_used_by_the_actual_process(): void
    {
        [$user, $vehicleCostCenter, $item] = $this->operationalContext();
        $this->approvalWorkflow('PURCHASE_REQUEST', $user, 2);
        $transactionUuid = (string) Str::uuid();

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_U_WebTxnId" Type="Edm.String" /><Property Name="U_UserWebSAP" Type="Edm.String" /><Property Name="U_VPRIOR" Type="Edm.String" /><Property Name="U_KMAKHIR" Type="Edm.Int32" /><Property Name="RequesterEmail" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseRequests" /></EntityContainer></Schema>'),
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
            'document_date' => '2026-10-21',
            'required_date' => '2026-10-21',
            'priority' => 'URGENT',
            'notes' => 'U/ L8568UZ - POOL KENDARI',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicleCostCenter->id,
                'quantity' => 2,
                'ending_km' => 125000,
                'manual_uom' => 'DUS',
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
        $this->assertSame('DUS', data_get($document->normalized_data, 'lines.0.manual_uom'));
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
                && $request['TaxDate'] === '2026-10-21'
                && $request['DocDueDate'] === '2026-10-21'
                && $request['RequriedDate'] === '2026-10-21'
                && $request['Comments'] === 'U/ L8568UZ - POOL KENDARI'
                && $request['U_U_WebTxnId'] === $transactionUuid
                && $request['U_UserWebSAP'] === $user->name
                && $request['U_VPRIOR'] === 'URGENT'
                && ! isset($request['U_KMAKHIR'])
                && $request['RequesterEmail'] === $user->email
                && ! isset($request['U_Nopol'], $request['U_Pool'], $request['U_Department'])
                && $line['ItemCode'] === 'I05358'
                && (float) $line['Quantity'] === 2.0
                && $line['WarehouseCode'] === 'DMI-01'
                && $line['RequiredDate'] === '2026-10-21'
                && $line['CostingCode'] === 'TSP'
                && $line['CostingCode2'] === 'DUMAI'
                && $line['CostingCode3'] === 'L8568UZ'
                && $line['U_KM1'] === 125000
                && $line['MeasureUnit'] === 'DUS'
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
        $this->approvalWorkflow('PURCHASE_REQUEST', $user);
        $transactionUuid = (string) Str::uuid();

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_UserWebSAP" Type="Edm.String" /><Property Name="U_VPRIOR" Type="Edm.String" /><Property Name="RequesterEmail" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseRequests" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/PurchaseRequests' => Http::response(['DocEntry' => 92, 'DocNum' => 260000092]),
            'https://sap.test/b1s/v1/SQLQueries*' => Http::response(['value' => []]),
        ]);
        Log::spy();

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => $transactionUuid,
            'document_date' => '2026-10-21',
            'required_date' => '2026-10-21',
            'priority' => 'NORMAL',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicleCostCenter->id,
                'quantity' => 1,
                'ending_km' => 125000,
            ]],
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
                && ! isset($request['U_U_WebTxnId'], $request['U_WebTxnId']);
        });
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'optional field')
            && $context['entity'] === 'PurchaseRequests'
            && $context['field'] === 'U_U_WebTxnId'
            && $context['business_name'] === 'web_transaction_id')->once();
    }

    public function test_purchase_request_rejects_missing_required_sap_fields_before_calling_sap(): void
    {
        [$user] = $this->operationalContext();
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors([
            'document_date',
            'required_date',
            'priority',
            'lines',
        ]);
        Http::assertNothingSent();
    }

    public function test_purchase_request_maps_optional_nopol_per_detail_line(): void
    {
        [$user, $firstVehicle, $item] = $this->operationalContext();
        $secondVehicle = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'BK9921XY',
            'center_name' => 'BK9921XY',
            'dimension' => 3,
            'is_active' => true,
        ]);
        $this->approvalWorkflow('PURCHASE_REQUEST', $user);
        $transactionUuid = (string) Str::uuid();

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseRequests" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/PurchaseRequests' => Http::response(['DocEntry' => 93, 'DocNum' => 260000093]),
            'https://sap.test/b1s/v1/SQLQueries*' => Http::response(['value' => []]),
        ]);

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => $transactionUuid,
            'document_date' => '2026-10-21',
            'required_date' => '2026-10-21',
            'priority' => 'NORMAL',
            'lines' => [
                ['item_id' => $item->id, 'vehicle_cost_center_id' => $firstVehicle->id, 'quantity' => 1, 'ending_km' => 125000],
                ['item_id' => $item->id, 'vehicle_cost_center_id' => $secondVehicle->id, 'quantity' => 1, 'ending_km' => 130000],
                ['item_id' => $item->id, 'quantity' => 1, 'ending_km' => 0],
            ],
        ]);

        $response->assertRedirect(route('purchase-requests.index'))->assertSessionHasNoErrors();
        $document = SapDocument::query()->with('approval.steps')->where('transaction_uuid', $transactionUuid)->firstOrFail();
        $this->assertSame('L8568UZ', data_get($document->normalized_data, 'lines.0.vehicle_cost_center.center_code'));
        $this->assertSame('BK9921XY', data_get($document->normalized_data, 'lines.1.vehicle_cost_center.center_code'));
        $this->assertNull(data_get($document->normalized_data, 'lines.2.vehicle_cost_center'));

        $this->post($document->approval->steps()->firstOrFail()->approvalUrl(), ['decision' => 'APPROVE'])->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://sap.test/b1s/v2/PurchaseRequests') {
                return false;
            }

            return $request['DocumentLines'][0]['CostingCode3'] === 'L8568UZ'
                && $request['DocumentLines'][1]['CostingCode3'] === 'BK9921XY'
                && ! isset($request['DocumentLines'][2]['CostingCode3']);
        });
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
            'document_date' => '2026-10-21',
            'required_date' => '2026-10-21',
            'priority' => 'NORMAL',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $otherCompanyCostCenter->id,
                'quantity' => 1,
                'ending_km' => 125000,
            ]],
        ]);

        $response->assertSessionHasErrors('lines.0.vehicle_cost_center_id');
        Http::assertNothingSent();
    }

    public function test_purchase_request_rejects_an_unknown_priority_before_calling_sap(): void
    {
        [$user, $vehicleCostCenter, $item] = $this->operationalContext();
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'document_date' => '2026-10-21',
            'required_date' => '2026-10-21',
            'priority' => 'CRITICAL',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicleCostCenter->id,
                'quantity' => 1,
                'ending_km' => 125000,
            ]],
        ]);

        $response->assertSessionHasErrors('priority');
        Http::assertNothingSent();
    }

    public function test_purchase_request_form_asks_for_the_pr_date_and_allows_any_past_date(): void
    {
        [$user] = $this->operationalContext();

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-requests.create'))
            ->assertOk()
            ->assertSee('name="document_date" type="date" max="2026-10-21" value="2026-10-21"', false)
            ->assertSee('name="required_date" type="date" value="2026-10-21"', false)
            ->assertSee('Boleh tanggal mundur; tidak boleh melewati hari ini.');
    }

    #[DataProvider('backdatedPurchaseRequests')]
    public function test_a_backdated_purchase_request_posts_the_chosen_date_as_both_sap_dates_even_when_approved_days_later(string $companyDb, string $documentDate, string $requiredDate): void
    {
        config()->set('sap.company_db', $companyDb);
        config()->set('sap.company_databases', [$companyDb]);
        [$user, $vehicleCostCenter, $item] = $this->operationalContext(companyDb: $companyDb);
        $this->approvalWorkflow('PURCHASE_REQUEST', $user, companyDb: $companyDb);
        $this->fakeSapPurchaseRequestPost(Http::response(['DocEntry' => 93, 'DocNum' => 260000093]));

        $this->submitPurchaseRequest($user, $vehicleCostCenter, $item, $documentDate, $requiredDate)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('purchase-requests.index'));

        $document = SapDocument::query()->with('approval.steps')->sole();
        $this->assertSame($companyDb, $document->company_db);
        $this->assertSame($documentDate, $document->normalized_data['document_date']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/PurchaseRequests');

        Carbon::setTestNow('2026-10-28 08:00:00');
        // The e-mailed link lasts 48 hours; a week later the approver uses a link that was sent again.
        $step = $document->approval->steps()->firstOrFail();
        $step->update(['expires_at' => now()->addHours(48)]);
        $this->withoutVite()->get($step->approvalUrl())
            ->assertOk()
            ->assertSee('data-approval-document-date>'.Carbon::parse($documentDate)->format('d/m/Y').'<', false);
        $this->post($step->approvalUrl(), ['decision' => 'APPROVE'])
            ->assertRedirect()
            ->assertSessionMissing('warning');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/PurchaseRequests'
            && $request['DocDate'] === $documentDate
            && $request['TaxDate'] === $documentDate
            && $request['DocDueDate'] === $requiredDate
            && $request['RequriedDate'] === $requiredDate
            && $request['DocumentLines'][0]['RequiredDate'] === $requiredDate);
        $document->refresh();
        $this->assertSame('IN_SYNC', $document->integration_status->value);
        $this->assertSame($documentDate, $document->normalized_data['document_date']);
        $this->assertSame($documentDate, SapCommand::query()->sole()->request_payload['DocDate']);
    }

    /** @return array<string, array{string, string, string}> company database, PR date, required date */
    public static function backdatedPurchaseRequests(): array
    {
        return [
            'IA, yesterday' => ['IA', '2026-10-20', '2026-10-21'],
            'IA, some months ago' => ['IA', '2026-06-15', '2026-06-20'],
            'IA, the previous year' => ['IA', '2025-01-01', '2025-01-01'],
            'IAL_DEV, yesterday' => ['IAL_DEV', '2026-10-20', '2026-10-20'],
            'IAL_DEV, the previous year' => ['IAL_DEV', '2025-01-01', '2026-10-21'],
        ];
    }

    public function test_purchase_request_rejects_a_pr_date_in_the_future_and_a_required_date_before_the_pr_date(): void
    {
        [$user, $vehicleCostCenter, $item] = $this->operationalContext();
        Http::preventStrayRequests();

        $this->submitPurchaseRequest($user, $vehicleCostCenter, $item, '2026-10-22', '2026-10-22')
            ->assertSessionHasErrors(['document_date' => 'Tanggal PR tidak boleh melewati hari ini.']);
        $this->submitPurchaseRequest($user, $vehicleCostCenter, $item, '2026-06-15', '2026-06-14')
            ->assertSessionHasErrors(['required_date' => 'Tanggal kebutuhan tidak boleh lebih awal dari tanggal PR.']);

        $this->assertSame(0, SapDocument::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_sap_rejection_of_a_backdated_purchase_request_is_explained_and_a_later_resend_keeps_the_date(): void
    {
        [$user, $vehicleCostCenter, $item] = $this->operationalContext();
        $this->approvalWorkflow('PURCHASE_REQUEST', $user);
        $this->fakeSapPurchaseRequestPost(Http::sequence()
            ->push(['error' => ['message' => ['value' => 'Fake SAP rejection: posting period is closed']]], 400)
            ->push(['error' => ['message' => ['value' => 'Fake SAP rejection: posting period is closed']]], 400)
            ->push(['DocEntry' => 94, 'DocNum' => 260000094], 201));
        $this->submitPurchaseRequest($user, $vehicleCostCenter, $item, '2025-10-20', '2025-10-25')->assertSessionHasNoErrors();
        $document = SapDocument::query()->with('approval.steps')->sole();
        Carbon::setTestNow('2026-10-23 08:00:00');

        $this->post($document->approval->steps()->firstOrFail()->approvalUrl(), ['decision' => 'APPROVE'])
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'SAP menolak Purchase Request')
                && str_contains($message, 'Periode posting SAP untuk tanggal transaksi ini tertutup, terkunci, atau belum tersedia.')
                && str_contains($message, 'posting period is closed')
                && str_contains($message, 'Tanggal PR tidak diubah'));

        $command = SapCommand::query()->sole();
        $this->assertSame('SAP_ERROR', $command->status->value);
        $this->assertSame('SAP_ERROR', $document->refresh()->integration_status->value);
        $this->assertSame('2025-10-20', $command->request_payload['DocDate']);
        $this->assertSame('2025-10-20', $document->normalized_data['document_date']);
        $this->assertStringContainsString('posting period is closed', $command->last_error);

        $this->artisan('sap:post-approved')->assertSuccessful();
        $this->assertCount(1, $this->purchaseRequestPosts(), 'A SAP rejection is never retried by the scheduler.');

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        UserOperationalProfile::query()->create([
            'user_id' => $admin->id,
            'pool_id' => $user->operationalProfile->pool_id,
            'department_id' => $user->operationalProfile->department_id,
            'warehouse_code' => 'DMI-01',
        ]);
        Carbon::setTestNow('2026-10-25 08:00:00');
        $this->from(route('documents.show', $document))->actingAs($admin)
            ->post(route('documents.post-approved', $document))
            ->assertSessionHasErrors('sap');
        $this->assertStringContainsString('posting period is closed', session('errors')->first('sap'));
        $this->assertStringContainsString('Tanggal transaksi tidak diubah', session('errors')->first('sap'));
        $this->actingAs($admin)->post(route('documents.post-approved', $document))->assertSessionHasNoErrors();

        $posts = $this->purchaseRequestPosts();
        $this->assertCount(3, $posts);
        $posts->each(function (array $exchange): void {
            $this->assertSame('2025-10-20', $exchange[0]['DocDate']);
            $this->assertSame('2025-10-20', $exchange[0]['TaxDate']);
            $this->assertSame('2025-10-25', $exchange[0]['DocDueDate']);
        });
        $this->assertSame('IN_SYNC', $document->refresh()->integration_status->value);
        $this->assertSame('2025-10-20', $document->normalized_data['document_date']);
    }

    private function submitPurchaseRequest(User $user, SapCostCenterCache $vehicleCostCenter, SapItemCache $item, string $documentDate, string $requiredDate): TestResponse
    {
        return $this->actingAs($user)->post(route('purchase-requests.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'document_date' => $documentDate,
            'required_date' => $requiredDate,
            'priority' => 'NORMAL',
            'lines' => [[
                'item_id' => $item->id,
                'vehicle_cost_center_id' => $vehicleCostCenter->id,
                'quantity' => 1,
                'ending_km' => 125000,
            ]],
        ]);
    }

    private function fakeSapPurchaseRequestPost(mixed $postResponse): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_U_WebTxnId" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseRequests" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/PurchaseRequests' => $postResponse,
            'https://sap.test/b1s/v1/SQLQueries*' => Http::response(['value' => []]),
        ]);
    }

    /** @return Collection<int, array{0: Request, 1: mixed}> */
    private function purchaseRequestPosts(): Collection
    {
        return collect(Http::recorded())
            ->filter(fn (array $exchange): bool => $exchange[0]->method() === 'POST'
                && $exchange[0]->url() === 'https://sap.test/b1s/v2/PurchaseRequests')
            ->values();
    }

    /** @return array{User, SapCostCenterCache, SapItemCache} */
    private function operationalContext(string $sapPoolCode = 'DUMAI', string $companyDb = 'TEST_DB'): array
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
            'company_db' => $companyDb,
            'center_code' => 'TSP',
            'center_name' => 'Transport',
            'dimension' => 1,
            'is_active' => true,
        ]);
        SapCostCenterCache::query()->create([
            'company_db' => $companyDb,
            'center_code' => $sapPoolCode,
            'center_name' => 'Dumai',
            'dimension' => 2,
            'is_active' => true,
        ]);
        $vehicleCostCenter = SapCostCenterCache::query()->create([
            'company_db' => $companyDb,
            'center_code' => 'L8568UZ',
            'center_name' => 'L8568UZ',
            'dimension' => 3,
            'is_active' => true,
        ]);
        $item = SapItemCache::query()->create([
            'company_db' => $companyDb,
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
    private function approvalWorkflow(string $documentType, User $submitter, int $steps = 1, string $companyDb = 'TEST_DB'): array
    {
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => $companyDb,
            'submitter_id' => $submitter->id,
            'pool_id' => $submitter->operationalProfile->pool_id,
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
