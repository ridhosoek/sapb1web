<?php

namespace Tests\Feature;

use App\Application\Purchasing\ReconcilePurchaseRequest;
use App\Domain\Sap\Enums\PurchaseRequestReconciliationOutcome as Outcome;
use App\Domain\Shared\Enums\UserRole;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapApiLog;
use App\Models\SapCommand;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\SapWatcher;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** TD-14: Purchase Requests whose POST outcome is not certain. SAP is always faked. */
class PurchaseRequestReconciliationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const UUID_FIELD = 'U_U_WebTxnId';

    /** @var array<string, mixed> what the fake SAP does with a POST, a UUID lookup and a detail read */
    private array $sap = [];

    /** @var list<Request> every POST that reached the fake SAP, including those that lost the connection */
    private array $posts = [];

    /** @var list<Request> */
    private array $lookups = [];

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
        config()->set('sap.fields.web_transaction_id', self::UUID_FIELD);
        config()->set('sap.purchase_request.require_web_transaction_id_companies', []);
        config()->set('sap.purchase_request.reconcile', [
            'schedule_enabled' => false,
            'batch_size' => 5,
            'min_age_seconds' => 300,
            'max_attempts' => 3,
            'backoff_seconds' => 600,
            'max_backoff_seconds' => 21600,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_normal_post_records_one_purchase_request_and_logs_it_under_the_transaction_uuid(): void
    {
        $this->fakeSap();

        $document = $this->approvedPurchaseRequest();

        $command = SapCommand::query()->sole();
        $this->assertSame('IN_SYNC', $document->integration_status->value);
        $this->assertSame(501, $document->sap_doc_entry);
        $this->assertSame('260000501', $document->sap_doc_num);
        $this->assertSame('WAITING_QUOTATION', $document->normalized_data['progress_status']);
        $this->assertSame('OPEN', $document->sap_business_status->value);
        $this->assertSame('IN_SYNC', $command->status->value);
        $this->assertSame(501, $command->sap_doc_entry);
        $this->assertSame('ACTIVE', SapWatcher::query()->sole()->watch_status->value);
        $this->assertSame(1, AuditLog::query()->where('event', 'purchase_request.posted_after_approval')->count());
        $this->assertCount(0, $this->lookups);
        $this->assertSame($command->transaction_uuid, SapApiLog::query()->where('method', 'POST')->sole()->correlation_id);
        $this->assertCount(1, $this->posts);
    }

    /** @return array<string, array{string}> */
    public static function uncertainPosts(): array
    {
        return [
            'HTTP 502' => ['502'],
            'HTTP 408' => ['408'],
            'connection lost' => ['connection'],
            'success without DocEntry' => ['no-doc-entry'],
        ];
    }

    #[DataProvider('uncertainPosts')]
    public function test_a_purchase_request_that_sap_created_despite_an_uncertain_post_is_matched_without_a_second_post(string $failure): void
    {
        $this->fakeSap(['post' => $failure]);
        $document = $this->approvedPurchaseRequest();
        $command = SapCommand::query()->sole();

        $this->assertSame('POSTING_UNKNOWN', $document->integration_status->value);
        $this->assertSame('POSTING_UNKNOWN', $command->status->value);
        $this->assertSame('SAP_RESPONSE', AuditLog::query()->where('event', 'purchase_request.posting_unknown')->sole()->metadata['stage']);

        $this->artisan('sap:purchase-requests:reconcile')
            ->expectsOutputToContain('RECONCILED')
            ->assertSuccessful();

        $document->refresh();
        $command->refresh();
        $this->assertSame('IN_SYNC', $document->integration_status->value);
        $this->assertSame(501, $document->sap_doc_entry);
        $this->assertSame('260000501', $document->sap_doc_num);
        $this->assertSame('WAITING_QUOTATION', $document->normalized_data['progress_status']);
        $this->assertSame('IN_SYNC', $command->status->value);
        $this->assertSame(501, $command->sap_doc_entry);
        $this->assertNull($command->last_error);
        $this->assertNull($command->next_reconcile_at);
        $this->assertSame(Outcome::Reconciled, $command->reconcile_outcome);
        $this->assertSame(1, $command->reconcile_match_count);
        $this->assertSame('ACTIVE', SapWatcher::query()->sole()->watch_status->value);
        $audit = AuditLog::query()->where('event', 'purchase_request.reconciled')->sole();
        $this->assertSame('SCHEDULER', $audit->metadata['trigger']);
        $this->assertSame('POSTING_UNKNOWN', $audit->metadata['reconciled_from_status']);
        $this->assertCount(1, $this->posts);
        $this->assertSame(
            [$command->transaction_uuid],
            SapApiLog::query()->where('endpoint', 'like', '/PurchaseRequests%')->distinct()->pluck('correlation_id')->all(),
        );
        Http::assertSent(fn (Request $request): bool => $this->isLookup($request)
            && $request->data()['$filter'] === self::UUID_FIELD." eq '{$command->transaction_uuid}'"
            && (int) $request->data()['$top'] === 2);

        $this->assertSame(Outcome::AlreadySynced, app(ReconcilePurchaseRequest::class)->handle($command));
        $this->assertSame(1, AuditLog::query()->where('event', 'purchase_request.reconciled')->count());
        $this->assertCount(1, $this->lookups);
    }

    public function test_a_reconciled_request_that_is_open_in_sap_is_shown_open_and_waiting_for_a_quotation(): void
    {
        $this->fakeSap(['post' => '502', 'detail' => ['DocumentStatus' => 'bost_Open']]);
        $document = $this->approvedPurchaseRequest();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.purchase-requests.reconcile', $document))->assertSessionHas('status');

        $this->assertSame('OPEN', $document->refresh()->sap_business_status->value);
        $this->assertSame('WAITING_QUOTATION', $document->normalized_data['progress_status']);
        $watcher = SapWatcher::query()->sole();
        $this->assertSame('ACTIVE', $watcher->watch_status->value);
        $this->assertNotNull($watcher->next_poll_at);
        $this->withoutVite()->actingAs($admin)->get(route('purchase-progress.index'))
            ->assertOk()
            ->assertSee('Waiting Quotation')
            ->assertSee('data-progress-step="quotation" data-progress-state="current"', false)
            ->assertDontSee('Ditutup di SAP');
        $this->get(route('documents.show', $document))->assertSeeInOrder(['Status Dokumen', 'Open', 'Status Integrasi', 'In Sync']);
    }

    public function test_a_reconciled_request_that_sap_has_closed_is_shown_closed_without_claiming_later_steps(): void
    {
        $this->fakeSap(['post' => '502', 'detail' => ['DocumentStatus' => 'bost_Close']]);
        $document = $this->approvedPurchaseRequest();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.purchase-requests.reconcile', $document))->assertSessionHas('status');

        $command = SapCommand::query()->sole();
        $this->assertSame('IN_SYNC', $document->refresh()->integration_status->value);
        $this->assertSame('CLOSED', $document->sap_business_status->value);
        $this->assertSame('CLOSED_IN_SAP', $document->normalized_data['progress_status']);
        $this->assertSame(501, $document->sap_doc_entry);
        $this->assertSame('IN_SYNC', $command->status->value);
        $this->assertSame(501, $command->sap_doc_entry);
        $watcher = SapWatcher::query()->sole();
        $this->assertSame('STOPPED', $watcher->watch_status->value);
        $this->assertNull($watcher->next_poll_at);
        $this->assertSame('bost_Close', AuditLog::query()->where('event', 'purchase_request.reconciled')->sole()->metadata['sap_document_status']);

        $this->withoutVite()->actingAs($admin)->get(route('purchase-progress.index'))
            ->assertOk()
            ->assertSee('Ditutup di SAP; tahap lanjutan belum diperiksa')
            ->assertSee('data-progress-step="pr" data-progress-state="done"', false)
            ->assertSee('data-progress-step="quotation" data-progress-state="pending"', false)
            ->assertSee('data-progress-step="po" data-progress-state="pending"', false)
            ->assertSee('data-progress-step="grpo" data-progress-state="pending"', false)
            ->assertDontSee('Waiting Quotation');
        $this->get(route('documents.show', $document))
            ->assertSeeInOrder(['Status Dokumen', 'Closed', 'Status Integrasi', 'In Sync'])
            ->assertSee('CLOSED IN SAP')
            ->assertDontSee('WAITING QUOTATION');
        $this->get(route('purchase-requests.index'))->assertSee('Closed In Sap')->assertDontSee('Waiting Quotation');

        $this->actingAs($admin)->post(route('documents.post-approved', $document));
        $requestsBeforePolling = Http::recorded()->count();
        $this->artisan('sap:purchase-progress:sync')->expectsOutputToContain('No matching')->assertSuccessful();

        $this->assertSame($requestsBeforePolling, Http::recorded()->count());
        $this->assertCount(1, $this->posts);
    }

    public function test_a_manual_progress_check_of_a_closed_request_keeps_it_closed_and_unwatched(): void
    {
        $this->fakeSap(['post' => '502', 'detail' => ['DocumentStatus' => 'bost_Close']]);
        $document = $this->approvedPurchaseRequest();
        $this->assertSame(Outcome::Reconciled, app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole()));

        $this->actingAs($this->admin())->post(route('purchase-progress.sync', $document))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'belum ditemukan'));

        $this->assertSame('CLOSED', $document->refresh()->sap_business_status->value);
        $this->assertSame('CLOSED_IN_SAP', $document->normalized_data['progress_status']);
        $watcher = SapWatcher::query()->sole();
        $this->assertSame('STOPPED', $watcher->watch_status->value);
        $this->assertNull($watcher->next_poll_at);
    }

    public function test_a_synced_document_with_a_still_uncertain_command_is_left_for_investigation_without_a_post(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        // No portal flow writes this combination: the document and its command are always recorded together.
        $document->update(['integration_status' => 'IN_SYNC', 'sap_doc_entry' => 501]);

        $this->assertSame(Outcome::NotEligible, app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole()));
        $this->actingAs($this->admin())->post(route('documents.post-approved', $document));
        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('No uncertain')->assertSuccessful();

        $this->assertSame('POSTING_UNKNOWN', SapCommand::query()->sole()->status->value);
        $this->assertCount(1, $this->posts);
        $this->assertCount(0, $this->lookups);
    }

    public function test_a_portal_failure_after_sap_accepted_the_post_is_recorded_as_such_and_recovered(): void
    {
        $this->fakeSap();
        $failOnce = true;
        SapWatcher::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('Portal database is unavailable.');
            }
        });

        $document = $this->approvedPurchaseRequest();

        $command = SapCommand::query()->sole();
        $this->assertSame('POSTING_UNKNOWN', $document->integration_status->value);
        $this->assertNull($document->sap_doc_entry);
        $this->assertSame('POSTING_UNKNOWN', $command->status->value);
        $this->assertSame(501, $command->sap_doc_entry);
        $this->assertStringContainsString('SAP sudah menerima Purchase Request (DocEntry 501)', $command->last_error);
        $this->assertSame('PORTAL_RECORDING', AuditLog::query()->where('event', 'purchase_request.posting_unknown')->sole()->metadata['stage']);
        $this->assertSame(0, AuditLog::query()->where('event', 'purchase_request.posted_after_approval')->count());

        $this->assertSame(Outcome::Reconciled, app(ReconcilePurchaseRequest::class)->handle($command));
        $this->assertSame('IN_SYNC', $document->refresh()->integration_status->value);
        $this->assertSame(501, $document->sap_doc_entry);
        $this->assertSame(1, SapWatcher::query()->count());
        $this->assertCount(1, $this->posts);
    }

    public function test_a_failure_while_recording_the_reconciliation_leaves_nothing_half_written(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        SapWatcher::creating(fn () => throw new RuntimeException('Portal database is unavailable.'));

        $outcome = app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole());

        $command = SapCommand::query()->sole();
        $this->assertSame(Outcome::RecordingFailed, $outcome);
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertNull($document->sap_doc_entry);
        $this->assertSame('POSTING_UNKNOWN', $command->status->value);
        $this->assertNull($command->sap_doc_entry);
        $this->assertNotNull($command->next_reconcile_at);
        $this->assertSame(0, AuditLog::query()->where('event', 'purchase_request.reconciled')->count());
    }

    public function test_nothing_found_keeps_the_request_held_and_never_posts_again(): void
    {
        $this->fakeSap(['post' => '502', 'matches' => 0]);
        $document = $this->approvedPurchaseRequest();

        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('NOT_FOUND')->assertSuccessful();
        $this->artisan('sap:post-approved')->expectsOutputToContain('Posted 0 of 0')->assertSuccessful();
        $this->reconcileAsAdmin($document)->assertSessionHasErrors('sap');
        $this->actingAs($this->admin())->post(route('documents.post-approved', $document), [
            'evidence' => 'Dokumen tidak ditemukan dalam pemeriksaan SAP.',
            'confirm_not_in_sap' => '1',
        ])->assertSessionHasErrors('sap');

        $command = SapCommand::query()->sole();
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertSame('POSTING_UNKNOWN', $command->status->value);
        $this->assertSame(Outcome::NotFound, $command->reconcile_outcome);
        $this->assertSame(0, $command->reconcile_match_count);
        $this->assertSame(2, $command->reconcile_attempts);
        $this->assertStringContainsString('HTTP 502', $command->last_error);
        $this->assertCount(1, $this->posts);
    }

    /** @return array<string, array{array<string, mixed>, Outcome}> */
    public static function answersThatMustNotBeRecorded(): array
    {
        return [
            'two requests carry the UUID' => [['matches' => 2], Outcome::MultipleMatches],
            'lookup returns HTTP 500' => [['lookup' => 500], Outcome::LookupFailed],
            'lookup loses the connection' => [['lookup' => 'connection'], Outcome::LookupFailed],
            'detail returns HTTP 500' => [['detailStatus' => 500], Outcome::LookupFailed],
            'lookup has no value list' => [['lookupBody' => ['error' => 'unexpected']], Outcome::InvalidResponse],
            'lookup row has no DocEntry' => [['lookupBody' => ['value' => [['DocNum' => 1]]]], Outcome::InvalidResponse],
            'lookup row carries another UUID' => [['lookupBody' => ['value' => [['DocEntry' => 501, self::UUID_FIELD => '00000000-0000-4000-8000-000000000000']]]], Outcome::InvalidResponse],
            'lookup page is incomplete' => [['lookupBody' => ['value' => [], '@odata.nextLink' => 'PurchaseRequests?$skip=1']], Outcome::InvalidResponse],
            'detail has no lines' => [['detail' => ['DocumentLines' => null]], Outcome::InvalidResponse],
            'detail carries another UUID' => [['detail' => [self::UUID_FIELD => '00000000-0000-4000-8000-000000000000']], Outcome::DataMismatch],
            'cancelled in SAP' => [['detail' => ['Cancelled' => 'tYES']], Outcome::DataMismatch],
            'another document date' => [['detail' => ['DocDate' => '2026-11-01']], Outcome::DataMismatch],
            'document status missing' => [['detail' => ['DocumentStatus' => null]], Outcome::InvalidResponse],
            'document status empty' => [['detail' => ['DocumentStatus' => '']], Outcome::InvalidResponse],
            'document status unknown' => [['detail' => ['DocumentStatus' => 'bost_Paid']], Outcome::InvalidResponse],
            'another quantity' => [['line' => ['Quantity' => 9]], Outcome::DataMismatch],
            'another item' => [['line' => ['ItemCode' => 'I99999']], Outcome::DataMismatch],
            'another warehouse' => [['line' => ['WarehouseCode' => 'PLB-01']], Outcome::DataMismatch],
            'UDF missing from metadata' => [['metadataHasField' => false], Outcome::FieldUnsupported],
        ];
    }

    /** @param array<string, mixed> $answer */
    #[DataProvider('answersThatMustNotBeRecorded')]
    public function test_an_ambiguous_failed_or_contradicting_answer_changes_nothing(array $answer, Outcome $expected): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        $this->sap = [...$this->sap, ...$answer];
        Cache::store('array')->forget('sap:metadata:'.sha1('TEST_DB'));

        $outcome = app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole());

        $command = SapCommand::query()->sole();
        $this->assertSame($expected, $outcome);
        $this->assertSame($expected, $command->reconcile_outcome);
        $this->assertSame('POSTING_UNKNOWN', $command->status->value);
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertNull($document->sap_doc_entry);
        $this->assertSame('WAITING_SAP_SYNC', $document->normalized_data['progress_status']);
        $this->assertSame($expected->isRetryable(), $command->next_reconcile_at !== null);
        $this->assertSame(0, SapWatcher::query()->count());
        $this->assertCount(1, $this->posts);
    }

    public function test_a_doc_entry_already_held_by_another_portal_document_is_not_recorded_twice(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        SapDocument::factory()->create([
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_entry' => 501,
        ]);

        $outcome = app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole());

        $this->assertSame(Outcome::DataMismatch, $outcome);
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
    }

    public function test_a_doc_entry_different_from_the_one_sap_answered_with_is_not_recorded(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        SapCommand::query()->sole()->update(['sap_doc_entry' => 777]);

        $this->assertSame(Outcome::DataMismatch, app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole()));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
    }

    public function test_a_request_that_was_sent_without_the_uuid_is_never_searched_for(): void
    {
        $this->fakeSap(['post' => '502', 'metadataHasField' => false]);
        $document = $this->approvedPurchaseRequest();

        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('UUID_NOT_SENT')->assertSuccessful();

        $command = SapCommand::query()->sole();
        $this->assertArrayNotHasKey(self::UUID_FIELD, $command->request_payload);
        $this->assertSame(Outcome::UuidNotSent, $command->reconcile_outcome);
        $this->assertNull($command->next_reconcile_at);
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertCount(0, $this->lookups);
        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('No uncertain')->assertSuccessful();
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function ineligibleRows(): array
    {
        return [
            'another command type' => [['command_type' => 'CREATE_GOODS_ISSUE'], []],
            'another SAP entity' => [['sap_entity' => 'InventoryGenExits'], []],
            'another document type' => [[], ['document_type' => 'GOODS_ISSUE']],
            'document of another company' => [[], ['company_db' => 'OTHER_DB']],
            'command already rejected by SAP' => [['status' => 'SAP_ERROR'], []],
            'document waiting to be posted' => [[], ['integration_status' => 'PENDING_POST']],
        ];
    }

    /**
     * @param  array<string, mixed>  $commandChange
     * @param  array<string, mixed>  $documentChange
     */
    #[DataProvider('ineligibleRows')]
    public function test_rows_that_do_not_match_are_not_looked_up_or_changed(array $commandChange, array $documentChange): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        $document->update($documentChange);
        SapCommand::query()->sole()->update($commandChange);
        $before = $document->refresh()->integration_status;

        $outcome = app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole());

        $this->assertSame(Outcome::NotEligible, $outcome);
        $this->assertSame($before, $document->refresh()->integration_status);
        $this->assertNull($document->sap_doc_entry);
        $this->assertCount(0, $this->lookups);
    }

    public function test_a_posting_command_is_only_reconciled_once_it_is_stale(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        $document->update(['integration_status' => 'POSTING']);
        SapCommand::query()->sole()->update(['status' => 'POSTING']);

        Carbon::setTestNow('2026-11-27 09:04:00');
        $this->assertSame(Outcome::StillInFlight, app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole()));
        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('No uncertain')->assertSuccessful();
        $this->assertSame(0, SapCommand::query()->sole()->reconcile_attempts);
        $this->assertCount(0, $this->lookups);

        Carbon::setTestNow('2026-11-27 09:05:00');
        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('RECONCILED')->assertSuccessful();
        $this->assertSame('IN_SYNC', $document->refresh()->integration_status->value);
        $this->assertCount(1, $this->posts);
    }

    public function test_a_second_worker_does_nothing_while_the_command_is_being_checked(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        $command = SapCommand::query()->sole();
        $lock = Cache::lock("sap:purchase-request-reconcile:{$command->id}", 60);
        $this->assertTrue($lock->get());

        $this->assertSame(Outcome::Busy, app(ReconcilePurchaseRequest::class)->handle($command));

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertSame(0, $command->refresh()->reconcile_attempts);
        $this->assertCount(0, $this->lookups);
        $lock->release();
        $this->assertSame(Outcome::Reconciled, app(ReconcilePurchaseRequest::class)->handle($command));
    }

    public function test_a_post_that_fails_after_the_request_was_already_matched_does_not_undo_it(): void
    {
        $this->fakeSap(['post' => 'matched-by-another-worker-then-502']);

        $document = $this->approvedPurchaseRequest();

        $this->assertSame('IN_SYNC', $document->integration_status->value);
        $this->assertSame('IN_SYNC', SapCommand::query()->sole()->status->value);
        $this->assertSame(0, AuditLog::query()->where('event', 'purchase_request.posting_unknown')->count());
    }

    public function test_the_batch_is_limited_and_later_checks_back_off_until_the_attempt_limit(): void
    {
        $this->fakeSap(['post' => '502', 'matches' => 0]);
        $this->approvedPurchaseRequest();
        $this->approvedPurchaseRequest();

        $this->artisan('sap:purchase-requests:reconcile', ['--limit' => 1])->assertSuccessful();
        $this->assertCount(1, $this->lookups);
        $this->artisan('sap:purchase-requests:reconcile', ['--limit' => 1])->assertSuccessful();
        $this->assertCount(2, $this->lookups);
        $this->assertSame([1, 1], SapCommand::query()->orderBy('id')->pluck('reconcile_attempts')->all());

        Carbon::setTestNow('2026-11-27 09:09:59');
        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('No uncertain');
        Carbon::setTestNow('2026-11-27 09:10:00');
        $this->artisan('sap:purchase-requests:reconcile');
        $this->assertCount(4, $this->lookups);
        $this->assertSame('2026-11-27 09:30:00', SapCommand::query()->firstOrFail()->next_reconcile_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-27 09:30:00');
        $this->artisan('sap:purchase-requests:reconcile');
        Carbon::setTestNow('2026-11-28 09:00:00');
        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('No uncertain');

        $this->assertCount(6, $this->lookups);
        $this->assertSame(0, SapCommand::query()->whereNotNull('next_reconcile_at')->count());
        $this->assertCount(2, $this->posts);
    }

    public function test_the_batch_stops_at_the_first_failed_sap_read(): void
    {
        $this->fakeSap(['post' => '502', 'lookup' => 500]);
        $this->approvedPurchaseRequest();
        $this->approvedPurchaseRequest();

        $this->artisan('sap:purchase-requests:reconcile')->expectsOutputToContain('LOOKUP_FAILED')->assertFailed();

        $this->assertCount(1, $this->lookups);
    }

    public function test_commands_of_another_company_are_left_alone(): void
    {
        $this->fakeSap(['post' => '502']);
        $this->approvedPurchaseRequest();

        $this->artisan('sap:purchase-requests:reconcile', ['--company-db' => 'OTHER_DB'])
            ->expectsOutputToContain('No uncertain')
            ->assertSuccessful();

        $this->assertCount(0, $this->lookups);
    }

    public function test_the_schedule_only_runs_when_it_is_switched_on(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'sap:purchase-requests:reconcile'));

        $this->assertNotEmpty($events);
        $this->assertFalse($events->first()->filtersPass($this->app));
        config()->set('sap.purchase_request.reconcile.schedule_enabled', true);
        $this->assertTrue($events->first()->filtersPass($this->app));
    }

    public function test_the_field_check_reports_whether_a_company_is_ready(): void
    {
        $this->fakeSap();

        $this->artisan('sap:purchase-requests:reconcile', ['--check-field' => true])
            ->expectsOutputToContain('TEST_DB: READY')
            ->assertSuccessful();

        Cache::store('array')->forget('sap:metadata:'.sha1('TEST_DB'));
        $this->sap['metadataHasField'] = false;

        $this->artisan('sap:purchase-requests:reconcile', ['--check-field' => true])
            ->expectsOutputToContain('NOT READY')
            ->assertFailed();
    }

    public function test_a_company_that_requires_the_uuid_does_not_send_a_request_without_it(): void
    {
        config()->set('sap.purchase_request.require_web_transaction_id_companies', ['TEST_DB']);
        $this->fakeSap(['metadataHasField' => false]);

        $document = $this->approvedPurchaseRequest();

        $this->assertSame('PENDING_POST', $document->integration_status->value);
        $this->assertSame(0, SapCommand::query()->sole()->attempts);
        $this->assertCount(0, $this->posts);
    }

    public function test_a_failed_sap_login_leaves_the_request_waiting_instead_of_uncertain(): void
    {
        $this->fakeSap(['login' => 500]);
        Cache::store('array')->put('sap:metadata:'.sha1('TEST_DB'), $this->metadata(true), 3600);

        $document = $this->approvedPurchaseRequest();

        $this->assertSame('PENDING_POST', $document->integration_status->value);
        $this->assertSame('PENDING_POST', SapCommand::query()->sole()->status->value);
        $this->assertCount(0, $this->posts);
    }

    public function test_a_lost_connection_is_reported_to_the_approver_as_an_uncertain_result(): void
    {
        $this->fakeSap(['post' => 'connection']);

        $this->approvedPurchaseRequest();

        $this->assertStringContainsString('belum pasti', (string) session('warning'));
        $this->assertStringContainsString('Jangan dikirim ulang', (string) session('warning'));
    }

    public function test_a_lost_connection_on_an_administrator_send_does_not_promise_an_automatic_retry(): void
    {
        $this->fakeSap(['login' => 500]);
        Cache::store('array')->put('sap:metadata:'.sha1('TEST_DB'), $this->metadata(true), 3600);
        $document = $this->approvedPurchaseRequest();
        $this->sap = ['post' => 'connection'];

        $this->actingAs($this->admin())->post(route('documents.post-approved', $document))
            ->assertSessionHasErrors('sap');

        $this->assertStringContainsString('Jangan kirim ulang', session('errors')->first('sap'));
        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
    }

    public function test_an_administrator_can_reconcile_from_the_document_page_and_the_action_is_audited(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        $admin = $this->admin();

        $this->withoutVite()->actingAs($admin)->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Periksa SAP &amp; Rekonsiliasi', false)
            ->assertSee('Terkirim pada '.self::UUID_FIELD)
            ->assertSee('Belum pernah diperiksa.')
            ->assertDontSee('Kirim Ulang ke SAP');

        $this->actingAs($admin)->post(route('admin.purchase-requests.reconcile', $document))
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('status');

        $this->assertSame('IN_SYNC', $document->refresh()->integration_status->value);
        $requested = AuditLog::query()->where('event', 'purchase_request.reconciliation_requested')->sole();
        $this->assertSame($admin->id, $requested->actor_id);
        $this->assertSame('RECONCILED', $requested->metadata['outcome']);
        $this->assertSame('POSTING_UNKNOWN', $requested->metadata['previous_status']);
        $reconciled = AuditLog::query()->where('event', 'purchase_request.reconciled')->sole();
        $this->assertSame($admin->id, $reconciled->actor_id);
        $this->assertSame('ADMIN', $reconciled->metadata['trigger']);
    }

    public function test_only_an_administrator_of_the_same_company_can_force_a_reconciliation(): void
    {
        $this->fakeSap(['post' => '502']);
        $document = $this->approvedPurchaseRequest();
        $submitter = $document->approval->submitter;
        $adminHo = $this->admin(UserRole::AdminHo);

        $this->actingAs($submitter)->post(route('admin.purchase-requests.reconcile', $document))->assertForbidden();
        $this->actingAs($adminHo)->post(route('admin.purchase-requests.reconcile', $document))->assertForbidden();
        $this->withoutVite()->actingAs($submitter)->get(route('documents.show', $document))
            ->assertOk()
            ->assertDontSee('Rekonsiliasi Purchase Request');
        $document->update(['company_db' => 'OTHER_DB']);
        $this->actingAs($this->admin())->post(route('admin.purchase-requests.reconcile', $document))->assertNotFound();

        $this->assertSame('POSTING_UNKNOWN', $document->refresh()->integration_status->value);
        $this->assertCount(0, $this->lookups);
    }

    /** @param array<string, mixed> $behaviour */
    private function fakeSap(array $behaviour = []): void
    {
        $this->sap = $behaviour;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_ends_with($url, '/Login')) {
                return Http::response(['SessionId' => 'session-1'], $this->sap['login'] ?? 200);
            }

            if (str_ends_with($url, '/$metadata')) {
                return Http::response($this->metadata($this->sap['metadataHasField'] ?? true));
            }

            // Usage history on submission, and the quotation lookup of a manual progress check.
            if (str_contains($url, '/SQLQueries') || str_contains($url, '/$crossjoin(PurchaseQuotations')) {
                return Http::response(['value' => []]);
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/PurchaseRequests')) {
                $this->posts[] = $request;

                return $this->fakePost($request);
            }

            if ($this->isLookup($request)) {
                $this->lookups[] = $request;

                return $this->fakeLookup($request);
            }

            if (preg_match('#/PurchaseRequests\((\d+)\)$#', $url, $match) === 1) {
                return Http::response($this->sapDocumentDetail((int) $match[1]), $this->sap['detailStatus'] ?? 200);
            }

            return null;
        });
    }

    private function fakePost(Request $request): mixed
    {
        $this->sap['created'] = $request->data();

        return match ($this->sap['post'] ?? 'success') {
            '502' => Http::response('Bad Gateway', 502),
            '408' => Http::response('Request Timeout', 408),
            'connection' => throw new ConnectionException('Operation timed out.'),
            'no-doc-entry' => Http::response([], 201),
            'matched-by-another-worker-then-502' => $this->matchWhilePostIsRunning(),
            default => Http::response(['DocEntry' => 501, 'DocNum' => 260000501], 201),
        };
    }

    /** The POST is still running when a stale-POSTING reconciliation records the request; then the POST fails. */
    private function matchWhilePostIsRunning(): mixed
    {
        Carbon::setTestNow(now()->addSeconds(301));
        $this->assertSame(Outcome::Reconciled, app(ReconcilePurchaseRequest::class)->handle(SapCommand::query()->sole()));

        return Http::response('Gateway Timeout', 504);
    }

    private function fakeLookup(Request $request): mixed
    {
        $lookup = $this->sap['lookup'] ?? 200;

        if ($lookup === 'connection') {
            throw new ConnectionException('Operation timed out.');
        }

        if (isset($this->sap['lookupBody'])) {
            return Http::response($this->sap['lookupBody']);
        }

        preg_match("/eq '([^']+)'/", (string) $request->data()['$filter'], $match);
        $rows = collect(range(1, max(1, $this->sap['matches'] ?? 1)))
            ->take($this->sap['matches'] ?? 1)
            ->map(fn (int $index): array => ['DocEntry' => 500 + $index, 'DocNum' => 260000500 + $index, self::UUID_FIELD => $match[1]])
            ->values()
            ->all();

        return Http::response(['value' => $rows], $lookup);
    }

    /** @return array<string, mixed> the request SAP holds, built from what the portal posted */
    private function sapDocumentDetail(int $docEntry): array
    {
        $created = $this->sap['created'];
        $lines = array_map(
            fn (array $line): array => [...$line, ...($this->sap['line'] ?? [])],
            $created['DocumentLines'],
        );

        return [
            'DocEntry' => $docEntry,
            'DocNum' => 260000000 + $docEntry,
            'DocDate' => $created['DocDate'].'T00:00:00Z',
            'Cancelled' => 'tNO',
            'DocumentStatus' => 'bost_Open',
            self::UUID_FIELD => $created[self::UUID_FIELD] ?? null,
            'DocumentLines' => $lines,
            ...($this->sap['detail'] ?? []),
        ];
    }

    private function metadata(bool $hasField): string
    {
        $property = $hasField ? '<Property Name="'.self::UUID_FIELD.'" Type="Edm.String" />' : '';

        return '<Schema><EntityType Name="Document">'.$property.'</EntityType><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="PurchaseRequests" /></EntityContainer></Schema>';
    }

    private function isLookup(Request $request): bool
    {
        return $request->method() === 'GET' && str_contains($request->url(), '/PurchaseRequests?');
    }

    private function admin(UserRole $role = UserRole::SuperAdmin): User
    {
        $admin = User::factory()->create(['role' => $role]);
        $this->profile($admin);

        return $admin;
    }

    private function reconcileAsAdmin(SapDocument $document): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('admin.purchase-requests.reconcile', $document));
    }

    /** Submits a Purchase Request and gives the final approval, which posts it to the fake SAP. */
    private function approvedPurchaseRequest(): SapDocument
    {
        $submitter = User::factory()->create();
        $this->profile($submitter);
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'TEST_DB',
            'submitter_id' => $submitter->id,
            'pool_id' => $submitter->operationalProfile->pool_id,
            'document_type' => 'PURCHASE_REQUEST',
            'name' => 'Workflow Test',
            'is_active' => true,
        ]);
        $approver = User::factory()->create();
        $workflow->steps()->create(['approver_id' => $approver->id, 'step_order' => 1, 'notification_email' => $approver->email]);
        $item = SapItemCache::query()->firstOrCreate(
            ['company_db' => 'TEST_DB', 'item_code' => 'I05358'],
            ['item_name' => 'Baterai GS NS 70', 'inventory_uom' => 'PCS', 'is_inventory_item' => false, 'is_purchase_item' => true, 'is_active' => true],
        );
        $transactionUuid = (string) Str::uuid();

        $this->actingAs($submitter)->post(route('purchase-requests.store'), [
            'transaction_uuid' => $transactionUuid,
            'document_date' => '2026-11-27',
            'required_date' => '2026-11-27',
            'priority' => 'NORMAL',
            'lines' => [['item_id' => $item->id, 'quantity' => 2, 'ending_km' => 125000]],
        ])->assertSessionHasNoErrors();
        $document = SapDocument::query()->with('approval.steps')->where('transaction_uuid', $transactionUuid)->sole();
        $this->post($document->approval->steps->sole()->approvalUrl(), ['decision' => 'APPROVE'])->assertRedirect();

        return $document->fresh(['approval.submitter']);
    }

    private function profile(User $user): void
    {
        $pool = ControlPool::query()->firstOrCreate(['code' => 'DUMAI'], ['name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->firstOrCreate(
            ['pool_id' => $pool->id, 'code' => 'TSP'],
            ['external_id' => 'dept-tsp', 'name' => 'Transport', 'is_active' => true],
        );

        foreach ([[1, 'TSP', 'Transport'], [2, 'DUMAI', 'Dumai']] as [$dimension, $code, $name]) {
            SapCostCenterCache::query()->firstOrCreate(
                ['company_db' => 'TEST_DB', 'dimension' => $dimension, 'center_code' => $code],
                ['center_name' => $name, 'is_active' => true],
            );
        }

        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);
    }
}
