<?php

namespace Tests\Feature;

use App\Integration\Sap\Reports\SparepartExpenseGateway;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SparepartReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
        config()->set('cache.default', 'array');
    }

    public function test_report_combines_sap_sources_and_scopes_the_nopol_filter(): void
    {
        [$user, $vehicle] = $this->operationalContext();

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'report-session']);
            }

            if (str_contains($request->url(), "SQLQueries('WEB_SPARE_GI')/List")) {
                return Http::response(['error' => [
                    'code' => '200',
                    'message' => ['value' => "Invalid entityset 'SQLQueries'."],
                ]], 400);
            }

            if (str_contains(urldecode($request->url()), 'crossjoin(InventoryGenExits')) {
                return Http::response(['value' => [[
                    'InventoryGenExits' => $this->odataDocument(601),
                    'InventoryGenExits/DocumentLines' => $this->odataLine(350000),
                ]]]);
            }

            if (str_contains(urldecode($request->url()), 'crossjoin(PurchaseInvoices')) {
                return Http::response(['value' => [[
                    'PurchaseInvoices' => [
                        ...$this->odataDocument(1801),
                        'CardCode' => 'V0001',
                        'CardName' => 'PT Sparepart',
                    ],
                    'PurchaseInvoices/DocumentLines' => $this->odataLine(425000),
                ]]]);
            }

            if (str_contains($request->url(), '/UDO_PAY_REQ')) {
                return Http::response(['value' => [[
                    'DocEntry' => 901,
                    'DocNum' => 260000901,
                    'Canceled' => 'N',
                    'CreateDate' => '2026-10-20',
                    'U_STATUS' => 'GENERATED',
                    'U_BPCODE' => 'V0002',
                    'U_BPNAME' => 'Bengkel',
                    'VIT_PAYMENTREQDCollection' => [[
                        'U_FPK' => 'FPK-001',
                        'U_REMARK' => 'Jasa pasang ban',
                        'U_MESIN' => 'DMI-01',
                        'U_DEPT' => 'BK8815FG',
                        'U_ACCOUNTCODE' => '5114.5001',
                        'U_PAYMENTAMT' => 125000,
                    ]],
                ]]]);
            }

            if (str_contains($request->url(), '/InventoryGenExits?')) {
                return Http::response(['value' => [[
                    'DocEntry' => 601,
                    'DocumentLines' => [[
                        'ItemCode' => 'I05358',
                        'SerialNumbers' => [['InternalSerialNumber' => 'BAN-0001']],
                    ]],
                ]]]);
            }

            return Http::response(['error' => ['code' => -1, 'message' => ['value' => 'Unexpected request']]], 500);
        });

        $response = $this->withoutVite()->actingAs($user)->get(route('sparepart-report.index', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-10-25',
            'nopol' => $vehicle->center_code,
        ]));

        $response
            ->assertOk()
            ->assertSee('Report Beban Sparepart')
            ->assertSee('Goods Issue')
            ->assertSee('A/P Invoice')
            ->assertSee('Payment Request')
            ->assertSee('BAN-0001')
            ->assertSee('900.000');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(urldecode($request->url()), 'crossjoin(PurchaseInvoices')
            && str_contains(urldecode($request->url()), "CostingCode3 eq 'BK8815FG'"));
    }

    public function test_missing_sap_query_is_registered_without_a_hardcoded_company_schema(): void
    {
        $listAttempts = 0;

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use (&$listAttempts) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'report-session']);
            }

            if (str_contains($request->url(), "SQLQueries('WEB_SPARE_AP')/List")) {
                $listAttempts++;

                return $listAttempts === 1
                    ? Http::response(['error' => ['code' => '-2028', 'message' => ['value' => 'No matching records found']]], 404)
                    : Http::response(['value' => []]);
            }

            if ($request->url() === 'https://sap.test/b1s/v1/SQLQueries') {
                return Http::response(['SqlCode' => 'WEB_SPARE_AP'], 201);
            }

            return Http::response(['error' => ['code' => -1, 'message' => ['value' => 'Unexpected request']]], 500);
        });

        $result = $this->app->make(SparepartExpenseGateway::class)->search(
            dateFrom: '2026-01-01',
            dateTo: '2026-10-25',
            itemCode: 'I05358',
            sources: ['ap_invoice'],
            withSerials: false,
        );

        $this->assertSame([], $result['rows']);
        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== 'https://sap.test/b1s/v1/SQLQueries') {
                return false;
            }

            $sql = (string) $request['SqlText'];

            return $request['SqlCode'] === 'WEB_SPARE_AP'
                && ! str_contains($sql, 'IA.')
                && str_contains($sql, ':itemCode')
                && str_contains($sql, ':nopol');
        });
    }

    public function test_purchase_request_history_returns_latest_goods_issue_for_every_selected_item_and_vehicle(): void
    {
        [$user, $vehicle, $item] = $this->operationalContext();
        $secondItem = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00078',
            'item_name' => 'Ban 245/70 R16',
            'inventory_uom' => 'PCS',
            'is_purchase_item' => true,
            'is_active' => true,
        ]);
        $this->travelTo('2026-10-27 09:00:00');

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'history-session']);
            }

            if (str_contains($request->url(), "SQLQueries('WEB_SPARE_GI')/List")) {
                return Http::response(['value' => [
                    $this->sapRow('Goods Issue', 601, 'I05358', 425000),
                    $this->sapRow('Goods Issue', 602, 'I00078', 900000),
                ]]);
            }

            return Http::response(['value' => []]);
        });

        $response = $this->actingAs($user)->getJson(route('purchase-requests.sparepart-history', [
            'item_ids' => [$item->id, $secondItem->id],
            'vehicle_cost_center_id' => $vehicle->id,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('vehicle.code', 'BK8815FG')
            ->assertJsonCount(2, 'histories')
            ->assertJsonPath('histories.0.item_code', 'I05358')
            ->assertJsonPath('histories.0.last_goods_issue_date', '2026-10-20')
            ->assertJsonPath('histories.0.expense_amount', 425000)
            ->assertJsonPath('histories.0.days_in_use', 7)
            ->assertJsonPath('histories.1.item_code', 'I00078')
            ->assertJsonPath('histories.1.expense_amount', 900000);
    }

    public function test_purchase_request_history_rejects_item_from_another_company_without_calling_sap(): void
    {
        [$user] = $this->operationalContext();
        $otherCompanyItem = SapItemCache::query()->create([
            'company_db' => 'SECOND_DB',
            'item_code' => 'OTHER-ITEM',
            'item_name' => 'Other Item',
            'is_purchase_item' => true,
            'is_active' => true,
        ]);
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->getJson(route('purchase-requests.sparepart-history', [
            'item_ids' => [$otherCompanyItem->id],
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('item_ids.0');
        Http::assertNothingSent();
    }

    /** @return array{User, SapCostCenterCache, SapItemCache} */
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
        $vehicle = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'BK8815FG',
            'center_name' => 'BK 8815 FG',
            'dimension' => 3,
            'is_active' => true,
        ]);
        $item = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I05358',
            'item_name' => 'Baterai GS NS 70',
            'inventory_uom' => 'PCS',
            'is_purchase_item' => true,
            'is_active' => true,
        ]);

        return [$user, $vehicle, $item];
    }

    /** @return array<string, int|float|string> */
    private function sapRow(string $transaction, int $documentEntry, string $itemCode, float $amount): array
    {
        return [
            'RefDate' => '2026-10-20',
            'FPK' => 'FPK-001',
            'ItemCode' => $itemCode,
            'Description' => 'Baterai GS NS 70',
            'WhsCode' => 'DMI-01',
            'WhsName' => 'Gudang Dumai',
            'Nopol' => 'BK8815FG',
            'Quantity' => 1,
            'UnitPrice' => $amount,
            'Amount' => $amount,
            'DocEntry' => $documentEntry,
            'DocNum' => 260000000 + $documentEntry,
            'Transaction' => $transaction,
            'TransId' => $documentEntry,
            'KmStart' => 120000,
            'KmEnd' => 125000,
        ];
    }

    /** @return array<string, int|string> */
    private function odataDocument(int $documentEntry): array
    {
        return [
            'DocEntry' => $documentEntry,
            'DocNum' => 260000000 + $documentEntry,
            'DocDate' => '2026-10-20',
            'Cancelled' => 'N',
            'Reference2' => 'FPK-001',
            'TransNum' => $documentEntry,
        ];
    }

    /** @return array<string, int|float|string> */
    private function odataLine(float $price): array
    {
        return [
            'LineNum' => 0,
            'ItemCode' => 'I05358',
            'ItemDescription' => 'Baterai GS NS 70',
            'Quantity' => 1,
            'WarehouseCode' => 'DMI-01',
            'CostingCode3' => 'BK8815FG',
            'AccountCode' => '5114.5001',
            'StockInmPrice' => $price,
            'UnitPrice' => $price,
            'LineTotal' => $price,
            'U_KM0' => 120000,
            'U_KM1' => 125000,
        ];
    }
}
