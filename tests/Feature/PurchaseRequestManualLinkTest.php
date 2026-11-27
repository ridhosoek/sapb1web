<?php

namespace Tests\Feature;

use App\Application\Purchasing\LinkPurchaseRequestToSap;
use App\Domain\Shared\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\DocumentApproval;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapWatcher;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** TD-14: manual link of Purchase Requests that were sent without the transaction UUID. SAP is always faked. */
class PurchaseRequestManualLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REASON = 'Diperiksa di SAP: komentar, baris, dan waktu dibuat sama dengan PR portal.';

    /** @var array<int, array<string, mixed>> Purchase Requests the fake SAP holds, keyed by DocEntry */
    private array $sapDocuments = [];

    /** @var array{detail?: int, search?: int, beforeDetail?: callable} */
    private array $sap = [];

    /** @var list<Request> */
    private array $reads = [];

    /** @var list<array<string, mixed>> */
    private array $logins = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-27 09:00:00');
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'OTHER_DB']);
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
        config()->set('sap.fields.web_transaction_id', 'U_U_WebTxnId');
        config()->set('sap.purchase_request.manual_link', [
            'early_tolerance_minutes' => 2,
            'max_creation_delay_minutes' => 10,
            'candidate_window_days' => 7,
        ]);
        $this->fakeSap();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_verified_request_is_linked_with_its_evidence_and_nothing_is_posted(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        $admin = $this->user(UserRole::SuperAdmin);

        $this->link($document, 27344, actor: $admin)
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('status');

        $command = SapCommand::query()->sole();
        $this->assertSame('IN_SYNC', $document->refresh()->integration_status->value);
        $this->assertSame(27344, $document->sap_doc_entry);
        $this->assertSame('260027344', $document->sap_doc_num);
        $this->assertSame('OPEN', $document->sap_business_status->value);
        $this->assertSame('WAITING_QUOTATION', $document->normalized_data['progress_status']);
        $this->assertSame('IN_SYNC', $command->status->value);
        $this->assertSame(27344, $command->sap_doc_entry);
        $this->assertSame('ACTIVE', SapWatcher::query()->sole()->watch_status->value);
        $audit = AuditLog::query()->where('event', 'purchase_request.manually_linked')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('127.0.0.1', $audit->ip_address);
        $this->assertNotNull($audit->created_at);
        $this->assertSame(27344, $audit->metadata['sap_doc_entry']);
        $this->assertSame(self::REASON, $audit->metadata['reason']);
        $this->assertSame('2026-11-26 13:42:32', $audit->metadata['portal']['attempted_at']);
        $this->assertCount(2, $audit->metadata['portal']['DocumentLines']);
        $this->assertCount(2, $audit->metadata['selected']['DocumentLines']);
        $this->assertSame('13:43:00', $audit->metadata['selected']['DocTime']);
        $this->assertSame(array_keys(LinkPurchaseRequestToSap::CHECK_LABELS), array_keys($audit->metadata['selected']['checks']));
        $this->assertNotContains(false, $audit->metadata['selected']['checks']);
        $this->assertSame([], $audit->metadata['other_candidates']);
        $this->assertSame([], array_values(array_filter($this->reads, fn (Request $request): bool => $request->method() !== 'GET')));
        $this->assertCount(2, $this->reads);
    }

    public function test_a_request_closed_in_sap_is_linked_as_closed_and_left_unwatched(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344, ['DocumentStatus' => 'bost_Close']);

        $this->link($document, 27344)->assertSessionHas('status');

        $this->assertSame('CLOSED', $document->refresh()->sap_business_status->value);
        $this->assertSame('CLOSED_IN_SAP', $document->normalized_data['progress_status']);
        $this->assertSame('STOPPED', SapWatcher::query()->sole()->watch_status->value);
    }

    public function test_the_review_page_compares_every_candidate_and_changes_nothing(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27248);
        $this->sapHolds(27278, ['Comments' => 'Pengajuan ulang', 'CreationDate' => '2026-11-28', 'DocTime' => '08:25:00', 'DocumentStatus' => 'bost_Close']);
        $this->sapHolds(27300, ['DocumentLines' => [['ItemCode' => 'I00001', 'Quantity' => 9, 'WarehouseCode' => 'PTK-01']]]);

        $this->withoutVite()->actingAs($this->user(UserRole::SuperAdmin))
            ->get(route('admin.purchase-requests.link', ['document' => $document, 'doc_entry' => 27248, 'doc_num' => '260027248']))
            ->assertOk()
            ->assertSee('data-sap-candidate="27248"', false)
            ->assertSee('data-sap-candidate="27278"', false)
            ->assertDontSee('data-sap-candidate="27300"', false)
            ->assertSee('name="reviewed_candidates[]" value="27278"', false)
            ->assertSee('Komentar sama: tidak cocok')
            ->assertSee('Waktu dibuat cocok dengan percobaan portal: tidak cocok')
            ->assertSee('Tautkan ke DocEntry 27248')
            ->assertDontSee('Tidak dapat ditautkan');

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_second_candidate_must_be_confirmed_and_both_are_kept_as_evidence(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27248);
        $this->sapHolds(27278, ['Comments' => 'Pengajuan ulang', 'CreationDate' => '2026-11-28', 'DocTime' => '08:25:00']);

        $this->link($document, 27248)->assertSessionHasErrors('link');

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $rejected = AuditLog::query()->where('event', 'purchase_request.manual_link_rejected')->sole();
        $this->assertStringContainsString('27278', implode(' ', $rejected->metadata['problems']));

        $this->link($document, 27248, reviewed: [27278])->assertSessionHas('status');

        $this->assertSame(27248, $document->refresh()->sap_doc_entry);
        $audit = AuditLog::query()->where('event', 'purchase_request.manually_linked')->sole();
        $this->assertSame([27278], $audit->metadata['reviewed_candidates']);
        $this->assertSame(27278, $audit->metadata['other_candidates'][0]['DocEntry']);
        $this->assertFalse($audit->metadata['other_candidates'][0]['checks']['comments']);
        $this->assertFalse($audit->metadata['other_candidates'][0]['checks']['created_time']);
    }

    public function test_the_rejected_candidate_cannot_be_chosen_instead(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27248);
        $this->sapHolds(27278, ['Comments' => 'Pengajuan ulang', 'CreationDate' => '2026-11-28', 'DocTime' => '08:25:00']);

        $this->link($document, 27278, reviewed: [27248])->assertSessionHasErrors('link');

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
    }

    public function test_two_requests_that_fit_equally_well_are_refused_even_when_both_are_confirmed(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        $this->sapHolds(27345, ['DocTime' => '13:44:00']);

        $this->link($document, 27344, reviewed: [27345])->assertSessionHasErrors('link');

        $this->assertStringContainsString('Pemetaan ambigu', session('errors')->first('link'));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertSame(0, SapWatcher::query()->count());
    }

    public function test_two_identical_portal_requests_waiting_for_one_sap_document_are_refused(): void
    {
        $document = $this->uncertainRequest();
        $twin = $this->uncertainRequest(['attempted_at' => '2026-11-26 13:42:50']);
        $this->sapHolds(27344);

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertStringContainsString("dokumen portal #{$twin->id}", session('errors')->first('link'));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
    }

    /** @return array<string, array{array<string, mixed>, string}> what SAP holds differently, and the failed check */
    public static function differencesThatRefuseTheLink(): array
    {
        $first = ['ItemCode' => 'I00001', 'Quantity' => 2, 'WarehouseCode' => 'PTK-01'];
        $second = ['ItemCode' => 'I00002', 'Quantity' => 1.5, 'WarehouseCode' => 'PTK-01'];

        return [
            'another DocNum' => [['DocNum' => 260099999], 'DocNum sesuai isian'],
            'cancelled' => [['Cancelled' => 'tYES'], 'Tidak dibatalkan di SAP'],
            'unknown status' => [['DocumentStatus' => 'bost_Paid'], 'Status buka/tutup dikenal'],
            'another document date' => [['DocDate' => '2026-11-25'], 'Tanggal dokumen sama'],
            'another quantity' => [['DocumentLines' => [[...$first, 'Quantity' => 3], $second]], 'Semua baris sama'],
            'another item' => [['DocumentLines' => [[...$first, 'ItemCode' => 'I09999'], $second]], 'Semua baris sama'],
            'another warehouse' => [['DocumentLines' => [[...$first, 'WarehouseCode' => 'MDN-01'], $second]], 'Semua baris sama'],
            'a line missing' => [['DocumentLines' => [$first]], 'Semua baris sama'],
            'an extra line' => [['DocumentLines' => [$first, $second, $second]], 'Semua baris sama'],
            'another comment' => [['Comments' => 'U BK8170GH - POOL MEDAN'], 'Komentar sama'],
            'created before the attempt' => [['DocTime' => '13:39:00'], 'Waktu dibuat cocok'],
            'created long after the attempt' => [['DocTime' => '13:53:00'], 'Waktu dibuat cocok'],
            'created on another day' => [['CreationDate' => '2026-11-27'], 'Waktu dibuat cocok'],
            'no creation time' => [['DocTime' => null], 'Waktu dibuat cocok'],
            'another transaction UUID' => [['U_U_WebTxnId' => '00000000-0000-4000-8000-000000000000'], 'Tidak membawa UUID transaksi lain'],
        ];
    }

    /** @param array<string, mixed> $difference */
    #[DataProvider('differencesThatRefuseTheLink')]
    public function test_a_sap_document_that_differs_is_not_linked(array $difference, string $failedCheck): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344, $difference);

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertStringContainsString($failedCheck, implode(' ', session('errors')->get('link')));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertNull($document->sap_doc_entry);
        $this->assertSame('POSTING_UNKNOWN', SapCommand::query()->sole()->status->value);
        $this->assertSame(0, SapWatcher::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'purchase_request.manual_link_rejected')->count());
    }

    /** @return array<string, array{mixed}> */
    public static function acceptedCreationTimes(): array
    {
        return [
            'rounded down to the minute' => ['13:42:00'],
            'without seconds' => ['13:43'],
            'as a number' => [1343],
            'at the end of the allowed delay' => ['13:52:00'],
        ];
    }

    #[DataProvider('acceptedCreationTimes')]
    public function test_a_creation_time_close_to_the_attempt_is_accepted(mixed $docTime): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344, ['DocTime' => $docTime]);

        $this->link($document, 27344)->assertSessionHas('status');
    }

    public function test_line_order_and_comment_spacing_do_not_matter(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344, [
            'Comments' => "U BK8170GH  -\rPOOL PONTIANAK ",
            'DocumentLines' => array_reverse($this->lines()),
        ]);

        $this->link($document, 27344)->assertSessionHas('status');
    }

    /** @return array<string, array{string}> */
    public static function ownersOfADocEntry(): array
    {
        return ['a portal document' => ['document'], 'another command' => ['command']];
    }

    #[DataProvider('ownersOfADocEntry')]
    public function test_a_doc_entry_already_used_in_the_company_is_refused(string $owner): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);

        if ($owner === 'document') {
            SapDocument::factory()->create(['document_type' => 'PURCHASE_REQUEST', 'sap_entity' => 'PurchaseRequests', 'sap_doc_entry' => 27344]);
        } else {
            SapCommand::query()->create(['company_db' => 'TEST_DB', 'transaction_uuid' => (string) Str::uuid(), 'command_type' => 'CREATE_PURCHASE_REQUEST', 'sap_entity' => 'PurchaseRequests', 'status' => 'IN_SYNC', 'sap_doc_entry' => 27344]);
        }

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertStringContainsString('belum dipakai', implode(' ', session('errors')->get('link')));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
    }

    public function test_the_same_doc_entry_in_another_company_does_not_block_the_link(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        SapDocument::factory()->create(['company_db' => 'OTHER_DB', 'document_type' => 'PURCHASE_REQUEST', 'sap_entity' => 'PurchaseRequests', 'sap_doc_entry' => 27344]);

        $this->link($document, 27344)->assertSessionHas('status');
    }

    public function test_sap_is_read_in_the_company_of_the_document_only(): void
    {
        $document = $this->uncertainRequest(['company_db' => 'OTHER_DB']);
        $this->sapHolds(27344);
        $admin = $this->user(UserRole::SuperAdmin);

        $this->link($document, 27344, actor: $admin)->assertNotFound();
        $this->assertCount(0, $this->reads);

        // The default company is still TEST_DB here; the link has to select the document's company itself.
        $result = app(LinkPurchaseRequestToSap::class)->link($document, 27344, '260027344', [], self::REASON, $admin);

        $this->assertTrue($result['linked']);
        $this->assertSame(['OTHER_DB'], array_values(array_unique(array_column($this->logins, 'CompanyDB'))));
    }

    /** @return array<string, array{array<string, int>}> */
    public static function unreadableSap(): array
    {
        return [
            'detail fails' => [['detail' => 500]],
            'candidate search fails' => [['search' => 500]],
            'DocEntry does not exist' => [['detail' => 404]],
        ];
    }

    /** @param array<string, int> $failure */
    #[DataProvider('unreadableSap')]
    public function test_nothing_is_linked_when_sap_cannot_be_read(array $failure): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        $this->sap = $failure;

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertStringContainsString('SAP tidak dapat dibaca', session('errors')->first('link'));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
    }

    public function test_the_candidate_search_follows_every_page_and_refuses_an_endless_one(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);

        foreach (range(1, 100) as $offset) {
            $this->sapHolds(27000 + $offset, ['DocumentLines' => [['ItemCode' => 'I07777', 'Quantity' => 1, 'WarehouseCode' => 'PTK-01']]]);
        }

        $this->sapHolds(27500, ['Comments' => 'Halaman kedua']);

        $review = app(LinkPurchaseRequestToSap::class)->review($document, 27344, '260027344');

        $this->assertSame([], $review['problems']);
        $this->assertSame([27500], array_column($review['others'], 'DocEntry'));
        $this->assertCount(3, $this->reads);

        foreach (range(1, 900) as $offset) {
            $this->sapHolds(28000 + $offset, ['DocumentLines' => [['ItemCode' => 'I07777', 'Quantity' => 1, 'WarehouseCode' => 'PTK-01']]]);
        }

        $this->assertStringContainsString('SAP tidak dapat dibaca', app(LinkPurchaseRequestToSap::class)->review($document, 27344, '260027344')['problems'][0]);
    }

    public function test_a_search_that_does_not_return_the_chosen_document_is_not_trusted(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344, ['CreationDate' => '2026-11-20']);

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertStringContainsString('tidak memuat DocEntry 27344', implode(' ', session('errors')->get('link')));
    }

    public function test_a_command_that_changes_while_sap_is_read_is_not_linked(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        $this->sap['beforeDetail'] = fn () => SapCommand::query()->sole()->update(['attempts' => 2]);

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertStringContainsString('berubah', session('errors')->first('link'));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertNull($document->sap_doc_entry);
        $this->assertSame(0, SapWatcher::query()->count());
    }

    public function test_a_portal_failure_while_recording_leaves_nothing_half_written(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        SapWatcher::creating(fn () => throw new RuntimeException('Portal database is unavailable.'));

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertNull($document->sap_doc_entry);
        $this->assertSame('POSTING_UNKNOWN', SapCommand::query()->sole()->status->value);
        $this->assertNull(SapCommand::query()->sole()->sap_doc_entry);
        $this->assertSame(0, AuditLog::query()->where('event', 'purchase_request.manually_linked')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'purchase_request.manual_link_rejected')->count());
    }

    public function test_submitting_the_same_link_twice_records_it_once(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        $this->sapHolds(27345, ['Comments' => 'Lain', 'DocTime' => '15:00:00']);

        $this->link($document, 27344, reviewed: [27345])->assertSessionHas('status');
        $reads = count($this->reads);
        $this->link($document, 27344, reviewed: [27345])->assertSessionHas('status');
        $this->link($document, 27345, reviewed: [27344])->assertSessionHasErrors('link');

        $this->assertSame(27344, $document->refresh()->sap_doc_entry);
        $this->assertSame(1, AuditLog::query()->where('event', 'purchase_request.manually_linked')->count());
        $this->assertSame(1, SapWatcher::query()->count());
        $this->assertCount($reads, $this->reads);
    }

    public function test_a_link_is_not_recorded_while_another_process_holds_the_command(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        $lock = Cache::lock('sap:purchase-request-reconcile:'.SapCommand::query()->sole()->id, 60);
        $this->assertTrue($lock->get());

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertCount(0, $this->reads);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function requestsTheManualLinkIsNotFor(): array
    {
        return [
            'sent with its UUID' => [['sends_uuid' => true]],
            'still waiting to be posted' => [['status' => 'PENDING_POST']],
            'rejected by SAP' => [['status' => 'SAP_ERROR']],
            'still posting' => [['status' => 'POSTING']],
            'not approved' => [['approval' => 'PENDING']],
            'without a recorded attempt' => [['attempted_at' => null]],
        ];
    }

    /** @param array<string, mixed> $state */
    #[DataProvider('requestsTheManualLinkIsNotFor')]
    public function test_other_requests_are_refused_without_reading_sap(array $state): void
    {
        $document = $this->uncertainRequest($state);
        $this->sapHolds(27344);

        $this->link($document, 27344)->assertSessionHasErrors('link');

        $this->assertNull($document->refresh()->sap_doc_entry);
        $this->assertCount(0, $this->reads);
        $this->withoutVite()->get(route('documents.show', $document))->assertDontSee('Periksa untuk Ditautkan');
    }

    public function test_a_reason_and_a_confirmation_are_required(): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->post(route('admin.purchase-requests.link.store', $document), ['doc_entry' => 27344, 'doc_num' => '260027344', 'reason' => 'singkat'])
            ->assertSessionHasErrors(['reason', 'confirm']);

        $this->assertCount(0, $this->reads);
    }

    /** @return array<string, array{UserRole}> */
    public static function rolesThatMayNotLink(): array
    {
        return ['Admin HO' => [UserRole::AdminHo], 'operational admin' => [UserRole::AdminOperational]];
    }

    #[DataProvider('rolesThatMayNotLink')]
    public function test_only_a_super_admin_can_review_or_record_a_link(UserRole $role): void
    {
        $document = $this->uncertainRequest();
        $this->sapHolds(27344);
        $user = $this->user($role);

        $this->actingAs($user)->get(route('admin.purchase-requests.link', ['document' => $document, 'doc_entry' => 27344, 'doc_num' => '260027344']))->assertForbidden();
        $this->link($document, 27344, actor: $user)->assertForbidden();

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertCount(0, $this->reads);
    }

    public function test_the_document_page_offers_the_link_to_a_super_admin(): void
    {
        $document = $this->uncertainRequest();

        $this->withoutVite()->actingAs($this->user(UserRole::SuperAdmin))->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Periksa untuk Ditautkan')
            ->assertSee(route('admin.purchase-requests.link', $document), false);

        $this->assertCount(0, $this->reads);
    }

    /** @param list<int> $reviewed */
    private function link(SapDocument $document, int $docEntry, array $reviewed = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->user(UserRole::SuperAdmin))
            ->post(route('admin.purchase-requests.link.store', $document), [
                'doc_entry' => $docEntry,
                'doc_num' => (string) (260000000 + $docEntry),
                'reviewed_candidates' => $reviewed,
                'reason' => self::REASON,
                'confirm' => '1',
            ]);
    }

    /**
     * A request as the earlier release left it: held as uncertain, with a stored payload that has no transaction UUID.
     *
     * @param  array<string, mixed>  $state
     */
    private function uncertainRequest(array $state = []): SapDocument
    {
        $companyDb = $state['company_db'] ?? 'TEST_DB';
        $status = $state['status'] ?? 'POSTING_UNKNOWN';
        $uuid = (string) Str::uuid();
        $payload = [
            'DocDate' => '2026-11-26',
            'Comments' => 'U BK8170GH - POOL PONTIANAK',
            'DocumentLines' => $this->lines(),
            ...(($state['sends_uuid'] ?? false) ? ['U_U_WebTxnId' => $uuid] : []),
        ];
        SapCommand::query()->create([
            'company_db' => $companyDb,
            'transaction_uuid' => $uuid,
            'command_type' => 'CREATE_PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'status' => $status,
            'request_payload' => $payload,
            'attempts' => 1,
            'last_attempt_at' => array_key_exists('attempted_at', $state) ? $state['attempted_at'] : '2026-11-26 13:42:32',
        ]);
        $document = SapDocument::factory()->create([
            'company_db' => $companyDb,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_entry' => null,
            'sap_doc_num' => null,
            'source' => 'WEB',
            'transaction_uuid' => $uuid,
            'integration_status' => $status,
            'created_from_web' => true,
            'normalized_data' => ['progress_status' => 'PENDING_APPROVAL', 'warehouse_code' => 'PTK-01', 'lines' => []],
        ]);
        DocumentApproval::query()->create([
            'sap_document_id' => $document->id,
            'submitted_by' => User::factory()->create()->id,
            'status' => $state['approval'] ?? 'APPROVED',
            'submitted_at' => now(),
        ]);

        return $document;
    }

    /** @return list<array{ItemCode: string, Quantity: int|float, WarehouseCode: string}> */
    private function lines(): array
    {
        return [
            ['ItemCode' => 'I00001', 'Quantity' => 2, 'WarehouseCode' => 'PTK-01'],
            ['ItemCode' => 'I00002', 'Quantity' => 1.5, 'WarehouseCode' => 'PTK-01'],
        ];
    }

    /** @param array<string, mixed> $difference */
    private function sapHolds(int $docEntry, array $difference = []): void
    {
        $this->sapDocuments[$docEntry] = [
            'DocEntry' => $docEntry,
            'DocNum' => 260000000 + $docEntry,
            'DocDate' => '2026-11-26',
            'CreationDate' => '2026-11-26',
            'DocTime' => '13:43:00',
            'Comments' => 'U BK8170GH - POOL PONTIANAK',
            'Cancelled' => 'tNO',
            'DocumentStatus' => 'bost_Open',
            'U_U_WebTxnId' => null,
            'DocumentLines' => $this->lines(),
            ...$difference,
        ];
    }

    /** Reads behave like Service Layer: the search filters by creation date and returns pages of at most 100. */
    private function fakeSap(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_ends_with($url, '/Login')) {
                $this->logins[] = $request->data();

                return Http::response(['SessionId' => 'session-1']);
            }

            $this->reads[] = $request;

            if (preg_match('#/PurchaseRequests\((\d+)\)$#', $url, $match) === 1) {
                if (isset($this->sap['beforeDetail'])) {
                    ($this->sap['beforeDetail'])();
                }

                $document = $this->sapDocuments[(int) $match[1]] ?? null;

                return Http::response($document ?? [], $this->sap['detail'] ?? ($document === null ? 404 : 200));
            }

            if ($request->method() === 'GET' && str_contains($url, '/PurchaseRequests?')) {
                preg_match("/CreationDate ge '([\d-]+)' and CreationDate le '([\d-]+)'/", (string) $request->data()['$filter'], $range);
                ksort($this->sapDocuments);
                $rows = array_values(array_filter(
                    $this->sapDocuments,
                    fn (array $document): bool => $document['CreationDate'] >= $range[1] && $document['CreationDate'] <= $range[2],
                ));

                return Http::response(['value' => array_slice($rows, (int) $request->data()['$skip'], 100)], $this->sap['search'] ?? 200);
            }

            return null;
        });
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $pool = ControlPool::query()->firstOrCreate(['code' => 'PTK'], ['name' => 'Pontianak', 'is_active' => true]);
        $department = ControlDepartment::query()->firstOrCreate(
            ['pool_id' => $pool->id, 'code' => 'TSP'],
            ['external_id' => 'dept-tsp', 'name' => 'Transport', 'is_active' => true],
        );
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'PTK-01',
        ]);

        return $user;
    }
}
