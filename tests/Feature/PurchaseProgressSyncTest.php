<?php

namespace Tests\Feature;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\WatchStatus;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\SapDocumentRelation;
use App\Models\SapWatcher;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurchaseProgressSyncTest extends TestCase
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
        config()->set('sap.object_types.purchase_request', 1470000113);
        config()->set('sap.object_types.purchase_quotation', 540000006);
        config()->set('sap.watchers.interval_seconds', 120);
    }

    public function test_command_discovers_purchase_quotation_and_advances_progress(): void
    {
        $this->travelTo('2026-10-22 10:15:00');
        [, $purchaseRequest] = $this->operationalContext('WAITING_QUOTATION');

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'session-progress']);
            }

            if (str_contains($request->url(), '$crossjoin(PurchaseQuotations')) {
                return Http::response(['value' => [[
                    'PurchaseQuotations' => [
                        'DocEntry' => 501,
                        'DocNum' => 26000501,
                        'DocDate' => '2026-10-22',
                        'DocDueDate' => '2026-10-29',
                        'DocumentStatus' => 'bost_Open',
                        'Cancelled' => 'tNO',
                        'CardCode' => 'V0001',
                        'CardName' => 'Vendor Test',
                    ],
                    'PurchaseQuotations/DocumentLines' => [
                        'DocEntry' => 501,
                        'LineNum' => 0,
                        'BaseType' => 1470000113,
                        'BaseEntry' => 1426,
                        'BaseLine' => 0,
                        'ItemCode' => 'I05358',
                        'Quantity' => 2,
                    ],
                ]]]);
            }

            if (str_contains($request->url(), '$crossjoin(PurchaseOrders')) {
                return Http::response(['value' => []]);
            }

            return Http::response([], 404);
        });

        $this->artisan('sap:purchase-progress:sync', [
            '--company-db' => 'TEST_DB',
            '--doc-entry' => 1426,
        ])->assertSuccessful();

        $purchaseRequest->refresh();
        $purchaseQuotation = SapDocument::query()
            ->where('company_db', 'TEST_DB')
            ->where('sap_entity', 'PurchaseQuotations')
            ->where('sap_doc_entry', 501)
            ->firstOrFail();
        $this->assertSame('WAITING_PO', $purchaseRequest->normalized_data['progress_status']);
        $this->assertSame('26000501', $purchaseRequest->normalized_data['purchase_quotation_doc_num']);
        $this->assertSame('PURCHASE_QUOTATION', $purchaseQuotation->document_type);
        $this->assertSame('26000501', $purchaseQuotation->sap_doc_num);
        $this->assertDatabaseHas('sap_document_relations', [
            'parent_document_id' => $purchaseRequest->id,
            'child_document_id' => $purchaseQuotation->id,
            'relation_type' => 'PURCHASE_QUOTATION',
            'discovered_by' => 'SERVICE_LAYER_BASE_DOCUMENT',
        ]);
        $this->assertDatabaseHas('sap_watchers', [
            'sap_document_id' => $purchaseRequest->id,
            'watch_status' => WatchStatus::Active->value,
            'consecutive_failures' => 0,
        ]);
        Http::assertSent(function (ClientRequest $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '$crossjoin(PurchaseQuotations,PurchaseQuotations/DocumentLines)')
                && str_contains($url, 'BaseType eq 1470000113')
                && str_contains($url, 'BaseEntry eq 1426');
        });
        Http::assertSent(function (ClientRequest $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '$crossjoin(PurchaseOrders,PurchaseOrders/DocumentLines)')
                && str_contains($url, 'BaseType eq 540000006')
                && str_contains($url, 'BaseEntry eq 501');
        });
    }

    public function test_command_discovers_purchase_order_from_purchase_quotation_and_advances_progress(): void
    {
        $this->travelTo('2026-10-22 10:20:00');
        [$user, $purchaseRequest] = $this->operationalContext('WAITING_QUOTATION');

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'session-progress']);
            }

            if (str_contains($request->url(), '$crossjoin(PurchaseQuotations')) {
                return Http::response(['value' => [[
                    'PurchaseQuotations' => [
                        'DocEntry' => 501,
                        'DocNum' => 26000501,
                        'DocumentStatus' => 'O',
                        'Cancelled' => 'N',
                    ],
                    'PurchaseQuotations/DocumentLines' => [
                        'DocEntry' => 501,
                        'LineNum' => 0,
                        'BaseType' => 1470000113,
                        'BaseEntry' => 1426,
                        'BaseLine' => 0,
                        'ItemCode' => 'I05358',
                        'Quantity' => 2,
                    ],
                ]]]);
            }

            if (str_contains($request->url(), '$crossjoin(PurchaseOrders')) {
                return Http::response(['value' => [[
                    'PurchaseOrders' => [
                        'DocEntry' => 701,
                        'DocNum' => 26000701,
                        'DocDate' => '2026-10-22',
                        'DocDueDate' => '2026-10-29',
                        'DocumentStatus' => 'O',
                        'Cancelled' => 'N',
                        'CardCode' => 'V0001',
                        'CardName' => 'Vendor Test',
                    ],
                    'PurchaseOrders/DocumentLines' => [
                        'DocEntry' => 701,
                        'LineNum' => 0,
                        'BaseType' => 540000006,
                        'BaseEntry' => 501,
                        'BaseLine' => 0,
                        'ItemCode' => 'I05358',
                        'Quantity' => 2,
                    ],
                ]]]);
            }

            return Http::response([], 404);
        });

        $this->artisan('sap:purchase-progress:sync', [
            '--company-db' => 'TEST_DB',
            '--doc-entry' => 1426,
        ])->assertSuccessful();

        $purchaseRequest->refresh();
        $purchaseQuotation = SapDocument::query()
            ->where('company_db', 'TEST_DB')
            ->where('sap_entity', 'PurchaseQuotations')
            ->where('sap_doc_entry', 501)
            ->firstOrFail();
        $purchaseOrder = SapDocument::query()
            ->where('company_db', 'TEST_DB')
            ->where('sap_entity', 'PurchaseOrders')
            ->where('sap_doc_entry', 701)
            ->firstOrFail();
        $this->assertSame('READY_FOR_GRPO', $purchaseRequest->normalized_data['progress_status']);
        $this->assertSame('26000701', $purchaseRequest->normalized_data['purchase_order_doc_num']);
        $this->assertSame('PURCHASE_ORDER', $purchaseOrder->document_type);
        $this->assertDatabaseHas('sap_document_relations', [
            'parent_document_id' => $purchaseQuotation->id,
            'child_document_id' => $purchaseOrder->id,
            'relation_type' => 'PURCHASE_ORDER',
            'discovered_by' => 'SERVICE_LAYER_BASE_DOCUMENT',
        ]);
        $this->assertDatabaseHas('sap_watchers', [
            'sap_document_id' => $purchaseRequest->id,
            'watch_status' => WatchStatus::Stopped->value,
            'consecutive_failures' => 0,
        ]);
        Http::assertSent(function (ClientRequest $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '$crossjoin(PurchaseOrders,PurchaseOrders/DocumentLines)')
                && str_contains($url, 'BaseType eq 540000006')
                && str_contains($url, 'BaseEntry eq 501');
        });
        $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-progress.index'))
            ->assertOk()
            ->assertSee('data-progress-step="po" data-progress-state="done"', false)
            ->assertSee('data-progress-step="grpo" data-progress-state="current"', false)
            ->assertDontSee('Sync SAP');
    }

    public function test_command_keeps_watcher_active_when_purchase_quotation_is_not_found(): void
    {
        $this->travelTo('2026-10-22 10:15:00');
        [, $purchaseRequest] = $this->operationalContext('WAITING_PO');

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-progress']),
            'https://sap.test/b1s/v2/*' => Http::response(['value' => []]),
        ]);

        $this->artisan('sap:purchase-progress:sync', [
            '--company-db' => 'TEST_DB',
            '--doc-entry' => 1426,
        ])->assertSuccessful();

        $purchaseRequest->refresh();
        $watcher = $purchaseRequest->watcher()->firstOrFail();
        $this->assertSame('WAITING_QUOTATION', $purchaseRequest->normalized_data['progress_status']);
        $this->assertSame(WatchStatus::Active, $watcher->watch_status);
        $this->assertSame('2026-10-22 10:17:00', $watcher->next_poll_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $watcher->consecutive_failures);
        $this->assertDatabaseCount('sap_document_relations', 0);
    }

    public function test_scheduled_sync_reactivates_legacy_watcher_and_checks_for_purchase_order(): void
    {
        $this->travelTo('2026-10-22 10:25:00');
        [, $purchaseRequest] = $this->operationalContext('WAITING_PO');
        $purchaseQuotation = SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $purchaseRequest->pool_id,
            'department_id' => $purchaseRequest->department_id,
            'document_type' => 'PURCHASE_QUOTATION',
            'sap_entity' => 'PurchaseQuotations',
            'sap_doc_entry' => 501,
            'sap_doc_num' => '26000501',
            'source' => 'SAP',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => false,
        ]);
        SapDocumentRelation::query()->create([
            'parent_document_id' => $purchaseRequest->id,
            'child_document_id' => $purchaseQuotation->id,
            'relation_type' => 'PURCHASE_QUOTATION',
            'discovered_by' => 'SERVICE_LAYER_BASE_DOCUMENT',
        ]);
        SapWatcher::query()->create([
            'sap_document_id' => $purchaseRequest->id,
            'watch_status' => WatchStatus::Stopped,
            'last_polled_at' => now()->subMinute(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-progress']),
            'https://sap.test/b1s/v2/*' => Http::response(['value' => []]),
        ]);

        $this->artisan('sap:purchase-progress:sync', ['--company-db' => 'TEST_DB'])
            ->assertSuccessful();

        $watcher = $purchaseRequest->watcher()->firstOrFail();
        $this->assertSame(WatchStatus::Active, $watcher->watch_status);
        $this->assertSame('2026-10-22 10:27:00', $watcher->next_poll_at->format('Y-m-d H:i:s'));
        Http::assertSent(function (ClientRequest $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '$crossjoin(PurchaseOrders,PurchaseOrders/DocumentLines)')
                && str_contains($url, 'BaseEntry eq 501');
        });
    }

    public function test_user_can_sync_purchase_request_from_the_progress_page(): void
    {
        [$user, $purchaseRequest] = $this->operationalContext('WAITING_QUOTATION');

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-progress']),
            'https://sap.test/b1s/v2/*' => Http::response(['value' => []]),
        ]);

        $response = $this->actingAs($user)->post(route('purchase-progress.sync', $purchaseRequest));

        $response->assertRedirect(route('purchase-progress.index'))
            ->assertSessionHas('status', 'Sinkronisasi selesai. Purchase Quotation yang mereferensikan PR ini belum ditemukan di SAP.');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '$crossjoin'));
    }

    public function test_user_cannot_sync_purchase_request_from_another_pool(): void
    {
        [$user] = $this->operationalContext('WAITING_QUOTATION');
        [, $otherPurchaseRequest] = $this->operationalContext('WAITING_QUOTATION', 'MEDAN');
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('purchase-progress.sync', $otherPurchaseRequest));

        $response->assertForbidden();
        Http::assertNothingSent();
    }

    /** @return array{User, SapDocument} */
    private function operationalContext(string $progressStatus, string $poolCode = 'DUMAI'): array
    {
        $pool = ControlPool::query()->create([
            'code' => $poolCode,
            'name' => str($poolCode)->title(),
            'is_active' => true,
        ]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.strtolower($poolCode),
            'code' => 'TSP',
            'name' => 'Transport',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => $poolCode === 'DUMAI' ? 'DMI-01' : 'MDN-01',
        ]);
        $purchaseRequest = SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_entry' => $poolCode === 'DUMAI' ? 1426 : 2426,
            'sap_doc_num' => $poolCode === 'DUMAI' ? '260001426' : '260002426',
            'source' => 'WEB',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => true,
            'normalized_data' => ['progress_status' => $progressStatus],
        ]);

        return [$user, $purchaseRequest];
    }
}
