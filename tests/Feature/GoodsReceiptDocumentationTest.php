<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodsReceiptDocumentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
        config()->set('sap.ready_to_receive.series', 887);
        config()->set('sap.ready_to_receive.lookback_months', 2);
        config()->set('sap.ready_to_receive.sync_interval_seconds', 0);
        config()->set('sap.documents.goods_receipt_attachments.disk', 'local');
        Carbon::setTestNow('2026-11-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_can_upload_private_goods_receipt_photos_without_calling_sap(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);

        $response = $this->actingAs($user)->post(route('ready-to-receive.attachments.store', $purchaseOrder), [
            'images' => [$this->fakeImage('surat-jalan.png')],
            'caption' => 'Barang diterima lengkap',
        ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHas('status', '1 foto penerimaan berhasil disimpan di portal.');
        $attachment = SapDocumentAttachment::query()->sole();
        $this->assertSame($purchaseOrder->id, $attachment->sap_document_id);
        $this->assertSame($user->id, $attachment->uploaded_by);
        $this->assertSame('surat-jalan.png', $attachment->original_name);
        $this->assertSame('Barang diterima lengkap', $attachment->caption);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertModelExists(AuditLog::query()->where('event', 'goods_receipt_po.documentation_uploaded')->sole());
        Http::assertNothingSent();
    }

    public function test_list_imports_only_series_887_purchase_orders_for_user_warehouse_without_existing_grpo(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $previouslyCachedPurchaseOrder = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'sap_doc_entry' => 702,
            'sap_doc_num' => '260001702',
            'normalized_data' => [
                'series' => 887,
                'document_date' => '2026-10-21',
                'warehouse_code' => 'DMI-01',
                'vendor_name' => 'Vendor Already Received',
                'lines' => [],
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $url = urldecode($request->url());

            if (str_ends_with($url, '/Login')) {
                return Http::response(['SessionId' => 'ready-po-session']);
            }

            if (str_contains($url, '$crossjoin(PurchaseOrders,PurchaseOrders/DocumentLines)')) {
                return Http::response(['value' => [
                    [
                        'PurchaseOrders' => [
                            'DocEntry' => 701,
                            'DocNum' => 260001701,
                            'Series' => 887,
                            'DocDate' => '2026-10-20',
                            'DocumentStatus' => 'bost_Open',
                            'Cancelled' => 'tNO',
                            'CardCode' => 'V001',
                            'CardName' => 'Vendor Ready',
                        ],
                        'PurchaseOrders/DocumentLines' => [
                            'DocEntry' => 701,
                            'LineNum' => 0,
                            'LineStatus' => 'bost_Open',
                            'ItemCode' => 'I02042',
                            'ItemDescription' => 'Baterai Yuasa NS 70',
                            'Quantity' => 2,
                            'RemainingOpenQuantity' => 2,
                            'WarehouseCode' => 'DMI-01',
                            'CostingCode3' => 'BK8815FG',
                        ],
                    ],
                    [
                        'PurchaseOrders' => [
                            'DocEntry' => 702,
                            'DocNum' => 260001702,
                            'Series' => 887,
                            'DocDate' => '2026-10-21',
                            'DocumentStatus' => 'bost_Open',
                            'Cancelled' => 'tNO',
                            'CardName' => 'Vendor Already Received',
                        ],
                        'PurchaseOrders/DocumentLines' => [
                            'DocEntry' => 702,
                            'LineNum' => 0,
                            'LineStatus' => 'bost_Open',
                            'ItemCode' => 'I09999',
                            'Quantity' => 1,
                            'RemainingOpenQuantity' => 1,
                            'WarehouseCode' => 'DMI-01',
                        ],
                    ],
                ]]);
            }

            if (str_contains($url, '$crossjoin(PurchaseDeliveryNotes,PurchaseDeliveryNotes/DocumentLines)')) {
                return Http::response(['value' => [[
                    'PurchaseDeliveryNotes' => ['DocEntry' => 900, 'Cancelled' => 'tNO'],
                    'PurchaseDeliveryNotes/DocumentLines' => ['BaseType' => 22, 'BaseEntry' => 702],
                ]]]);
            }

            return Http::response([], 404);
        });

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('ready-to-receive.index'))
            ->assertOk()
            ->assertSee('260001701')
            ->assertSee('Vendor Ready')
            ->assertDontSee('BK8815FG')
            ->assertSee('DMI-01')
            ->assertDontSee('260001702')
            ->assertDontSee('Vendor Already Received')
            ->assertSee('Series 887')
            ->assertSee('Sinkronisasi terakhir: 1 PO memenuhi filter; 1 PO dikeluarkan karena sudah memiliki GRPO.')
            ->assertSee('Ready For Grpo');
        $readyPurchaseOrder = SapDocument::query()->where('sap_doc_num', '260001701')->firstOrFail();
        $this->assertSame('BK8815FG', data_get($readyPurchaseOrder->normalized_data, 'lines.0.vehicle_code'));
        $this->withoutVite()
            ->actingAs($user)
            ->get(route('ready-to-receive.show', $readyPurchaseOrder))
            ->assertOk()
            ->assertSee('BK8815FG');
        $this->assertDatabaseHas('sap_documents', [
            'company_db' => 'TEST_DB',
            'sap_entity' => 'PurchaseOrders',
            'sap_doc_entry' => 701,
            'sap_doc_num' => '260001701',
        ]);
        $this->assertDatabaseMissing('sap_documents', [
            'id' => $previouslyCachedPurchaseOrder->id,
            'sap_business_status' => 'OPEN',
        ]);
        $this->assertDatabaseHas('sap_documents', [
            'id' => $previouslyCachedPurchaseOrder->id,
            'sap_business_status' => 'CLOSED',
        ]);
        Http::assertSent(function (ClientRequest $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '$crossjoin(PurchaseOrders,PurchaseOrders/DocumentLines)')
                && str_contains($url, 'PurchaseOrders/Series eq 887')
                && str_contains($url, "PurchaseOrders/DocDate ge '2026-09-03'")
                && str_contains($url, "PurchaseOrders/DocumentLines/WarehouseCode eq 'DMI-01'")
                && str_contains($url, "PurchaseOrders/DocumentStatus eq 'bost_Open'");
        });
    }

    public function test_uploaded_photo_is_visible_only_through_authorized_private_route(): void
    {
        Storage::fake('local');
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);
        $this->actingAs($user)->post(route('ready-to-receive.attachments.store', $purchaseOrder), [
            'images' => [$this->fakeImage('barang.png')],
        ]);
        $attachment = SapDocumentAttachment::query()->sole();

        $response = $this->actingAs($user)->get(route('document-attachments.show', $attachment));

        $response
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=3600, private')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_serial_managed_tire_displays_sap_style_serial_setup_on_grpo_form(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);
        $purchaseOrder->update([
            'normalized_data' => array_replace_recursive($purchaseOrder->normalized_data, [
                'lines' => [[
                    'ItemCode' => 'I00084',
                    'ItemDescription' => 'Ban Baru Bridgestone',
                    'Quantity' => 2,
                    'RemainingOpenQuantity' => 2,
                    'WarehouseCode' => 'DMI-01',
                ]],
            ]),
        ]);
        SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00084',
            'item_name' => 'Ban Baru Bridgestone',
            'item_group_code' => null,
            'item_group_name' => null,
            'manages_serial_numbers' => false,
            'serial_management_method' => null,
            'is_active' => true,
            'source_payload' => ['ItemCode' => 'I00084'],
            'synced_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $url = urldecode($request->url());

            if (str_ends_with($url, '/Login')) {
                return Http::response(['SessionId' => 'serial-setup-session']);
            }

            if ($request->method() === 'GET' && str_contains($url, '/Items?')) {
                return Http::response(['value' => [[
                    'ItemCode' => 'I00084',
                    'ItemName' => 'Ban Baru Bridgestone',
                    'InventoryUOM' => 'PCS',
                    'ItemsGroupCode' => 200,
                    'InventoryItem' => 'tYES',
                    'PurchaseItem' => 'tYES',
                    'ManageSerialNumbers' => 'tYES',
                    'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction',
                    'Frozen' => 'tNO',
                ]]]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/ItemGroups?')) {
                return Http::response(['value' => [[
                    'Number' => 200,
                    'GroupName' => 'BAN',
                ]]]);
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/CompanyService_GetAdminInfo')) {
                return Http::response(['AdminInfo' => ['UniqueSerialNo' => 'usn_SerialNumber']]);
            }

            return Http::response([], 404);
        });

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('ready-to-receive.show', $purchaseOrder))
            ->assertOk()
            ->assertSee('Ban Baru Bridgestone')
            ->assertSee('Detail Item Master')
            ->assertSee('data-item-group="BAN"', false)
            ->assertSee('Serial Numbers — Setup')
            ->assertSee('Atur Serial')
            ->assertSee('Kolom unik SAP:')
            ->assertSee('data-serial-unique-field="internal_serial_number"', false)
            ->assertDontSee('Tidak dikelola serial')
            ->assertDontSee('name="lines[0][serial_numbers]"', false);
        $this->assertDatabaseHas('sap_items_cache', [
            'company_db' => 'TEST_DB',
            'item_code' => 'I00084',
            'item_group_code' => 200,
            'item_group_name' => 'BAN',
            'manages_serial_numbers' => true,
            'serial_management_method' => 'bomm_OnEveryTransaction',
        ]);
        Http::assertSent(function (ClientRequest $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/Items?')
                && str_contains($url, "ItemCode eq 'I00084'");
        });
        Http::assertSent(function (ClientRequest $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/ItemGroups?')
                && str_contains($url, 'Number eq 200');
        });
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/CompanyService_GetAdminInfo'));
    }

    public function test_grpo_form_does_not_claim_item_is_non_serial_when_metadata_refresh_fails(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);
        SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I05358',
            'item_name' => 'Baterai GS NS 70',
            'manages_serial_numbers' => false,
            'is_active' => true,
            'source_payload' => ['ItemCode' => 'I05358'],
            'synced_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'metadata-failure-session']);
            }

            return Http::response(['error' => ['message' => ['value' => 'SAP unavailable']]], 503);
        });

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('ready-to-receive.show', $purchaseOrder))
            ->assertOk()
            ->assertSee('Metadata serial item belum dapat diperbarui dari SAP')
            ->assertSee('Metadata belum tersedia')
            ->assertDontSee('Tidak dikelola serial');
    }

    public function test_upload_rejects_non_image_file(): void
    {
        Storage::fake('local');
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.attachments.store', $purchaseOrder), [
                'images' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('images.0');
        $this->assertSame(0, SapDocumentAttachment::query()->count());
    }

    public function test_goods_receipt_documentation_does_not_leak_other_company_or_pool(): void
    {
        [$user] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        [, $otherPool, $otherDepartment] = $this->operationalUser(UserRole::AdminOperational, 'MEDAN');
        $otherPoolPurchaseOrder = $this->purchaseOrder($otherPool, $otherDepartment);
        $otherCompanyPurchaseOrder = $this->purchaseOrder($otherPool, $otherDepartment, 'SECOND_DB');

        $this->withoutVite()->actingAs($user)
            ->get(route('ready-to-receive.show', $otherPoolPurchaseOrder))
            ->assertForbidden();
        $this->withoutVite()->actingAs($user)
            ->get(route('ready-to-receive.show', $otherCompanyPurchaseOrder))
            ->assertNotFound();
    }

    public function test_private_attachment_does_not_leak_other_company_or_pool(): void
    {
        [$user] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        [, $otherPool, $otherDepartment] = $this->operationalUser(UserRole::AdminOperational, 'MEDAN');
        $otherPoolAttachment = SapDocumentAttachment::factory()->create([
            'sap_document_id' => $this->purchaseOrder($otherPool, $otherDepartment)->id,
            'uploaded_by' => null,
        ]);
        $otherCompanyAttachment = SapDocumentAttachment::factory()->create([
            'sap_document_id' => $this->purchaseOrder($otherPool, $otherDepartment, 'SECOND_DB')->id,
            'uploaded_by' => null,
        ]);

        $this->actingAs($user)
            ->get(route('document-attachments.show', $otherPoolAttachment))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('document-attachments.show', $otherCompanyAttachment))
            ->assertNotFound();
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalUser(UserRole $role, string $poolCode): array
    {
        $pool = ControlPool::query()->create(['code' => $poolCode, 'name' => $poolCode, 'is_active' => true]);
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

    private function purchaseOrder(ControlPool $pool, ControlDepartment $department, string $companyDb = 'TEST_DB'): SapDocument
    {
        return SapDocument::factory()->create([
            'company_db' => $companyDb,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'sap_doc_entry' => null,
            'sap_doc_num' => '260002001',
            'normalized_data' => [
                'warehouse_code' => $pool->code === 'DUMAI' ? 'DMI-01' : 'MDN-01',
                'progress_status' => 'READY_FOR_GRPO',
                'vendor_name' => 'PT Sparepart',
                'lines' => [[
                    'ItemCode' => 'I05358',
                    'ItemDescription' => 'Baterai GS NS 70',
                    'Quantity' => 2,
                    'WarehouseCode' => 'DMI-01',
                    'vehicle_code' => 'BK8815FG',
                ]],
            ],
        ]);
    }

    private function fakeImage(string $name): UploadedFile
    {
        $onePixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $onePixelPng ?: '');
    }
}
