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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('BK8815FG', data_get($goodsReceipt->normalized_data, 'lines.0.vehicle_code'));
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

    public function test_goods_receipt_is_rejected_when_sap_already_has_a_grpo_for_the_purchase_order(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(hasExistingGoodsReceipt: true);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('lines');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $this->assertDatabaseCount('sap_commands', 0);
    }

    #[DataProvider('purchaseOrderAuthorizationStatusesHeldBackFromReceipt')]
    public function test_goods_receipt_is_not_posted_when_the_live_po_authorization_status_is_not_allowed(?string $authorizationStatus, string $expectedReason): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(authorizationStatus: $authorizationStatus);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ]);

        $response->assertRedirect(route('ready-to-receive.show', $purchaseOrder))->assertSessionHasErrors('lines');
        $this->assertStringContainsString($expectedReason, $response->baseResponse->getSession()->get('errors')->getBag('default')->first('lines'));
        $this->assertNoGoodsReceiptWasPosted();
        $this->assertDatabaseCount('sap_commands', 0);
        $this->assertDatabaseCount('sap_document_relations', 0);
    }

    /** @return array<string, array{?string, string}> */
    public static function purchaseOrderAuthorizationStatusesHeldBackFromReceipt(): array
    {
        return [
            'without approval' => ['dasWithout', 'PO tanpa proses approval di SAP (dasWithout)'],
            'generated by authorizer' => ['dasGeneratedbyAuthorizer', 'Generated by Authorizer'],
            'pending' => ['dasPending', 'masih menunggu approval'],
            'rejected' => ['dasRejected', 'ditolak di SAP'],
            'cancelled' => ['dasCancelled', 'dibatalkan di SAP'],
            'unknown value' => ['dasSomethingNew', 'tidak dikenal portal'],
            'missing value' => [null, 'tidak terbaca dari SAP'],
        ];
    }

    #[DataProvider('purchaseOrderAuthorizationStatusesAllowedForReceipt')]
    public function test_goods_receipt_is_posted_for_the_allowed_po_authorization_statuses(string $authorizationStatus): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(authorizationStatus: $authorizationStatus);

        $this->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, collect(Http::recorded())->filter(
            fn (array $exchange): bool => $exchange[0]->method() === 'POST'
                && $exchange[0]->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes',
        ));
    }

    /** @return array<string, array{string}> */
    public static function purchaseOrderAuthorizationStatusesAllowedForReceipt(): array
    {
        return [
            'generated (WddStatus P)' => ['dasGenerated'],
            'approved (WddStatus Y)' => ['dasApproved'],
        ];
    }

    public function test_goods_receipt_is_not_posted_when_the_live_po_cannot_be_read(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(purchaseOrderFails: true);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('sap');

        $this->assertNoGoodsReceiptWasPosted();
        $this->assertDatabaseCount('sap_commands', 0);
    }

    public function test_serial_managed_tire_posts_one_complete_serial_record_per_received_unit(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(serialItem: true);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [[
                    'line_num' => 0,
                    'quantity' => 2,
                    'serial_numbers' => [
                        [
                            'manufacturer_serial_number' => 'MFR-BAN-001',
                            'internal_serial_number' => 'BAN-001',
                            'lot_number' => 'LOT-01',
                            'expiry_date' => '2031-10-28',
                            'manufacture_date' => '2026-10-01',
                            'reception_date' => '2026-10-28',
                            'location' => 'Rak Ban A',
                            'notes' => 'Ban depan',
                        ],
                        [
                            'manufacturer_serial_number' => 'MFR-BAN-002',
                            'internal_serial_number' => 'BAN-002',
                            'lot_number' => 'LOT-01',
                        ],
                    ],
                ]],
            ]);

        $response->assertSessionHasNoErrors();
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://sap.test/b1s/v2/PurchaseDeliveryNotes') {
                return false;
            }

            return $request['DocumentLines'][0]['SerialNumbers'] === [
                [
                    'Quantity' => 1,
                    'ManufacturerSerialNumber' => 'MFR-BAN-001',
                    'InternalSerialNumber' => 'BAN-001',
                    'BatchID' => 'LOT-01',
                    'ExpiryDate' => '2031-10-28',
                    'ManufactureDate' => '2026-10-01',
                    'ReceptionDate' => '2026-10-28',
                    'Location' => 'Rak Ban A',
                    'Notes' => 'Ban depan',
                ],
                [
                    'Quantity' => 1,
                    'ManufacturerSerialNumber' => 'MFR-BAN-002',
                    'InternalSerialNumber' => 'BAN-002',
                    'BatchID' => 'LOT-01',
                ],
            ];
        });
        $goodsReceipt = SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->sole();
        $this->assertSame('internal_serial_number', data_get($goodsReceipt->normalized_data, 'lines.0.serial_unique_field'));
        $this->assertSame('BAN-001', data_get($goodsReceipt->normalized_data, 'lines.0.serial_numbers.0.internal_serial_number'));
        $this->assertNull(data_get($goodsReceipt->normalized_data, 'lines.0.serial_numbers.1.expiry_date'));
    }

    public function test_serial_managed_tire_is_not_posted_when_serial_numbers_are_missing(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(serialItem: true);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('lines.0.serial_numbers');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $this->assertDatabaseCount('sap_commands', 0);
    }

    public function test_non_serial_tire_is_posted_by_quantity_without_serial_numbers(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(nonSerialTire: true);

        $this->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes'
            && (float) $request['DocumentLines'][0]['Quantity'] === 2.0
            && ! array_key_exists('SerialNumbers', $request['DocumentLines'][0]));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/CompanyService_GetAdminInfo'));
        $goodsReceipt = SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->sole();
        $this->assertSame('BAN', data_get($goodsReceipt->normalized_data, 'lines.0.item_group_name'));
        $this->assertFalse(data_get($goodsReceipt->normalized_data, 'lines.0.manages_serial_numbers'));
        $this->assertSame([], data_get($goodsReceipt->normalized_data, 'lines.0.serial_numbers'));
        $this->assertNull(data_get($goodsReceipt->normalized_data, 'lines.0.serial_unique_field'));
        $this->assertDatabaseHas('sap_commands', ['command_type' => 'CREATE_GOODS_RECEIPT_PO', 'status' => 'IN_SYNC']);
    }

    public function test_non_serial_tire_rejects_serial_numbers_that_sap_does_not_manage(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(nonSerialTire: true);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [[
                    'line_num' => 0,
                    'quantity' => 2,
                    'serial_numbers' => [['internal_serial_number' => 'BAN-001'], ['internal_serial_number' => 'BAN-002']],
                ]],
            ])
            ->assertSessionHasErrors('lines.0.serial_numbers');

        $this->assertNoGoodsReceiptWasPosted();
    }

    /** @param array<string, mixed> $itemMasterOverrides */
    #[DataProvider('unsupportedTireSerialConfigurations')]
    public function test_tire_with_serial_configuration_not_decided_by_business_is_not_posted(array $itemMasterOverrides): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(nonSerialTire: true, itemMasterOverrides: $itemMasterOverrides);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('lines.0.serial_numbers');

        $this->assertNoGoodsReceiptWasPosted();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unsupportedTireSerialConfigurations(): array
    {
        return [
            'serial managed on release only' => [['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_OnReleaseOnly']],
            'serial flag unreadable' => [['ManageSerialNumbers' => null]],
            'serial managed without method' => [['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => null]],
            'serial managed with an unknown method' => [['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_FutureMethod']],
        ];
    }

    public function test_release_only_serial_method_is_not_posted_when_the_item_group_cannot_be_read(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(
            nonSerialTire: true,
            itemMasterOverrides: ['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_OnReleaseOnly'],
            groupName: '',
        );

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('lines.0.serial_numbers');

        $this->assertNoGoodsReceiptWasPosted();
    }

    public function test_item_with_an_unknown_serial_method_is_not_posted_even_outside_the_tire_group(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(itemMasterOverrides: ['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_FutureMethod']);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('lines.0.serial_numbers');

        $this->assertNoGoodsReceiptWasPosted();
    }

    public function test_non_tire_item_with_release_only_serial_method_keeps_the_existing_no_serial_receipt(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(itemMasterOverrides: ['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_OnReleaseOnly']);

        $this->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes'
            && ! array_key_exists('SerialNumbers', $request['DocumentLines'][0]));
    }

    public function test_item_with_unreadable_serial_flag_is_not_posted_even_outside_the_tire_group(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(itemMasterOverrides: ['ManageSerialNumbers' => null]);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('lines.0.serial_numbers');

        $this->assertNoGoodsReceiptWasPosted();
    }

    public function test_tire_is_not_posted_when_item_master_cannot_be_read(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(nonSerialTire: true, itemMasterMissing: true);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('lines.0.line_num');

        $this->assertNoGoodsReceiptWasPosted();
    }

    public function test_tire_is_not_posted_when_item_master_request_fails(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(nonSerialTire: true, itemMasterFails: true);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('sap');

        $this->assertNoGoodsReceiptWasPosted();
    }

    public function test_serial_managed_tire_requires_a_whole_quantity(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(serialItem: true);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 1.5, 'serial_numbers' => [['internal_serial_number' => 'BAN-001']]]],
            ])
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertNoGoodsReceiptWasPosted();
    }

    public function test_serial_managed_tire_requires_one_serial_per_received_unit(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(serialItem: true);

        $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [['line_num' => 0, 'quantity' => 2, 'serial_numbers' => [['internal_serial_number' => 'BAN-001']]]],
            ])
            ->assertSessionHasErrors('lines.0.serial_numbers');

        $this->assertNoGoodsReceiptWasPosted();
    }

    public function test_serial_grpo_can_continue_when_unique_serial_configuration_is_temporarily_unavailable(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(serialItem: true, serialConfigFails: true);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [[
                    'line_num' => 0,
                    'quantity' => 2,
                    'serial_numbers' => [
                        ['internal_serial_number' => 'BAN-FALLBACK-001'],
                        ['internal_serial_number' => 'BAN-FALLBACK-002'],
                    ],
                ]],
            ]);

        $response->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $goodsReceipt = SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->sole();
        $this->assertSame('none', data_get($goodsReceipt->normalized_data, 'lines.0.serial_unique_field'));
    }

    public function test_goods_receipt_rejects_serial_number_longer_than_sap_limit(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        Http::preventStrayRequests();

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [[
                    'line_num' => 0,
                    'quantity' => 1,
                    'serial_numbers' => [[
                        'internal_serial_number' => str_repeat('A', 33),
                    ]],
                ]],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('lines.0.serial_numbers.0.internal_serial_number');
        Http::assertNothingSent();
    }

    public function test_goods_receipt_requires_the_company_unique_serial_field(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(serialItem: true);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [[
                    'line_num' => 0,
                    'quantity' => 2,
                    'serial_numbers' => [
                        ['manufacturer_serial_number' => 'MFR-001'],
                        ['manufacturer_serial_number' => 'MFR-002'],
                    ],
                ]],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('lines.0.serial_numbers.0.internal_serial_number');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $this->assertDatabaseCount('sap_commands', 0);
    }

    public function test_goods_receipt_rejects_duplicate_company_unique_serial_numbers(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap(serialItem: true);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), [
                'receipt_date' => '2026-10-28',
                'lines' => [[
                    'line_num' => 0,
                    'quantity' => 2,
                    'serial_numbers' => [
                        ['internal_serial_number' => 'BAN-001'],
                        ['internal_serial_number' => 'ban-001'],
                    ],
                ]],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('lines.0.serial_numbers');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $this->assertDatabaseCount('sap_commands', 0);
    }

    private function assertNoGoodsReceiptWasPosted(): void
    {
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sap.test/b1s/v2/PurchaseDeliveryNotes');
        $this->assertDatabaseCount('sap_commands', 0);
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
                'warehouse_code' => 'DMI-01',
                'progress_status' => 'READY_FOR_GRPO',
                'vendor_name' => 'Sekawan, CV',
                'lines' => [[
                    'LineNum' => 0,
                    'ItemCode' => 'I02042',
                    'ItemDescription' => 'Baterai Yuasa NS 70',
                    'Quantity' => 2,
                    'RemainingOpenQuantity' => 2,
                    'WarehouseCode' => 'DMI-01',
                    'CostingCode3' => 'BK8815FG',
                ]],
            ],
        ]);

        return [$user, $purchaseOrder];
    }

    /** @param array<string, mixed> $itemMasterOverrides */
    private function fakeSap(
        bool $hasExistingGoodsReceipt = false,
        bool $serialItem = false,
        bool $serialConfigFails = false,
        bool $nonSerialTire = false,
        bool $itemMasterMissing = false,
        bool $itemMasterFails = false,
        array $itemMasterOverrides = [],
        ?string $groupName = null,
        ?string $authorizationStatus = 'dasGenerated',
        bool $purchaseOrderFails = false,
    ): void {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($hasExistingGoodsReceipt, $serialItem, $serialConfigFails, $nonSerialTire, $itemMasterMissing, $itemMasterFails, $itemMasterOverrides, $groupName, $authorizationStatus, $purchaseOrderFails) {
            $isTire = $serialItem || $nonSerialTire;
            $itemCode = $serialItem ? 'I00084' : ($nonSerialTire ? 'I00090' : 'I02042');
            $itemName = $serialItem ? 'Ban Baru Bridgestone' : ($nonSerialTire ? 'Selendang Ban' : 'Baterai Yuasa NS 70');
            $url = urldecode($request->url());

            if (str_ends_with($url, '/Login')) {
                return Http::response(['SessionId' => 'session-1']);
            }

            if (str_ends_with($url, '/$metadata')) {
                return Http::response('<Schema><EntityType Name="Document"><Property Name="U_UserWebSAP" Type="Edm.String" /></EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseDeliveryNotes" /></EntityContainer></Schema>');
            }

            if (str_ends_with($url, '/PurchaseOrders(500)')) {
                if ($purchaseOrderFails) {
                    return Http::response(['error' => ['message' => ['value' => 'Service Layer unavailable']]], 503);
                }

                return Http::response([
                    'DocEntry' => 500,
                    'DocNum' => 260001614,
                    'CardCode' => 'S00682',
                    'CardName' => 'Sekawan, CV',
                    'Cancelled' => 'tNO',
                    'DocumentStatus' => 'bost_Open',
                    'AuthorizationStatus' => $authorizationStatus,
                    'DocumentLines' => [[
                        'LineNum' => 0,
                        'LineStatus' => 'bost_Open',
                        'ItemCode' => $itemCode,
                        'ItemDescription' => $itemName,
                        'Quantity' => 2,
                        'RemainingOpenQuantity' => 2,
                        'WarehouseCode' => 'DMI-01',
                        'CostingCode3' => 'BK8815FG',
                    ]],
                ]);
            }

            if (str_contains($url, '/Items?')) {
                if ($itemMasterFails) {
                    return Http::response(['error' => ['message' => ['value' => 'Items unavailable']]], 503);
                }

                if ($itemMasterMissing) {
                    return Http::response(['value' => []]);
                }

                return Http::response(['value' => [[
                    'ItemCode' => $itemCode,
                    'ItemName' => $itemName,
                    'InventoryUOM' => 'PCS',
                    'ItemsGroupCode' => $isTire ? 200 : 100,
                    'InventoryItem' => 'tYES',
                    'PurchaseItem' => 'tYES',
                    'ManageSerialNumbers' => $serialItem ? 'tYES' : 'tNO',
                    'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction',
                    'Frozen' => 'tNO',
                    ...$itemMasterOverrides,
                ]]]);
            }

            if (str_contains($url, '/ItemGroups?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['value' => (int) ($query['$skip'] ?? 0) === 0
                    ? [['Number' => $isTire ? 200 : 100, 'GroupName' => $groupName ?? ($isTire ? 'BAN' : 'SPAREPART')]]
                    : []]);
            }

            if (in_array($request->method(), ['GET', 'POST'], true) && str_ends_with($url, '/CompanyService_GetAdminInfo')) {
                if ($serialConfigFails) {
                    return Http::response(['error' => ['message' => ['value' => 'AdminInfo unavailable']]], 503);
                }

                return Http::response(['AdminInfo' => ['UniqueSerialNo' => 'usn_SerialNumber']]);
            }

            if (str_contains($url, '$crossjoin(PurchaseDeliveryNotes,PurchaseDeliveryNotes/DocumentLines)')) {
                return Http::response(['value' => $hasExistingGoodsReceipt ? [[
                    'PurchaseDeliveryNotes' => [
                        'DocEntry' => 501,
                        'DocNum' => 260003001,
                        'Cancelled' => 'tNO',
                    ],
                    'PurchaseDeliveryNotes/DocumentLines' => [
                        'BaseType' => 22,
                        'BaseEntry' => 500,
                        'BaseLine' => 0,
                    ],
                ]] : []]);
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/PurchaseDeliveryNotes')) {
                return Http::response([
                    'DocEntry' => 501,
                    'DocNum' => 260003001,
                ], 201);
            }

            return Http::response([], 404);
        });
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
            'normalized_data' => ['progress_status' => 'READY_FOR_GRPO', 'warehouse_code' => 'DMI-01'],
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
