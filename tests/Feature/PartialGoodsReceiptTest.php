<?php

namespace Tests\Feature;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapDocumentRelation;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TD-05: several Goods Receipt POs per Purchase Order, a UUID per receipt request stored in the SAP UDF,
 * reconciliation of uncertain POSTs, and the guards around them. SAP is a stateful fake: these tests prove the
 * portal's own decisions, not SAP's behaviour, which still needs UAT.
 */
class PartialGoodsReceiptTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const POST_URL = 'https://sap.test/b1s/v2/PurchaseDeliveryNotes';

    private object $sap;

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
        config()->set('sap.fields.web_transaction_id', 'U_U_WebTxnId');
        config()->set('sap.fields.web_created_by_user', 'U_UserWebSAP');
        config()->set('sap.goods_receipt.partial_receipts_enabled', true);
        $this->sap = $this->newSapState();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_two_partial_receipts_on_the_same_po_keep_it_ready_until_the_open_quantity_is_gone(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $purchaseRequest = $this->purchaseRequestChain($purchaseOrder);
        $this->fakeSap();
        $firstUuid = (string) str()->uuid();
        $secondUuid = (string) str()->uuid();

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($firstUuid, 4))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'GRPO SAP 260003601 berhasil dibuat.');

        $purchaseOrder->refresh();
        $this->assertSame(BusinessStatus::Open, $purchaseOrder->sap_business_status);
        $this->assertSame('PARTIALLY_RECEIVED', data_get($purchaseOrder->normalized_data, 'progress_status'));
        $this->assertSame(6.0, (float) data_get($purchaseOrder->normalized_data, 'lines.0.open_quantity'));
        $this->assertSame('PARTIALLY_RECEIVED', data_get($purchaseRequest->refresh()->normalized_data, 'progress_status'));
        $this->assertSame('ACTIVE', $purchaseRequest->watcher()->first()->watch_status->value);
        $this->withoutVite()->actingAs($user)->get(route('ready-to-receive.index'))
            ->assertOk()
            ->assertSee('260001614');

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($secondUuid, 6))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'GRPO SAP 260003602 berhasil dibuat.');

        $purchaseOrder->refresh();
        $this->assertSame(BusinessStatus::Closed, $purchaseOrder->sap_business_status);
        $this->assertSame('RECEIVED', data_get($purchaseOrder->normalized_data, 'progress_status'));
        $this->assertTrue(data_get($purchaseOrder->normalized_data, 'fully_received'));
        $purchaseRequest->refresh();
        $this->assertSame('FULLY_RECEIVED', data_get($purchaseRequest->normalized_data, 'progress_status'));
        $this->assertSame(['260003601', '260003602'], data_get($purchaseRequest->normalized_data, 'goods_receipt_doc_nums'));
        $this->assertSame('STOPPED', $purchaseRequest->watcher()->first()->watch_status->value);
        $this->assertSame(2, SapDocumentRelation::query()->where('parent_document_id', $purchaseOrder->id)->where('relation_type', 'GOODS_RECEIPT_PO')->count());
        $this->assertSame([$firstUuid, $secondUuid], collect($this->postedPayloads())->pluck('U_U_WebTxnId')->all());
        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $firstUuid, 'status' => 'IN_SYNC', 'base_document_entry' => 500]);
        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $secondUuid, 'status' => 'IN_SYNC', 'base_document_entry' => 500]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'goods_receipt_po.posted']);
    }

    public function test_submitting_the_same_request_uuid_twice_posts_only_once(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $uuid = (string) str()->uuid();

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->postedPayloads());
        $this->assertSame(1, SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->count());
        $this->assertSame(1, SapCommand::query()->count());
        $this->assertSame(6.0, $this->sap->lines[0]['open']);
    }

    public function test_a_new_request_uuid_after_a_receipt_is_a_new_receipt(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 3))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 3))->assertSessionHasNoErrors();

        $this->assertCount(2, $this->postedPayloads());
        $this->assertSame(4.0, $this->sap->lines[0]['open']);
    }

    public function test_a_request_without_a_uuid_is_rejected_before_anything_is_sent(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();

        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), ['receipt_date' => '2026-10-28', 'lines' => [['line_num' => 0, 'quantity' => 4]]])
            ->assertSessionHasErrors('receipt_request_uuid');

        $this->assertSame([], $this->postedPayloads());
        $this->assertDatabaseCount('sap_commands', 0);
    }

    public function test_quantity_above_the_remaining_open_quantity_is_rejected_after_a_partial_receipt(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4))->assertSessionHasNoErrors();

        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 7))
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertCount(1, $this->postedPayloads());
        $this->assertDatabaseCount('sap_commands', 1);
    }

    public function test_a_fully_received_po_cannot_receive_again(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 10))->assertSessionHasNoErrors();

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 1));

        $response->assertSessionHasErrors('lines');
        $this->assertStringContainsString('sudah ditutup', $response->baseResponse->getSession()->get('errors')->getBag('default')->first('lines'));
        $this->assertCount(1, $this->postedPayloads());
        $this->withoutVite()->actingAs($user)->get(route('ready-to-receive.show', $purchaseOrder))
            ->assertOk()
            ->assertSee('data-receipt-closed', false)
            ->assertDontSee('name="receipt_request_uuid"', false);
    }

    public function test_approval_status_is_checked_again_for_every_receipt(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4))->assertSessionHasNoErrors();
        $this->sap->authorization = 'dasPending';

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 2));

        $response->assertSessionHasErrors('lines');
        $this->assertStringContainsString('masih menunggu approval', $response->baseResponse->getSession()->get('errors')->getBag('default')->first('lines'));
        $this->assertCount(1, $this->postedPayloads());
        $this->assertDatabaseCount('sap_commands', 1);
    }

    public function test_the_po_is_not_posted_when_the_live_po_cannot_be_read(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->purchaseOrderFails = true;

        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4));

        $this->assertSame([], $this->postedPayloads());
        $this->assertDatabaseCount('sap_commands', 0);
    }

    public function test_the_receipt_is_not_posted_when_the_transaction_udf_is_not_available_on_goods_receipts(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->udfInMetadata = false;

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4));

        $response->assertSessionHasErrors('sap');
        $this->assertStringContainsString('U_U_WebTxnId', $response->baseResponse->getSession()->get('errors')->getBag('default')->first('sap'));
        $this->assertSame([], $this->postedPayloads());
        $this->assertDatabaseCount('sap_commands', 0);
    }

    public function test_a_timeout_leaves_posting_unknown_and_blocks_every_further_receipt_for_that_po(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'timeout_not_stored';
        $firstUuid = (string) str()->uuid();

        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($firstUuid, 4))
            ->assertSessionHasErrors('sap');

        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $firstUuid, 'status' => 'POSTING_UNKNOWN', 'base_document_entry' => 500]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'goods_receipt_po.posting_unknown']);
        $this->assertSame(0, SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->count());

        $this->sap->postMode = 'ok';
        $blocked = $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4));
        $blocked->assertSessionHasErrors('lines');
        $this->assertStringContainsString('belum pasti', $blocked->baseResponse->getSession()->get('errors')->getBag('default')->first('lines'));

        $sameUuid = $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($firstUuid, 4));
        $sameUuid->assertSessionHasErrors('lines');
        $this->assertCount(1, $this->postedPayloads());
        $this->withoutVite()->actingAs($user)->get(route('ready-to-receive.show', $purchaseOrder))
            ->assertOk()
            ->assertSee('data-uncertain-receipts', false);
    }

    public function test_an_internal_server_error_is_treated_as_uncertain_and_not_as_a_rejection(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'server_error_stored';
        $uuid = (string) str()->uuid();

        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4))
            ->assertSessionHasErrors('sap');

        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'POSTING_UNKNOWN']);
        $this->assertDatabaseMissing('sap_commands', ['status' => 'SAP_ERROR']);
    }

    public function test_a_definite_sap_rejection_does_not_block_the_po_and_the_same_uuid_can_be_retried_after_a_lookup(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'reject';
        $uuid = (string) str()->uuid();

        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4))
            ->assertSessionHasErrors('sap');

        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'SAP_ERROR', 'attempts' => 1]);

        $this->sap->postMode = 'ok';
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'IN_SYNC', 'attempts' => 2]);
        $this->assertCount(2, $this->postedPayloads());
        $this->assertTrue($this->lookedUpByUuid($uuid), 'The retry must look for the UUID in SAP before posting again.');
        $this->assertSame(1, SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->count());
    }

    public function test_a_rejected_request_whose_uuid_already_exists_in_sap_is_reconciled_instead_of_posted_again(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $uuid = (string) str()->uuid();
        $this->sap->postMode = 'reject';
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4));
        $this->storeReceiptInSap($uuid, [0 => 4.0]);
        $this->sap->postMode = 'ok';

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->postedPayloads());
        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'IN_SYNC']);
        $this->assertSame(1, SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->count());
    }

    public function test_reconciliation_records_a_receipt_that_sap_stored_before_the_connection_dropped(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $purchaseRequest = $this->purchaseRequestChain($purchaseOrder);
        $this->fakeSap();
        $this->sap->postMode = 'timeout_stored';
        $uuid = (string) str()->uuid();
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4))
            ->assertSessionHasErrors('sap');
        $this->sap->postMode = 'ok';

        $this->artisan('sap:goods-receipts:reconcile', ['--company-db' => 'TEST_DB'])->assertSuccessful();

        $this->assertCount(1, $this->postedPayloads(), 'Reconciliation must never post.');
        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'IN_SYNC', 'sap_doc_entry' => 601]);
        $goodsReceipt = SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->sole();
        $this->assertSame($uuid, $goodsReceipt->transaction_uuid);
        $this->assertSame(4.0, (float) data_get($goodsReceipt->normalized_data, 'lines.0.quantity'));
        $this->assertDatabaseHas('sap_document_relations', ['parent_document_id' => $purchaseOrder->id, 'child_document_id' => $goodsReceipt->id, 'discovered_by' => 'RECONCILIATION']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'goods_receipt_po.reconciled']);
        $this->assertSame(6.0, (float) data_get($purchaseOrder->refresh()->normalized_data, 'lines.0.open_quantity'));
        $this->assertSame('PARTIALLY_RECEIVED', data_get($purchaseRequest->refresh()->normalized_data, 'progress_status'));

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 6))->assertSessionHasNoErrors();
        $this->assertCount(2, $this->postedPayloads());
    }

    public function test_an_empty_lookup_never_releases_an_uncertain_command_however_old_it_is(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'timeout_not_stored';
        $uuid = (string) str()->uuid();
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4));

        Carbon::setTestNow('2026-11-30 10:00:00');
        $this->artisan('sap:goods-receipts:reconcile', ['--company-db' => 'TEST_DB'])->assertSuccessful();
        $this->artisan('sap:goods-receipts:reconcile', ['--company-db' => 'TEST_DB'])->assertSuccessful();

        $this->assertTrue($this->lookedUpByUuid($uuid));
        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'POSTING_UNKNOWN']);
        $this->sap->postMode = 'ok';
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4))
            ->assertSessionHasErrors('lines');
        $this->assertCount(1, $this->postedPayloads());
    }

    public function test_a_failing_lookup_leaves_the_command_uncertain(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'timeout_stored';
        $uuid = (string) str()->uuid();
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4));
        $this->sap->lookupFails = true;

        $this->artisan('sap:goods-receipts:reconcile', ['--company-db' => 'TEST_DB'])->assertFailed();

        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'POSTING_UNKNOWN']);
        $this->assertSame(0, SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->count());
    }

    public function test_a_command_that_is_still_in_flight_is_not_reconciled_yet(): void
    {
        [, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $uuid = (string) str()->uuid();
        SapCommand::query()->create([
            'company_db' => 'TEST_DB',
            'transaction_uuid' => $uuid,
            'command_type' => 'CREATE_GOODS_RECEIPT_PO',
            'sap_entity' => 'PurchaseDeliveryNotes',
            'base_document_entry' => $purchaseOrder->sap_doc_entry,
            'status' => 'POSTING',
            'request_payload' => ['U_U_WebTxnId' => $uuid],
            'attempts' => 1,
            'last_attempt_at' => now()->subSeconds(10),
        ]);

        $this->artisan('sap:goods-receipts:reconcile', ['--company-db' => 'TEST_DB'])->assertSuccessful();

        $this->assertFalse($this->lookedUpByUuid($uuid));
    }

    public function test_only_a_super_admin_can_release_an_uncertain_command_and_only_with_evidence(): void
    {
        [$operator, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'timeout_not_stored';
        $uuid = (string) str()->uuid();
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($operator)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4));
        $command = SapCommand::query()->where('transaction_uuid', $uuid)->sole();
        $superAdmin = $this->superAdmin($purchaseOrder);

        $this->actingAs($operator)->post(route('admin.goods-receipt-commands.release', $command), ['evidence' => str_repeat('x', 30), 'confirm' => '1'])->assertForbidden();
        $this->actingAs($superAdmin)->from(route('admin.integration-monitor'))
            ->post(route('admin.goods-receipt-commands.release', $command), ['evidence' => 'sudah dicek', 'confirm' => '1'])
            ->assertSessionHasErrors('evidence');
        $this->actingAs($superAdmin)->from(route('admin.integration-monitor'))
            ->post(route('admin.goods-receipt-commands.release', $command), ['evidence' => str_repeat('x', 30)])
            ->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'POSTING_UNKNOWN']);
    }

    public function test_a_super_admin_release_is_audited_and_lets_a_new_request_through_with_live_validation(): void
    {
        [$operator, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'timeout_not_stored';
        $uuid = (string) str()->uuid();
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($operator)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4));
        $command = SapCommand::query()->where('transaction_uuid', $uuid)->sole();
        $superAdmin = $this->superAdmin($purchaseOrder);
        $evidence = 'Tim SAP (Budi) memeriksa PurchaseDeliveryNotes langsung di SAP IA: tidak ada GRPO untuk PO ini setelah 28 Okt.';

        $this->actingAs($superAdmin)->post(route('admin.goods-receipt-commands.release', $command), ['evidence' => $evidence, 'confirm' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $command->refresh();
        $this->assertSame('SAP_ERROR', $command->status->value);
        $this->assertStringContainsString('Dilepas Super Admin', (string) $command->last_error);
        $audit = AuditLog::query()->where('event', 'goods_receipt_po.recovery_released')->sole();
        $this->assertSame($superAdmin->id, $audit->actor_id);
        $this->assertSame($evidence, $audit->metadata['evidence']);
        $this->assertSame($uuid, $audit->metadata['request_uuid']);
        $this->assertSame('POSTING_UNKNOWN', $audit->metadata['previous_status']);

        $this->sap->postMode = 'ok';
        $this->sap->lines[0]['open'] = 3.0;
        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($operator)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4));
        $response->assertSessionHasErrors('lines.0.quantity');
        $this->actingAs($operator)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 3))->assertSessionHasNoErrors();
    }

    public function test_a_release_is_refused_when_sap_cannot_be_read_and_reconciles_when_the_receipt_exists(): void
    {
        [$operator, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->sap->postMode = 'timeout_stored';
        $uuid = (string) str()->uuid();
        $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($operator)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt($uuid, 4));
        $command = SapCommand::query()->where('transaction_uuid', $uuid)->sole();
        $superAdmin = $this->superAdmin($purchaseOrder);
        $payload = ['evidence' => str_repeat('bukti investigasi ', 3), 'confirm' => '1'];

        $this->sap->lookupFails = true;
        $this->actingAs($superAdmin)->from(route('admin.integration-monitor'))
            ->post(route('admin.goods-receipt-commands.release', $command), $payload)
            ->assertSessionHasErrors('command');
        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'POSTING_UNKNOWN']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'goods_receipt_po.recovery_released']);

        $this->sap->lookupFails = false;
        $this->actingAs($superAdmin)->post(route('admin.goods-receipt-commands.release', $command), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sap_commands', ['transaction_uuid' => $uuid, 'status' => 'IN_SYNC']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'goods_receipt_po.recovery_released']);
    }

    public function test_the_watcher_marks_a_cancelled_receipt_and_gives_the_quantity_back(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $purchaseRequest = $this->purchaseRequestChain($purchaseOrder);
        $this->fakeSap();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 10))->assertSessionHasNoErrors();
        $this->assertSame('FULLY_RECEIVED', data_get($purchaseRequest->refresh()->normalized_data, 'progress_status'));
        $goodsReceipt = SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->sole();

        $this->sap->receipts[601]['cancelled'] = true;
        $this->sap->lines[0]['open'] = 10.0;
        $purchaseRequest->watcher()->update(['watch_status' => 'ACTIVE', 'next_poll_at' => now()]);
        $this->artisan('sap:purchase-progress:sync', ['--company-db' => 'TEST_DB', '--doc-entry' => 300])->assertSuccessful();

        $this->assertSame(BusinessStatus::Cancelled, $goodsReceipt->refresh()->sap_business_status);
        $purchaseOrder->refresh();
        $this->assertSame(BusinessStatus::Open, $purchaseOrder->sap_business_status);
        $this->assertFalse(data_get($purchaseOrder->normalized_data, 'fully_received'));
        $this->assertSame(10.0, (float) data_get($purchaseOrder->normalized_data, 'lines.0.open_quantity'));
        $purchaseRequest->refresh();
        $this->assertSame('READY_FOR_GRPO', data_get($purchaseRequest->normalized_data, 'progress_status'));
        $this->assertEmpty(data_get($purchaseRequest->normalized_data, 'goods_receipt_doc_nums'));
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 10))->assertSessionHasNoErrors();
        $this->assertCount(2, $this->postedPayloads());
    }

    public function test_the_watcher_reports_partial_then_full_progress_and_does_not_overwrite_web_receipts(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $purchaseRequest = $this->purchaseRequestChain($purchaseOrder);
        $this->fakeSap();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4))->assertSessionHasNoErrors();
        $webReceipt = SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->sole();
        $purchaseRequest->watcher()->update(['watch_status' => 'ACTIVE', 'next_poll_at' => now()]);

        $this->artisan('sap:purchase-progress:sync', ['--company-db' => 'TEST_DB', '--doc-entry' => 300])->assertSuccessful();

        $this->assertSame('PARTIALLY_RECEIVED', data_get($purchaseRequest->refresh()->normalized_data, 'progress_status'));
        $this->assertSame('ACTIVE', $purchaseRequest->watcher()->first()->watch_status->value);
        $this->assertSame('WEB', $webReceipt->refresh()->source);
        $this->assertSame(1, SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->count());

        $this->storeReceiptInSap((string) str()->uuid(), [0 => 6.0]);
        $this->artisan('sap:purchase-progress:sync', ['--company-db' => 'TEST_DB', '--doc-entry' => 300])->assertSuccessful();

        $purchaseRequest->refresh();
        $this->assertSame('FULLY_RECEIVED', data_get($purchaseRequest->normalized_data, 'progress_status'));
        $this->assertSame(2, SapDocument::query()->where('document_type', 'GOODS_RECEIPT_PO')->count());
        $this->assertSame('STOPPED', $purchaseRequest->watcher()->first()->watch_status->value);
    }

    public function test_a_request_is_not_fully_received_while_another_po_of_the_request_is_still_open(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $purchaseRequest = $this->purchaseRequestChain($purchaseOrder);
        $quotation = SapDocument::query()->where('document_type', 'PURCHASE_QUOTATION')->sole();
        $secondPurchaseOrder = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'sap_doc_entry' => 777,
            'sap_doc_num' => '260001777',
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'progress_status' => 'READY_FOR_GRPO', 'lines' => []],
        ]);
        SapDocumentRelation::query()->create([
            'parent_document_id' => $quotation->id,
            'child_document_id' => $secondPurchaseOrder->id,
            'relation_type' => 'PURCHASE_ORDER',
        ]);
        $this->fakeSap();

        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 10))->assertSessionHasNoErrors();

        $this->assertSame('RECEIVED', data_get($purchaseOrder->refresh()->normalized_data, 'progress_status'));
        $this->assertSame('PARTIALLY_RECEIVED', data_get($purchaseRequest->refresh()->normalized_data, 'progress_status'));
        $this->assertSame('ACTIVE', $purchaseRequest->watcher()->first()->watch_status->value);
    }

    public function test_po_with_a_receipt_stays_in_the_ready_sync_while_sap_reports_open_quantity(): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4))->assertSessionHasNoErrors();
        Cache::flush();

        $this->withoutVite()->actingAs($user)->get(route('ready-to-receive.index'))
            ->assertOk()
            ->assertSee('260001614')
            ->assertSee('Partially Received')
            ->assertSee('yang masih memiliki Qty terbuka');

        $purchaseOrder->refresh();
        $this->assertSame(BusinessStatus::Open, $purchaseOrder->sap_business_status);
        $this->assertSame('PARTIALLY_RECEIVED', data_get($purchaseOrder->normalized_data, 'progress_status'));
        $this->assertFalse(data_get($purchaseOrder->normalized_data, 'fully_received'));
    }

    public function test_when_the_feature_is_off_the_existing_single_receipt_behaviour_is_unchanged(): void
    {
        config()->set('sap.goods_receipt.partial_receipts_enabled', false);
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        $payload = ['receipt_date' => '2026-10-28', 'lines' => [['line_num' => 0, 'quantity' => 4]]];

        $this->withoutVite()->actingAs($user)->get(route('ready-to-receive.show', $purchaseOrder))
            ->assertOk()
            ->assertDontSee('name="receipt_request_uuid"', false)
            ->assertDontSee('data-goods-receipt-history', false);
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('ready-to-receive.receive', $purchaseOrder), $payload)->assertSessionHasNoErrors();

        $this->assertCount(1, $this->postedPayloads());
        $this->assertArrayNotHasKey('U_U_WebTxnId', $this->postedPayloads()[0]);
        $this->assertDatabaseHas('sap_commands', ['status' => 'IN_SYNC', 'base_document_entry' => null]);
        $this->artisan('sap:goods-receipts:reconcile', ['--company-db' => 'TEST_DB'])->assertSuccessful();
        $this->assertFalse(collect(Http::recorded())->contains(fn (array $exchange): bool => str_contains(urldecode($exchange[0]->url()), 'U_U_WebTxnId eq')));
    }

    #[DataProvider('nonDefinitiveStatuses')]
    public function test_only_uncertain_statuses_block_the_po(string $status, bool $blocks): void
    {
        [$user, $purchaseOrder] = $this->operationalContext();
        $this->fakeSap();
        SapCommand::query()->create([
            'company_db' => 'TEST_DB',
            'transaction_uuid' => (string) str()->uuid(),
            'command_type' => 'CREATE_GOODS_RECEIPT_PO',
            'sap_entity' => 'PurchaseDeliveryNotes',
            'base_document_entry' => 500,
            'status' => $status,
            'request_payload' => [],
            'attempts' => 1,
            'last_attempt_at' => now(),
        ]);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))->actingAs($user)
            ->post(route('ready-to-receive.receive', $purchaseOrder), $this->receipt((string) str()->uuid(), 4));

        if ($blocks) {
            $response->assertSessionHasErrors('lines');
            $this->assertSame([], $this->postedPayloads());
        } else {
            $response->assertSessionHasNoErrors();
            $this->assertCount(1, $this->postedPayloads());
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function nonDefinitiveStatuses(): array
    {
        return [
            'posting (in flight or crashed)' => ['POSTING', true],
            'posting unknown' => ['POSTING_UNKNOWN', true],
            'sap error (definite rejection)' => ['SAP_ERROR', false],
            'in sync' => ['IN_SYNC', false],
        ];
    }

    /** @return array{receipt_date: string, receipt_request_uuid: string, lines: list<array{line_num: int, quantity: float|int}>} */
    private function receipt(string $uuid, float|int $quantity): array
    {
        return [
            'receipt_date' => '2026-10-28',
            'receipt_request_uuid' => $uuid,
            'lines' => [['line_num' => 0, 'quantity' => $quantity]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function postedPayloads(): array
    {
        return $this->sap->postAttempts;
    }

    private function lookedUpByUuid(string $uuid): bool
    {
        return collect(Http::recorded())->contains(fn (array $exchange): bool => $exchange[0]->method() === 'GET'
            && str_contains(urldecode($exchange[0]->url()), "U_U_WebTxnId eq '{$uuid}'"));
    }

    private function newSapState(): object
    {
        return (object) [
            'lines' => [0 => ['quantity' => 10.0, 'open' => 10.0]],
            'authorization' => 'dasWithout',
            'purchaseOrderFails' => false,
            'receipts' => [],
            'nextDocEntry' => 601,
            'postMode' => 'ok',
            'lookupFails' => false,
            'udfInMetadata' => true,
            'postAttempts' => [],
        ];
    }

    /** @param array<int, float> $quantities line number => received quantity */
    private function storeReceiptInSap(string $uuid, array $quantities): int
    {
        $docEntry = $this->sap->nextDocEntry++;
        $this->sap->receipts[$docEntry] = ['uuid' => $uuid, 'doc_num' => 260003000 + $docEntry, 'cancelled' => false, 'lines' => $quantities];

        foreach ($quantities as $lineNumber => $quantity) {
            $this->sap->lines[$lineNumber]['open'] -= $quantity;
        }

        return $docEntry;
    }

    private function fakeSap(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($url, '/Login')) {
                return Http::response(['SessionId' => 'session-1']);
            }

            if (str_ends_with($url, '/$metadata')) {
                $udf = $this->sap->udfInMetadata ? '<Property Name="U_U_WebTxnId" Type="Edm.String" />' : '';

                return Http::response('<Schema><EntityType Name="Document"><Property Name="U_UserWebSAP" Type="Edm.String" />'.$udf.'</EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseDeliveryNotes" /></EntityContainer></Schema>');
            }

            if (str_ends_with($url, '/PurchaseOrders(500)')) {
                if ($this->sap->purchaseOrderFails) {
                    return Http::response(['error' => ['message' => ['value' => 'Service Layer unavailable']]], 503);
                }

                $isClosed = collect($this->sap->lines)->every(fn (array $line): bool => $line['open'] <= 0);

                return Http::response([
                    'DocEntry' => 500,
                    'DocNum' => 260001614,
                    'CardCode' => 'S00682',
                    'CardName' => 'Sekawan, CV',
                    'Cancelled' => 'tNO',
                    'DocumentStatus' => $isClosed ? 'bost_Close' : 'bost_Open',
                    'AuthorizationStatus' => $this->sap->authorization,
                    'DocumentLines' => collect($this->sap->lines)->map(fn (array $line, int $number): array => [
                        'LineNum' => $number,
                        'LineStatus' => $line['open'] > 0 ? 'bost_Open' : 'bost_Close',
                        'ItemCode' => 'I02042',
                        'ItemDescription' => 'Baterai Yuasa NS 70',
                        'Quantity' => $line['quantity'],
                        'RemainingOpenQuantity' => max(0, $line['open']),
                        'WarehouseCode' => 'DMI-01',
                        'CostingCode3' => 'BK8815FG',
                    ])->values()->all(),
                ]);
            }

            if (str_contains($url, '/Items?')) {
                return Http::response(['value' => [[
                    'ItemCode' => 'I02042',
                    'ItemName' => 'Baterai Yuasa NS 70',
                    'InventoryUOM' => 'PCS',
                    'ItemsGroupCode' => 100,
                    'InventoryItem' => 'tYES',
                    'PurchaseItem' => 'tYES',
                    'ManageSerialNumbers' => 'tNO',
                    'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction',
                    'Frozen' => 'tNO',
                ]]]);
            }

            if (str_contains($url, '/ItemGroups?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['value' => (int) ($query['$skip'] ?? 0) === 0 ? [['Number' => 100, 'GroupName' => 'SPAREPART']] : []]);
            }

            if (str_contains($url, '$crossjoin(PurchaseOrders,PurchaseOrders/DocumentLines)')) {
                return Http::response(['value' => collect($this->sap->lines)
                    ->filter(fn (array $line): bool => $line['open'] > 0)
                    ->map(fn (array $line, int $number): array => [
                        'PurchaseOrders' => [
                            'DocEntry' => 500,
                            'DocNum' => 260001614,
                            'Series' => 887,
                            'DocDate' => '2026-10-21',
                            'DocumentStatus' => 'bost_Open',
                            'Cancelled' => 'tNO',
                            'AuthorizationStatus' => $this->sap->authorization,
                            'CardCode' => 'S00682',
                            'CardName' => 'Sekawan, CV',
                        ],
                        'PurchaseOrders/DocumentLines' => [
                            'DocEntry' => 500,
                            'LineNum' => $number,
                            'LineStatus' => 'bost_Open',
                            'ItemCode' => 'I02042',
                            'ItemDescription' => 'Baterai Yuasa NS 70',
                            'Quantity' => $line['quantity'],
                            'RemainingOpenQuantity' => $line['open'],
                            'WarehouseCode' => 'DMI-01',
                        ],
                    ])->values()->all()]);
            }

            if (str_contains($url, '$crossjoin(PurchaseDeliveryNotes,PurchaseDeliveryNotes/DocumentLines)')) {
                if ($this->sap->lookupFails) {
                    return Http::response(['error' => ['message' => ['value' => 'Unavailable']]], 503);
                }

                $rows = [];

                foreach ($this->sap->receipts as $docEntry => $receipt) {
                    foreach ($receipt['lines'] as $lineNumber => $quantity) {
                        $rows[] = [
                            'PurchaseDeliveryNotes' => ['DocEntry' => $docEntry, 'DocNum' => $receipt['doc_num'], 'Cancelled' => $receipt['cancelled'] ? 'tYES' : 'tNO', 'DocumentStatus' => 'bost_Open'],
                            'PurchaseDeliveryNotes/DocumentLines' => ['DocEntry' => $docEntry, 'LineNum' => 0, 'BaseType' => 22, 'BaseEntry' => 500, 'BaseLine' => $lineNumber, 'ItemCode' => 'I02042', 'Quantity' => $quantity, 'WarehouseCode' => 'DMI-01'],
                        ];
                    }
                }

                return Http::response(['value' => $rows]);
            }

            if ($request->method() === 'GET' && $path === '/b1s/v2/PurchaseDeliveryNotes' && str_contains($url, "U_U_WebTxnId eq '")) {
                if ($this->sap->lookupFails) {
                    return Http::response(['error' => ['message' => ['value' => 'Unavailable']]], 503);
                }

                preg_match("/U_U_WebTxnId eq '([^']+)'/", $url, $matches);

                return Http::response(['value' => collect($this->sap->receipts)
                    ->filter(fn (array $receipt): bool => $receipt['uuid'] === ($matches[1] ?? null))
                    ->map(fn (array $receipt, int $docEntry): array => ['DocEntry' => $docEntry, 'DocNum' => $receipt['doc_num'], 'Cancelled' => $receipt['cancelled'] ? 'tYES' : 'tNO', 'U_U_WebTxnId' => $receipt['uuid']])
                    ->values()
                    ->all()]);
            }

            if ($request->method() === 'GET' && preg_match('#/PurchaseDeliveryNotes\((\d+)\)$#', $path, $matches) === 1) {
                $docEntry = (int) $matches[1];
                $receipt = $this->sap->receipts[$docEntry] ?? null;

                if ($receipt === null) {
                    return Http::response([], 404);
                }

                return Http::response([
                    'DocEntry' => $docEntry,
                    'DocNum' => $receipt['doc_num'],
                    'DocDate' => '2026-10-28',
                    'Cancelled' => $receipt['cancelled'] ? 'tYES' : 'tNO',
                    'CardCode' => 'S00682',
                    'CardName' => 'Sekawan, CV',
                    'U_U_WebTxnId' => $receipt['uuid'],
                    'DocumentLines' => collect($receipt['lines'])->map(fn (float $quantity, int $lineNumber): array => [
                        'LineNum' => 0, 'BaseType' => 22, 'BaseEntry' => 500, 'BaseLine' => $lineNumber, 'ItemCode' => 'I02042', 'ItemDescription' => 'Baterai Yuasa NS 70', 'Quantity' => $quantity, 'WarehouseCode' => 'DMI-01',
                    ])->values()->all(),
                ]);
            }

            if ($request->method() === 'POST' && $path === '/b1s/v2/PurchaseDeliveryNotes') {
                $mode = $this->sap->postMode;
                $docEntry = null;
                $this->sap->postAttempts[] = $request->data();

                if (in_array($mode, ['ok', 'timeout_stored', 'server_error_stored'], true)) {
                    $docEntry = $this->storeReceiptInSap(
                        (string) ($request['U_U_WebTxnId'] ?? ''),
                        collect($request['DocumentLines'])->mapWithKeys(fn (array $line): array => [(int) $line['BaseLine'] => (float) $line['Quantity']])->all(),
                    );
                }

                return match ($mode) {
                    'ok' => Http::response(['DocEntry' => $docEntry, 'DocNum' => $this->sap->receipts[$docEntry]['doc_num']], 201),
                    'timeout_stored', 'timeout_not_stored' => throw new ConnectionException('Fake timeout'),
                    'server_error_stored' => Http::response(['error' => ['code' => -2028, 'message' => ['value' => 'Internal error']]], 500),
                    default => Http::response(['error' => ['code' => -5002, 'message' => ['value' => 'Quantity falls below open quantity']]], 400),
                };
            }

            return Http::response([], 404);
        });
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
                'series' => 887,
                'document_date' => '2026-10-21',
                'warehouse_code' => 'DMI-01',
                'progress_status' => 'READY_FOR_GRPO',
                'vendor_name' => 'Sekawan, CV',
                'lines' => [[
                    'LineNum' => 0,
                    'ItemCode' => 'I02042',
                    'ItemDescription' => 'Baterai Yuasa NS 70',
                    'Quantity' => 10,
                    'RemainingOpenQuantity' => 10,
                    'WarehouseCode' => 'DMI-01',
                    'CostingCode3' => 'BK8815FG',
                ]],
            ],
        ]);

        return [$user, $purchaseOrder];
    }

    private function superAdmin(SapDocument $purchaseOrder): User
    {
        $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $purchaseOrder->pool_id,
            'department_id' => $purchaseOrder->department_id,
            'warehouse_code' => 'DMI-01',
        ]);

        return $user;
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
        $purchaseRequest->watcher()->create(['watch_status' => 'ACTIVE', 'next_poll_at' => now()]);
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
}
