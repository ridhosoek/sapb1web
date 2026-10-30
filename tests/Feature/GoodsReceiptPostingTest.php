<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\SapDocumentRelation;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodsReceiptPostingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 10:00:00');
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
        config()->set('sap.object_types.purchase_order', 22);
        config()->set('sap.fields.web_created_by_user', 'U_UserWebSAP');
        config()->set('sap.documents.goods_receipt_attachments.disk', 'local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_goods_receipt_posts_po_based_lines_to_sap_and_keeps_photo_in_portal(): void
    {
        Storage::fake('local');
        [$user, $purchaseOrder] = $this->operationalContext();
        $purchaseRequest = $this->purchaseRequestChain($purchaseOrder);
        $this->fakeSap();

        $response = $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), [
            'receipt_date' => '2026-10-28',
            'lines' => [['line_num' => 0, 'quantity' => 2]],
            'images' => [$this->fakeImage('barang.png')],
            'caption' => 'Barang diterima lengkap',
        ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'GRPO SAP 260003001 berhasil dibuat. 1 foto disimpan privat di portal.');
        Http::assertSent(function (Request $request) use ($user): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://sap.test/b1s/v2/PurchaseDeliveryNotes') {
                return false;
            }

            return $request['CardCode'] === 'S00682'
                && $request['DocDate'] === '2026-10-28'
                && $request['DocDueDate'] === '2026-10-28'
                && $request['TaxDate'] === '2026-10-28'
                && $request['U_UserWebSAP'] === $user->name
                && $request['DocumentLines'][0]['BaseType'] === 22
                && $request['DocumentLines'][0]['BaseEntry'] === 500
                && $request['DocumentLines'][0]['BaseLine'] === 0
                && (float) $request['DocumentLines'][0]['Quantity'] === 2.0
                && $request['DocumentLines'][0]['WarehouseCode'] === 'DMI-01'
                && ! isset($request['images'], $request['caption']);
        });
        $goodsReceipt = SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->sole();
        $this->assertSame(501, $goodsReceipt->sap_doc_entry);
        $this->assertSame('260003001', $goodsReceipt->sap_doc_num);
        $this->assertDatabaseHas('sap_document_relations', [
            'parent_document_id' => $purchaseOrder->id,
            'child_document_id' => $goodsReceipt->id,
            'relation_type' => 'GOODS_RECEIPT_PO',
            'discovered_by' => 'WEB_POST',
        ]);
        $this->assertDatabaseHas('sap_commands', [
            'command_type' => 'CREATE_GOODS_RECEIPT_PO',
            'sap_entity' => 'PurchaseDeliveryNotes',
            'status' => 'IN_SYNC',
            'sap_doc_entry' => 501,
        ]);
        $attachment = SapDocumentAttachment::query()->sole();
        $this->assertSame($goodsReceipt->id, $attachment->sap_document_id);
        Storage::disk('local')->assertExists($attachment->path);
        $purchaseOrder->refresh();
        $this->assertSame('CLOSED', $purchaseOrder->sap_business_status->value);
        $this->assertSame('RECEIVED', data_get($purchaseOrder->normalized_data, 'progress_status'));
        $purchaseRequest->refresh();
        $this->assertSame('FULLY_RECEIVED', data_get($purchaseRequest->normalized_data, 'progress_status'));
        $this->assertSame('260003001', data_get($purchaseRequest->normalized_data, 'goods_receipt_doc_num'));
    }

    public function test_repeated_submission_returns_existing_grpo_without_posting_twice(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $payload = [
            'receipt_date' => '2026-10-28',
            'lines' => [['line_num' => 0, 'quantity' => 2]],
        ];

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $payload)->assertSessionHasNoErrors();

        $purchaseDeliveryPosts = collect(Http::recorded())
            ->filter(fn (array $exchange): bool => $exchange[0]->method() === 'POST'
                && $exchange[0]->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $this->assertCount(1, $purchaseDeliveryPosts);
        $this->assertDatabaseCount('sap_documents', 2);
        $this->assertDatabaseCount('sap_document_relations', 1);
    }

    public function test_goods_receipt_rejects_quantity_above_live_po_open_quantity(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 3]],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('lines.0.quantity');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $this->assertDatabaseCount('sap_commands', 0);
        $this->assertDatabaseCount('sap_document_relations', 0);
    }

    /** @return array{User, SapDocument} */
    private function operationalContext(): array
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'tsp-dumai',
            'code' => 'TSP',
            'name' => 'Transport',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => UserRole::AdminOperational]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);
        $purchaseOrder = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'sap_doc_entry' => 500,
            'sap_doc_num' => '260001614',
            'normalized_data' => [
                'progress_status' => 'READY_FOR_GRPO',
                'vendor_name' => 'Sekawan, CV',
                'lines' => [[
                    'LineNum' => 0,
                    'ItemCode' => 'I02042',
                    'ItemDescription' => 'Baterai Yuasa NS 70',
                    'Quantity' => 2,
                    'RemainingOpenQuantity' => 2,
                    'WarehouseCode' => 'DMI-01',
                ]],
            ],
        ]);

        return [$user, $purchaseOrder];
    }

    private function fakeSap(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-1']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityType Name="Document"><Property Name="U_UserWebSAP" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseDeliveryNotes" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/PurchaseOrders(500)' => Http::response([
                'DocEntry' => 500,
                'DocNum' => 260001614,
                'CardCode' => 'S00682',
                'CardName' => 'Sekawan, CV',
                'Cancelled' => 'tNO',
                'DocumentStatus' => 'bost_Open',
                'DocumentLines' => [[
                    'LineNum' => 0,
                    'LineStatus' => 'bost_Open',
                    'ItemCode' => 'I02042',
                    'ItemDescription' => 'Baterai Yuasa NS 70',
                    'Quantity' => 2,
                    'RemainingOpenQuantity' => 2,
                    'WarehouseCode' => 'DMI-01',
                ]],
            ]),
            'https://sap.test/b1s/v2/PurchaseDeliveryNotes' => Http::response([
                'DocEntry' => 501,
                'DocNum' => 260003001,
            ], 201),
        ]);
    }

    private function purchaseRequestChain(SapDocument $purchaseOrder): SapDocument
    {
        $purchaseRequest = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $purchaseOrder->pool_id,
            'department_id' => $purchaseOrder->department_id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_entry' => 300,
            'normalized_data' => ['progress_status' => 'READY_FOR_GRPO'],
        ]);
        $purchaseQuotation = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $purchaseOrder->pool_id,
            'department_id' => $purchaseOrder->department_id,
            'document_type' => 'PURCHASE_QUOTATION',
            'sap_entity' => 'PurchaseQuotations',
            'sap_doc_entry' => 400,
        ]);
        SapDocumentRelation::query()->create([
            'parent_document_id' => $purchaseRequest->id,
            'child_document_id' => $purchaseQuotation->id,
            'relation_type' => 'PURCHASE_QUOTATION',
        ]);
        SapDocumentRelation::query()->create([
            'parent_document_id' => $purchaseQuotation->id,
            'child_document_id' => $purchaseOrder->id,
            'relation_type' => 'PURCHASE_ORDER',
        ]);

        return $purchaseRequest;
    }

    private function fakeImage(string $name): UploadedFile
    {
        $onePixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $onePixelPng ?: '');
    }
}
