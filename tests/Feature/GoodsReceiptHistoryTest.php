<?php

namespace Tests\Feature;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BP-10 / TD-11: the history of Goods Receipt POs posted from the portal belongs to their first sender only.
 * Nothing here talks to SAP; the posting paths that store the sender are covered in GoodsReceiptPostingTest and
 * PartialGoodsReceiptTest.
 */
class GoodsReceiptHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?ControlPool $pool = null;

    private ?ControlDepartment $department = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    public function test_history_lists_only_the_receipts_the_user_sent_from_the_portal(): void
    {
        $sender = $this->operator();
        $colleague = $this->operator();
        $this->webGoodsReceipt($sender, ['sap_doc_num' => '260003001']);
        $this->webGoodsReceipt($colleague, ['sap_doc_num' => '260003002']);
        $this->goodsReceiptMadeInSap(['sap_doc_num' => '260003003']);
        $this->webGoodsReceipt(null, ['sap_doc_num' => '260003004']);

        $response = $this->actingAs($sender)->get(route('ready-to-receive.history'));

        $response->assertOk()
            ->assertSee('260003001')
            ->assertDontSee('260003002')
            ->assertDontSee('260003003')
            ->assertDontSee('260003004');
        $this->assertSame(1, substr_count($response->getContent(), 'data-goods-receipt-row='));
        Http::assertNothingSent();
    }

    public function test_history_row_shows_the_receipt_its_origin_po_and_whether_sap_cancelled_it(): void
    {
        $sender = $this->operator();
        $this->webGoodsReceipt($sender, ['sap_doc_num' => '260003011'], [
            'receipt_date' => '2026-11-10',
            'base_document_num' => '260002328',
            'vendor_name' => 'Sekawan, CV',
        ]);
        $this->webGoodsReceipt($sender, ['sap_doc_num' => '260003012', 'sap_business_status' => BusinessStatus::Cancelled]);

        $this->actingAs($sender)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertSeeInOrder(['260003011', '10/11/2026', '260002328', 'Sekawan, CV', 'DMI-01', 'Aktif'])
            ->assertSeeInOrder(['260003012', 'Dibatalkan di SAP']);
        $this->actingAs($sender)->get(route('ready-to-receive.history', ['status' => 'CANCELLED']))
            ->assertOk()
            ->assertSee('260003012')
            ->assertDontSee('260003011');
        $this->actingAs($sender)->get(route('ready-to-receive.history', ['status' => 'ACTIVE']))
            ->assertOk()
            ->assertSee('260003011')
            ->assertDontSee('260003012');
    }

    public function test_history_search_and_pagination_use_goods_receipt_data(): void
    {
        $sender = $this->operator();

        foreach (range(1, 21) as $day) {
            $this->webGoodsReceipt($sender, ['sap_doc_num' => (string) (260004000 + $day)], [
                'receipt_date' => sprintf('2026-10-%02d', $day),
                'base_document_num' => (string) (260001000 + $day),
                'vendor_name' => $day === 5 ? 'Maju Jaya, PT' : 'Sekawan, CV',
            ]);
        }

        $this->actingAs($sender)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertSee('260004021')
            ->assertDontSee('260004001');
        $this->actingAs($sender)->get(route('ready-to-receive.history', ['page' => 2]))
            ->assertOk()
            ->assertSee('260004001')
            ->assertDontSee('260004021');

        foreach (['260004007' => '260004007', '260001009' => '260004009', 'maju jaya' => '260004005'] as $search => $expectedReceipt) {
            $response = $this->actingAs($sender)->get(route('ready-to-receive.history', ['q' => $search]));

            $response->assertOk()->assertSee($expectedReceipt);
            $this->assertSame(1, substr_count($response->getContent(), 'data-goods-receipt-row='), "Search for {$search}");
        }

        $this->actingAs($sender)->get(route('ready-to-receive.history', ['q' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('Tidak ada GRPO Anda yang cocok dengan pencarian ini.');
    }

    public function test_history_is_limited_to_the_active_company_database_and_the_users_warehouse(): void
    {
        Storage::fake('local');
        $sender = $this->operator();
        $otherCompanyReceipt = $this->webGoodsReceipt($sender, ['company_db' => 'SECOND_DB', 'sap_doc_num' => '260005001']);
        $otherWarehouseReceipt = $this->webGoodsReceipt($sender, ['sap_doc_num' => '260005002'], [
            'warehouse_code' => 'MDN-01',
            'lines' => [['line_num' => 0, 'item_code' => 'I02042', 'quantity' => 2, 'warehouse_code' => 'MDN-01']],
        ]);
        $otherCompanyPhoto = $this->photoOf($otherCompanyReceipt);
        $otherWarehousePhoto = $this->photoOf($otherWarehouseReceipt);

        $this->actingAs($sender)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertDontSee('260005001')
            ->assertDontSee('260005002');
        $this->actingAs($sender)->get(route('ready-to-receive.history.show', $otherCompanyReceipt))->assertNotFound();
        $this->actingAs($sender)->get(route('document-attachments.show', $otherCompanyPhoto))->assertNotFound();
        $this->actingAs($sender)->get(route('ready-to-receive.history.show', $otherWarehouseReceipt))->assertForbidden();
        $this->actingAs($sender)->get(route('document-attachments.show', $otherWarehousePhoto))->assertForbidden();

        $this->withSession(['sap_company_db' => 'SECOND_DB'])->actingAs($sender)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertSee('260005001')
            ->assertDontSee('260005002');
    }

    public function test_the_sender_opens_the_detail_and_the_photo_of_their_own_receipt(): void
    {
        Storage::fake('local');
        $sender = $this->operator();
        $goodsReceipt = $this->webGoodsReceipt($sender, ['sap_doc_num' => '260003021']);
        $photo = $this->photoOf($goodsReceipt, 'surat-jalan.jpg');

        $this->actingAs($sender)->get(route('ready-to-receive.history.show', $goodsReceipt))
            ->assertOk()
            ->assertSee('260003021')
            ->assertSee('I02042')
            ->assertSee('surat-jalan.jpg');
        $this->actingAs($sender)->get(route('document-attachments.show', $photo))->assertOk();
        $this->actingAs($sender)->get(route('documents.show', $goodsReceipt))->assertOk();
    }

    #[DataProvider('otherUserRoles')]
    public function test_no_other_user_whatever_the_role_reaches_the_receipt_by_list_or_direct_url(UserRole $role): void
    {
        Storage::fake('local');
        $sender = $this->operator();
        $goodsReceipt = $this->webGoodsReceipt($sender, ['sap_doc_num' => '260003031']);
        $photo = $this->photoOf($goodsReceipt);
        $otherUser = $this->operator(role: $role);

        $this->actingAs($otherUser)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertDontSee('260003031');
        $this->actingAs($otherUser)->get(route('ready-to-receive.history', ['q' => '260003031']))
            ->assertOk()
            ->assertDontSee('data-goods-receipt-row=', false);
        $this->actingAs($otherUser)->get(route('ready-to-receive.history.show', $goodsReceipt))->assertNotFound();
        $this->actingAs($otherUser)->get(route('documents.show', $goodsReceipt))->assertForbidden();
        $this->actingAs($otherUser)->get(route('document-attachments.show', $photo))->assertForbidden();
    }

    /** @return array<string, array{UserRole}> */
    public static function otherUserRoles(): array
    {
        return [
            'operator of the same warehouse' => [UserRole::AdminOperational],
            'manager operational' => [UserRole::ManagerOperational],
            'admin HO' => [UserRole::AdminHo],
            'manager admin HO' => [UserRole::ManagerAdminHo],
            'super admin' => [UserRole::SuperAdmin],
        ];
    }

    public function test_a_web_receipt_without_a_recorded_sender_is_shown_to_nobody(): void
    {
        Storage::fake('local');
        $probableSender = $this->operator();
        $superAdmin = $this->operator(role: UserRole::SuperAdmin);
        $goodsReceipt = $this->webGoodsReceipt(null, ['sap_doc_num' => '260003041']);
        $photo = $this->photoOf($goodsReceipt, uploader: $probableSender);

        foreach ([$probableSender, $superAdmin] as $user) {
            $this->actingAs($user)->get(route('ready-to-receive.history'))->assertOk()->assertDontSee('260003041');
            $this->actingAs($user)->get(route('ready-to-receive.history.show', $goodsReceipt))->assertNotFound();
            $this->actingAs($user)->get(route('documents.show', $goodsReceipt))->assertForbidden();
            $this->actingAs($user)->get(route('document-attachments.show', $photo))->assertForbidden();
        }
    }

    public function test_a_goods_receipt_made_in_sap_is_never_claimed_as_a_web_receipt(): void
    {
        $operator = $this->operator();
        $madeInSap = $this->goodsReceiptMadeInSap(['sap_doc_num' => '260003051', 'web_created_by' => $operator->id]);

        $this->actingAs($operator)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertDontSee('260003051')
            ->assertSee('data-history-empty', false);
        $this->actingAs($operator)->get(route('ready-to-receive.history.show', $madeInSap))->assertNotFound();
    }

    public function test_an_empty_history_says_that_no_successful_web_receipt_is_recorded(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertSee('Belum ada GRPO yang tercatat berhasil Anda kirim dari Web SAP pada database TEST_DB.')
            ->assertDontSee('data-uncertain-own-receipts', false);
    }

    public function test_uncertain_and_rejected_postings_are_not_receipts_and_only_the_sender_is_told_about_theirs(): void
    {
        $sender = $this->operator();
        $colleague = $this->operator();
        $purchaseOrder = SapDocument::factory()->create(['sap_doc_entry' => 500, 'sap_doc_num' => '260001614']);
        $this->goodsReceiptCommand($sender, IntegrationStatus::PostingUnknown, ['base_document_entry' => $purchaseOrder->sap_doc_entry]);
        $this->goodsReceiptCommand($sender, IntegrationStatus::SapError);
        $this->goodsReceiptCommand($colleague, IntegrationStatus::Posting);

        $response = $this->actingAs($sender)->get(route('ready-to-receive.history'));

        $response->assertOk()
            ->assertSee('1 pengiriman GRPO Anda belum dapat dipastikan hasilnya.')
            ->assertSee('260001614')
            ->assertSee('data-history-empty', false)
            ->assertDontSee('data-goods-receipt-row=', false);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $goodsReceipt = $this->webGoodsReceipt($this->operator());

        $this->get(route('ready-to-receive.history'))->assertRedirect(route('login'));
        $this->get(route('ready-to-receive.history.show', $goodsReceipt))->assertRedirect(route('login'));
    }

    public function test_view_policy_gives_a_web_receipt_to_its_sender_only_and_keeps_the_warehouse_rule_for_sap_receipts(): void
    {
        $sender = $this->operator();
        $colleague = $this->operator();
        $otherWarehouseOperator = $this->operator('MDN-01');
        $adminHo = $this->operator(role: UserRole::AdminHo);
        $webReceipt = $this->webGoodsReceipt($sender);
        $unattributedReceipt = $this->webGoodsReceipt(null);
        $madeInSap = $this->goodsReceiptMadeInSap();

        $this->assertTrue($sender->can('view', $webReceipt));
        $this->assertFalse($colleague->can('view', $webReceipt));
        $this->assertFalse($adminHo->can('view', $webReceipt));
        $this->assertFalse($sender->can('view', $unattributedReceipt));
        $this->assertFalse($adminHo->can('view', $unattributedReceipt));
        $this->assertTrue($colleague->can('view', $madeInSap));
        $this->assertTrue($adminHo->can('view', $madeInSap));
        $this->assertFalse($otherWarehouseOperator->can('view', $madeInSap));
    }

    public function test_attribution_reports_first_and_stores_only_senders_proven_by_an_exact_posted_audit(): void
    {
        $sender = $this->operator();
        $reconciler = $this->operator(role: UserRole::SuperAdmin);
        $uploader = $this->operator();
        $posted = $this->webGoodsReceipt(null, ['sap_doc_entry' => 601, 'sap_doc_num' => '260003601']);
        $command = $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['transaction_uuid' => $posted->transaction_uuid, 'sap_doc_entry' => 601]);
        $this->audit('goods_receipt_po.posted', $sender, $posted, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 601]);
        $reconciledOnly = $this->webGoodsReceipt(null, ['sap_doc_entry' => 602, 'sap_doc_num' => '260003602']);
        $this->audit('goods_receipt_po.reconciled', $reconciler, $reconciledOnly, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 602]);
        $this->audit('goods_receipt_po.documentation_uploaded', $uploader, $reconciledOnly, ['company_db' => 'TEST_DB']);
        $auditForAnotherEntry = $this->webGoodsReceipt(null, ['sap_doc_entry' => 603, 'sap_doc_num' => '260003603']);
        $this->audit('goods_receipt_po.posted', $sender, $auditForAnotherEntry, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 999]);

        $this->artisan('sap:goods-receipts:attribute-creators')
            ->expectsOutputToContain('Goods Receipt PO documents: 1 can be attributed; 2 without a provable sender.')
            ->expectsOutputToContain('Report only.')
            ->assertSuccessful();
        $this->assertNull($posted->refresh()->web_created_by);

        $this->artisan('sap:goods-receipts:attribute-creators', ['--apply' => true])
            ->expectsOutputToContain('Goods Receipt PO documents: 1 attributed; 2 without a provable sender.')
            ->expectsOutputToContain('GRPO 260003602')
            ->assertSuccessful();

        $this->assertSame($sender->id, $posted->refresh()->web_created_by);
        $this->assertSame($sender->id, $command->refresh()->requested_by);
        $this->assertNull($reconciledOnly->refresh()->web_created_by);
        $this->assertNull($auditForAnotherEntry->refresh()->web_created_by);
        $this->actingAs($sender)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertSee('260003601')
            ->assertDontSee('260003602')
            ->assertDontSee('260003603');
        $this->actingAs($reconciler)->get(route('ready-to-receive.history'))->assertOk()->assertDontSee('260003602');
    }

    public function test_attribution_of_a_reconciled_receipt_needs_one_sender_for_the_exact_command_and_uuid(): void
    {
        $sender = $this->operator();
        $colleague = $this->operator();
        $reconciler = $this->operator(role: UserRole::SuperAdmin);
        $purchaseOrder = SapDocument::factory()->create(['sap_doc_entry' => 500]);
        $proven = $this->webGoodsReceipt(null, ['sap_doc_entry' => 611]);
        $provenCommand = $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['transaction_uuid' => $proven->transaction_uuid, 'sap_doc_entry' => 611]);
        $this->audit('goods_receipt_po.posting_unknown', $sender, $purchaseOrder, ['company_db' => 'TEST_DB', 'command_id' => $provenCommand->id, 'request_uuid' => $proven->transaction_uuid]);
        $this->audit('goods_receipt_po.reconciled', $reconciler, $proven, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 611]);
        $ambiguous = $this->webGoodsReceipt(null, ['sap_doc_entry' => 612]);
        $ambiguousCommand = $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['transaction_uuid' => $ambiguous->transaction_uuid, 'sap_doc_entry' => 612]);
        $this->audit('goods_receipt_po.posting_unknown', $sender, $purchaseOrder, ['company_db' => 'TEST_DB', 'command_id' => $ambiguousCommand->id, 'request_uuid' => $ambiguous->transaction_uuid]);
        $this->audit('goods_receipt_po.posting_unknown', $colleague, $purchaseOrder, ['company_db' => 'TEST_DB', 'command_id' => $ambiguousCommand->id, 'request_uuid' => $ambiguous->transaction_uuid]);

        $this->artisan('sap:goods-receipts:attribute-creators', ['--apply' => true])->assertSuccessful();

        $this->assertSame($sender->id, $proven->refresh()->web_created_by);
        $this->assertSame($sender->id, $provenCommand->refresh()->requested_by);
        $this->assertNull($ambiguous->refresh()->web_created_by);
        $this->assertNull($ambiguousCommand->refresh()->requested_by);
    }

    public function test_attribution_gives_a_receipt_to_its_one_proven_sender_and_keeps_every_other_case_hidden(): void
    {
        $sender = $this->operator();
        $colleague = $this->operator();
        $purchaseOrder = SapDocument::factory()->create(['sap_doc_entry' => 500]);
        $proven = $this->webGoodsReceipt(null, ['sap_doc_entry' => 701, 'sap_doc_num' => '260003701']);
        $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['transaction_uuid' => $proven->transaction_uuid, 'sap_doc_entry' => 701]);
        $this->audit('goods_receipt_po.posted', $sender, $proven, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 701]);
        $commandNamesSomeoneElse = $this->webGoodsReceipt(null, ['sap_doc_entry' => 702, 'sap_doc_num' => '260003702']);
        $this->goodsReceiptCommand($colleague, IntegrationStatus::InSync, ['transaction_uuid' => $commandNamesSomeoneElse->transaction_uuid, 'sap_doc_entry' => 702]);
        $this->audit('goods_receipt_po.posted', $sender, $commandNamesSomeoneElse, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 702]);
        $uncertainPostBySomeoneElse = $this->webGoodsReceipt(null, ['sap_doc_entry' => 703, 'sap_doc_num' => '260003703']);
        $uncertainCommand = $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['transaction_uuid' => $uncertainPostBySomeoneElse->transaction_uuid, 'sap_doc_entry' => 703]);
        $this->audit('goods_receipt_po.posted', $sender, $uncertainPostBySomeoneElse, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 703]);
        $this->audit('goods_receipt_po.posting_unknown', $colleague, $purchaseOrder, ['company_db' => 'TEST_DB', 'command_id' => $uncertainCommand->id, 'request_uuid' => $uncertainPostBySomeoneElse->transaction_uuid]);
        $commandForAnotherEntry = $this->webGoodsReceipt(null, ['sap_doc_entry' => 704, 'sap_doc_num' => '260003704']);
        $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['transaction_uuid' => $commandForAnotherEntry->transaction_uuid, 'sap_doc_entry' => 999]);
        $this->audit('goods_receipt_po.posted', $sender, $commandForAnotherEntry, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 704]);
        $withoutCommand = $this->webGoodsReceipt(null, ['sap_doc_entry' => 705, 'sap_doc_num' => '260003705']);
        $this->audit('goods_receipt_po.posted', $sender, $withoutCommand, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 705]);
        $withoutEvidence = $this->webGoodsReceipt(null, ['sap_doc_entry' => 706, 'sap_doc_num' => '260003706']);
        $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['transaction_uuid' => $withoutEvidence->transaction_uuid, 'sap_doc_entry' => 706]);

        $this->artisan('sap:goods-receipts:attribute-creators')
            ->expectsOutputToContain('read only.')
            ->expectsOutputToContain('TEST_DB: 1 one proven sender; 2 more than one sender; 1 no evidence; 2 inconsistent command/UUID.')
            ->expectsOutputToContain("TEST_DB sap_documents.id can be attributed: {$proven->id}")
            ->assertSuccessful();
        $this->assertNull($proven->refresh()->web_created_by);

        $this->artisan('sap:goods-receipts:attribute-creators', ['--apply' => true])
            ->expectsOutputToContain("TEST_DB sap_documents.id attributed: {$proven->id}")
            ->assertSuccessful();

        $this->assertSame($sender->id, $proven->refresh()->web_created_by);

        foreach ([$commandNamesSomeoneElse, $uncertainPostBySomeoneElse, $commandForAnotherEntry, $withoutCommand, $withoutEvidence] as $unproven) {
            $this->assertNull($unproven->refresh()->web_created_by, "GRPO {$unproven->sap_doc_num} must stay without an owner.");
        }

        $this->assertNull($uncertainCommand->refresh()->requested_by);
        $this->actingAs($sender)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertSee('260003701')
            ->assertDontSee('260003702')
            ->assertDontSee('260003703')
            ->assertDontSee('260003704')
            ->assertDontSee('260003705')
            ->assertDontSee('260003706');
        $this->actingAs($sender)->get(route('ready-to-receive.history.show', $proven))->assertOk();
        $this->actingAs($sender)->get(route('ready-to-receive.history.show', $commandNamesSomeoneElse))->assertNotFound();
        $this->actingAs($colleague)->get(route('ready-to-receive.history'))
            ->assertOk()
            ->assertDontSee('260003701')
            ->assertDontSee('260003702')
            ->assertDontSee('260003703');
        $this->actingAs($colleague)->get(route('ready-to-receive.history.show', $proven))->assertNotFound();
    }

    public function test_attribution_ignores_a_command_of_another_company_with_the_same_request_uuid(): void
    {
        $sender = $this->operator();
        $goodsReceipt = $this->webGoodsReceipt(null, ['sap_doc_entry' => 711]);
        $this->goodsReceiptCommand(null, IntegrationStatus::InSync, ['company_db' => 'OTHER_DB', 'transaction_uuid' => $goodsReceipt->transaction_uuid, 'sap_doc_entry' => 711]);
        $this->audit('goods_receipt_po.posted', $sender, $goodsReceipt, ['company_db' => 'TEST_DB', 'sap_doc_entry' => 711]);

        $this->artisan('sap:goods-receipts:attribute-creators', ['--apply' => true])
            ->expectsOutputToContain('TEST_DB: 0 one proven sender; 0 more than one sender; 0 no evidence; 1 inconsistent command/UUID.')
            ->assertSuccessful();

        $this->assertNull($goodsReceipt->refresh()->web_created_by);
    }

    private function operator(string $warehouseCode = 'DMI-01', UserRole $role = UserRole::AdminOperational): User
    {
        $this->pool ??= ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $this->department ??= ControlDepartment::query()->create([
            'pool_id' => $this->pool->id,
            'external_id' => 'tsp-dumai',
            'code' => 'TSP',
            'name' => 'Transport',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $this->pool->id,
            'department_id' => $this->department->id,
            'warehouse_code' => $warehouseCode,
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $data
     */
    private function webGoodsReceipt(?User $sender, array $attributes = [], array $data = []): SapDocument
    {
        return SapDocument::factory()->create([
            'document_type' => 'GOODS_RECEIPT_PO',
            'sap_entity' => 'PurchaseDeliveryNotes',
            'source' => 'WEB',
            'created_from_web' => true,
            'web_created_by' => $sender?->id,
            'transaction_uuid' => (string) str()->uuid(),
            'normalized_data' => [
                'base_document_type' => 'PURCHASE_ORDER',
                'base_document_num' => '260001614',
                'receipt_date' => '2026-10-28',
                'vendor_name' => 'Sekawan, CV',
                'warehouse_code' => 'DMI-01',
                'lines' => [['line_num' => 0, 'item_code' => 'I02042', 'item_name' => 'Baterai Yuasa NS 70', 'quantity' => 2, 'warehouse_code' => 'DMI-01', 'serial_numbers' => []]],
                ...$data,
            ],
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function goodsReceiptMadeInSap(array $attributes = []): SapDocument
    {
        return $this->webGoodsReceipt(null, ['source' => 'SAP', 'created_from_web' => false, 'transaction_uuid' => null, ...$attributes]);
    }

    private function photoOf(SapDocument $goodsReceipt, string $name = 'foto.jpg', ?User $uploader = null): SapDocumentAttachment
    {
        $path = 'goods-receipt-po/'.$goodsReceipt->id.'/'.str()->uuid().'.jpg';
        Storage::disk('local')->put($path, 'photo');

        return SapDocumentAttachment::factory()->create([
            'sap_document_id' => $goodsReceipt->id,
            'uploaded_by' => $uploader?->id ?? $goodsReceipt->web_created_by,
            'path' => $path,
            'original_name' => $name,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function goodsReceiptCommand(?User $sender, IntegrationStatus $status, array $attributes = []): SapCommand
    {
        return SapCommand::query()->create([
            'company_db' => 'TEST_DB',
            'transaction_uuid' => (string) str()->uuid(),
            'command_type' => SapCommand::CREATE_GOODS_RECEIPT_PO,
            'sap_entity' => 'PurchaseDeliveryNotes',
            'requested_by' => $sender?->id,
            'status' => $status,
            'request_payload' => [],
            'attempts' => 1,
            'last_attempt_at' => now(),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $event, User $actor, SapDocument $document, array $metadata): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor->id,
            'event' => $event,
            'auditable_type' => SapDocument::class,
            'auditable_id' => $document->id,
            'metadata' => $metadata,
        ]);
    }
}
