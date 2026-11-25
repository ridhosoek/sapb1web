<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapSemanticLayerClient;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\DocumentApprovalStep;
use App\Models\IssueType;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BP-12 / TD-13. The SAP serial source is a fake that follows the contract in docs/integration-contracts.md; these tests
 * prove the portal's behaviour, not that SAP provides the source.
 */
class GoodsIssueSerialSelectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SERIAL_URL = 'https://sap.test/b1s/v1/sml.svc/SERIALNUMBERBANQUERY*';

    /** @var list<array<string, mixed>> serials in stock according to the fake SAP */
    private array $sapSerials = [];

    private bool $serialSourceDown = false;

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
        config()->set('sap.goods_issue.serial_selection.enabled', true);
        config()->set('sap.goods_issue.serial_selection.entity', 'SERIALNUMBERBANQUERY');
        config()->set('sap.goods_issue.serial_selection.semantic_base_url', 'https://sap.test/b1s/v1/sml.svc');
        config()->set('sap.goods_issue.serial_selection.view_username', 'IT01');
        config()->set('sap.goods_issue.serial_selection.view_password', 'serial-secret');
        $this->sapSerials = [
            $this->serialRow('I-BAN-SN', 'DMI-01', 101, 'SN-A-101', 'MFR-101'),
            $this->serialRow('I-BAN-SN', 'DMI-01', 102, 'SN-A-102', 'MFR-102'),
            $this->serialRow('I-BAN-SN', 'DMI-01', 103, 'SN-A-103', null),
            $this->serialRow('I-BAN-SN', 'MDN-01', 201, 'SN-OTHER-WAREHOUSE', null),
            $this->serialRow('I-BAN-SN', 'DMI-01', 104, 'SN-SOLD', null, quantity: 0),
            $this->serialRow('I-BAN-SN', 'DMI-01', null, 'SN-NO-SYSTEM-NUMBER', null),
            $this->serialRow('I-BAN-SN', 'DMI-01', 105, null, null),
            $this->serialRow('I-OLI-SN', 'DMI-01', 301, 'SN-OLI-301', null),
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_lookup_offers_only_complete_serials_in_stock_for_the_profile_warehouse(): void
    {
        [$user, , $items] = $this->context();
        $this->sapSerials[0]['SysNumber'] = '101';
        $this->sapSerials[0]['AbsEntry'] = '5101';
        $this->fakeSap();

        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id]))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.system_number', 101)
            ->assertJsonPath('data.0.label', 'SN-A-101 / MFR-101')
            ->assertJsonPath('data.2.label', 'SN-A-103')
            ->assertJsonPath('meta.warehouse_code', 'DMI-01')
            ->assertJsonPath('meta.incomplete', 2);
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), "WhsCode eq 'DMI-01'")
            && str_contains(urldecode($request->url()), "ItemCode eq 'I-BAN-SN'")
            && str_contains(urldecode($request->url()), 'Quantity gt 0')
            && $request->hasHeader('Cookie', 'B1SESSION=session-serial'));
    }

    public function test_the_lookup_uses_the_warehouse_of_the_profile_and_ignores_one_sent_by_the_browser(): void
    {
        [$user, , $items] = $this->context(warehouse: 'MDN-01');
        $this->fakeSap();

        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id, 'warehouse_code' => 'DMI-01']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'SN-OTHER-WAREHOUSE');
    }

    public function test_the_lookup_searches_the_view_before_limited_paging(): void
    {
        [$user, , $items] = $this->context();
        $this->sapSerials[] = $this->serialRow('I-BAN-SN', 'DMI-01', 106, 'SN-LATER-106', null);
        $this->fakeSap();

        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id, 'q' => 'SN-LATER']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.system_number', 106);

        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), "contains(DistNumber,'SN-LATER')"));
    }

    public function test_the_lookup_refuses_non_serial_items_other_companies_and_users_who_cannot_create_goods_issues(): void
    {
        [$user, , $items] = $this->context();
        $otherCompanyItem = SapItemCache::query()->create([
            'company_db' => 'OTHER_DB', 'item_code' => 'I-BAN-SN', 'item_name' => 'Ban', 'is_inventory_item' => true, 'is_active' => true,
            'source_payload' => ['ManageSerialNumbers' => 'tYES'],
        ]);
        $manager = User::factory()->create(['role' => UserRole::ManagerOperational]);
        UserOperationalProfile::query()->create(['user_id' => $manager->id, 'pool_id' => $user->operationalProfile->pool_id, 'department_id' => $user->operationalProfile->department_id, 'warehouse_code' => 'DMI-01']);
        $this->fakeSap();

        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-plain']->id]))->assertStatus(422);
        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $otherCompanyItem->id]))->assertStatus(422);
        $this->actingAs($manager)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id]))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_lookup_reports_an_unreadable_sap_and_stays_hidden_while_the_feature_is_off(): void
    {
        [$user, , $items] = $this->context();
        $this->serialSourceDown = true;
        $this->fakeSap();

        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id]))
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'tidak dapat dibaca'));

        config()->set('sap.goods_issue.serial_selection.enabled', false);
        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id]))->assertNotFound();
    }

    public function test_the_lookup_explains_that_the_sap_team_must_provide_the_source_when_it_is_not_configured(): void
    {
        [$user, , $items] = $this->context();
        config()->set('sap.goods_issue.serial_selection.entity', null);

        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id]))
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'tim SAP'));
    }

    public function test_the_serial_lookup_never_falls_back_to_the_transaction_account_when_it01_password_is_missing(): void
    {
        [$user, , $items] = $this->context();
        config()->set('sap.goods_issue.serial_selection.view_password', null);
        $this->fakeSap();

        $this->actingAs($user)->getJson(route('lookups.serials', ['item_id' => $items['ban-serial']->id]))
            ->assertServiceUnavailable();

        Http::assertNothingSent();
    }

    public function test_it01_serial_sessions_are_separate_for_each_company_database(): void
    {
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
        config()->set('sap.goods_issue.serial_selection.view_credentials_by_company.SECOND_DB', [
            'username' => 'IT01',
            'password' => 'second-secret',
        ]);
        $this->fakeSap();
        $client = app(SapSemanticLayerClient::class);
        $companies = app(SapCompanyContext::class);

        $client->getSerialStock(['$top' => 1]);
        $companies->select('SECOND_DB');
        $client->getSerialStock(['$top' => 1]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/Login')
            && $request['CompanyDB'] === 'SECOND_DB'
            && $request['UserName'] === 'IT01'
            && $request['Password'] === 'second-secret');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sml.svc/SERIALNUMBERBANQUERY')
            && $request->hasHeader('Cookie', 'B1SESSION=session-serial-second'));
    }

    public function test_the_item_lookup_tells_the_form_whether_an_item_needs_serials(): void
    {
        [$user, , $items] = $this->context();
        $this->fakeSap();

        $response = $this->actingAs($user)->getJson(route('lookups.items', ['context' => 'goods-issue']))->assertOk();
        $requirements = collect($response->json('data'))->pluck('serial_requirement', 'item_code');

        $this->assertSame('SERIAL_REQUIRED', $requirements['I-BAN-SN']);
        $this->assertSame('NON_SERIAL', $requirements['I-BAN-PLAIN']);
        $this->assertSame('SERIAL_REQUIRED', $requirements['I-OLI-SN']);
        $this->assertSame('UNDETERMINED', $requirements['I-UNKNOWN']);
        $this->assertArrayNotHasKey('source_payload', $response->json('data.0'));
    }

    public function test_serial_managed_items_use_the_picked_serials_whatever_their_group_or_issue_type(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $approver = $this->approvalWorkflow($user);
        $this->fakeSap();

        $this->submit($user, $vehicle, [
            $this->line($items['ban-serial'], $issueTypes['BAN-MT'], 2, [101, 102]),
            $this->line($items['oli-serial'], $issueTypes['SPR-MT'], 1, [301]),
        ])->assertSessionHasNoErrors();

        $document = SapDocument::query()->sole();
        $this->assertSame([101, 102], collect(data_get($document->normalized_data, 'lines.0.serial_selection.serials'))->pluck('system_number')->all());
        $this->assertSame('SN-A-101', data_get($document->normalized_data, 'lines.0.serial_selection.serials.0.internal_serial'));
        $this->assertSame([301], collect(data_get($document->normalized_data, 'lines.1.serial_selection.serials'))->pluck('system_number')->all());
        $this->assertNull(data_get($document->normalized_data, 'lines.0.serial_numbers'));

        $this->approve($approver)->assertRedirect();
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/InventoryGenExits')
            && $request['DocumentLines'][0]['SerialNumbers'] === [['SystemSerialNumber' => 101, 'Quantity' => 1], ['SystemSerialNumber' => 102, 'Quantity' => 1]]
            && $request['DocumentLines'][1]['SerialNumbers'] === [['SystemSerialNumber' => 301, 'Quantity' => 1]]);
        $this->assertSame('IN_SYNC', $document->refresh()->integration_status->value);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/InventoryGenExits')
            && $request->hasHeader('Cookie', 'B1SESSION=session-main'));
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), "SysNumber eq '101'"));
    }

    public function test_a_non_serial_ban_is_issued_by_quantity_without_serials_and_never_sends_serial_numbers(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $approver = $this->approvalWorkflow($user);
        $this->fakeSap();

        $this->submit($user, $vehicle, [$this->line($items['ban-plain'], $issueTypes['BAN-MT'], 3, [])])->assertSessionHasNoErrors();
        $this->approve($approver)->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/InventoryGenExits')
            && $request['DocumentLines'][0]['Quantity'] === 3.0
            && ! array_key_exists('SerialNumbers', $request['DocumentLines'][0]));
        $this->assertNull(data_get(SapDocument::query()->sole()->normalized_data, 'lines.0.serial_selection'));
    }

    public function test_free_text_serials_are_ignored_and_serials_sent_for_a_non_serial_item_are_refused(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $this->fakeSap();

        $this->submit($user, $vehicle, [$this->line($items['ban-plain'], $issueTypes['BAN-MT'], 1, [101])])
            ->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->submit($user, $vehicle, [[...$this->line($items['ban-serial'], $issueTypes['BAN-MT'], 2, []), 'serial_numbers' => "FAKE-1\nFAKE-2"]])
            ->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->assertSame(0, SapDocument::query()->count());
    }

    public function test_an_item_whose_serial_setting_is_unknown_is_held_with_guidance_for_the_sap_team(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $this->fakeSap();

        $this->submit($user, $vehicle, [$this->line($items['unknown'], $issueTypes['SPR-MT'], 1, [])])
            ->assertSessionHasErrors(['lines.0.serial_system_numbers' => 'Pengelolaan serial item I-UNKNOWN belum terbaca dari SAP. Pengajuan baris ini ditahan; minta tim SAP melengkapi data Item Master lalu muat ulang.']);
        $this->assertSame(0, SapDocument::query()->count());
    }

    public function test_quantity_and_serial_count_duplicates_and_availability_are_checked_by_the_server(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $this->fakeSap();
        $ban = $items['ban-serial'];
        $type = $issueTypes['BAN-MT'];

        $this->submit($user, $vehicle, [$this->line($ban, $type, 2, [101])])->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 1, [101, 102])])->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 1.5, [101])])->assertSessionHasErrors('lines.0.quantity');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 2, [101, 101])])->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 1, [101]), $this->line($ban, $type, 1, [101])])->assertSessionHasErrors('lines.1.serial_system_numbers');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 1, [201])])->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 1, [104])])->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 1, [301])])->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->submit($user, $vehicle, [$this->line($ban, $type, 1, [999])])->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->assertSame(0, SapDocument::query()->count());
    }

    public function test_a_submission_is_held_while_the_serial_source_cannot_be_read_and_nothing_is_saved(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $this->serialSourceDown = true;
        $this->fakeSap();

        $this->submit($user, $vehicle, [$this->line($items['ban-serial'], $issueTypes['BAN-MT'], 1, [101])])
            ->assertSessionHasErrors('lines.0.serial_system_numbers');
        $this->assertSame(0, SapDocument::query()->count());
    }

    public function test_a_backdated_fpk_date_still_maps_to_both_sap_dates_with_picked_serials(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $approver = $this->approvalWorkflow($user);
        $this->fakeSap();

        $this->submit($user, $vehicle, [$this->line($items['ban-serial'], $issueTypes['BAN-MT'], 1, [103])], '2025-01-01')->assertSessionHasNoErrors();
        $this->approve($approver)->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/InventoryGenExits')
            && $request['DocDate'] === '2025-01-01'
            && $request['TaxDate'] === '2025-01-01'
            && $request['DocumentLines'][0]['SerialNumbers'] === [['SystemSerialNumber' => 103, 'Quantity' => 1]]);
    }

    public function test_a_serial_that_is_gone_when_the_approval_is_posted_stops_the_post_and_is_not_replaced(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $approver = $this->approvalWorkflow($user);
        $this->fakeSap();
        $this->submit($user, $vehicle, [$this->line($items['ban-serial'], $issueTypes['BAN-MT'], 2, [101, 102])])->assertSessionHasNoErrors();
        $this->sapSerials = collect($this->sapSerials)->reject(fn (array $row): bool => $row['SysNumber'] === 102)->values()->all();

        $this->approve($approver)->assertSessionHasErrors();

        $this->assertSame(0, collect(Http::recorded())->filter(fn (array $exchange): bool => $exchange[0]->method() === 'POST' && str_ends_with($exchange[0]->url(), '/InventoryGenExits'))->count());
        $document = SapDocument::query()->sole();
        $this->assertSame('SAP_ERROR', $document->integration_status->value);
        $this->assertSame([101, 102], collect(data_get($document->normalized_data, 'lines.0.serial_selection.serials'))->pluck('system_number')->all());
        $audit = AuditLog::query()->where('event', 'goods_issue.serial_selection_stale')->sole();
        $this->assertStringContainsString('SN-A-102', $audit->metadata['reason']);
        $this->assertStringContainsString('tidak diganti otomatis', $audit->metadata['reason']);
    }

    public function test_a_serial_whose_identity_changed_or_that_lost_its_stock_stops_the_post(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $approver = $this->approvalWorkflow($user);
        $this->fakeSap();
        $this->submit($user, $vehicle, [$this->line($items['ban-serial'], $issueTypes['BAN-MT'], 2, [101, 103])])->assertSessionHasNoErrors();
        $this->sapSerials = collect($this->sapSerials)->map(function (array $row): array {
            if ($row['SysNumber'] === 101) {
                $row['DistNumber'] = 'SN-RENAMED';
            }

            if ($row['SysNumber'] === 103) {
                $row['Quantity'] = 0;
            }

            return $row;
        })->all();

        $this->approve($approver)->assertSessionHasErrors();

        $reason = AuditLog::query()->where('event', 'goods_issue.serial_selection_stale')->sole()->metadata['reason'];
        $this->assertStringContainsString('identitas serial SN-A-101 / MFR-101 berubah', $reason);
        $this->assertStringContainsString('serial SN-A-103 sudah tidak tersedia', $reason);
        $this->assertSame('SAP_ERROR', SapDocument::query()->sole()->integration_status->value);
    }

    public function test_an_unreadable_sap_at_approval_stops_this_attempt_without_a_post_and_keeps_the_document_pending(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $approver = $this->approvalWorkflow($user);
        $this->fakeSap();
        $this->submit($user, $vehicle, [$this->line($items['ban-serial'], $issueTypes['BAN-MT'], 1, [101])])->assertSessionHasNoErrors();
        $this->serialSourceDown = true;

        $this->approve($approver)->assertSessionHasErrors();

        $this->assertSame(0, collect(Http::recorded())->filter(fn (array $exchange): bool => $exchange[0]->method() === 'POST' && str_ends_with($exchange[0]->url(), '/InventoryGenExits'))->count());
        $this->assertSame('PENDING_POST', SapDocument::query()->sole()->integration_status->value);
        $this->assertSame(0, AuditLog::query()->where('event', 'goods_issue.serial_selection_stale')->count());
    }

    public function test_a_connection_lost_while_posting_is_still_reported_as_unknown_and_not_retried(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $approver = $this->approvalWorkflow($user);
        $this->fakeSap(goodsIssueResponse: fn () => throw new ConnectionException('timeout'));
        $this->submit($user, $vehicle, [$this->line($items['ban-serial'], $issueTypes['BAN-MT'], 1, [101])])->assertSessionHasNoErrors();

        $this->approve($approver)->assertRedirect();

        $this->assertSame('POSTING_UNKNOWN', SapDocument::query()->sole()->integration_status->value);
    }

    public function test_the_legacy_free_text_flow_is_unchanged_while_the_feature_is_off(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        config()->set('sap.goods_issue.serial_selection.enabled', false);
        config()->set('sap.goods_issue.serial_issue_type_codes', ['BAN-MT']);
        $this->approvalWorkflow($user);
        $this->fakeSap();

        $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK0000009',
            'fpk_date' => '2026-10-21',
            'complaint' => 'x',
            'damage_analysis' => 'y',
            'lines' => [['item_id' => $items['ban-plain']->id, 'vehicle_cost_center_id' => $vehicle->id, 'issue_type_id' => $issueTypes['BAN-MT']->id, 'requested_quantity' => 1, 'quantity' => 1, 'ending_km' => 1, 'serial_numbers' => 'SN-LEGACY-1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['SN-LEGACY-1'], SapDocument::query()->sole()->normalized_data['lines'][0]['serial_numbers']);
    }

    public function test_the_form_shows_the_serial_picker_only_while_the_feature_is_on(): void
    {
        [$user] = $this->context();

        $this->withoutVite()->actingAs($user)->get(route('goods-issues.create'))
            ->assertOk()
            ->assertSee('data-serial-cell', false)
            ->assertSee('data-serial-dialog', false)
            ->assertSee('data-serial-url="'.route('lookups.serials').'"', false)
            ->assertDontSee('name="lines[0][serial_numbers]"', false);

        config()->set('sap.goods_issue.serial_selection.enabled', false);
        $this->withoutVite()->actingAs($user)->get(route('goods-issues.create'))
            ->assertOk()
            ->assertDontSee('data-serial-dialog', false)
            ->assertSee('name="lines[0][serial_numbers]"', false);
    }

    public function test_a_non_serial_item_known_only_from_the_stock_sync_is_read_from_the_item_master_and_not_held(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $this->approvalWorkflow($user);
        $this->fakeSap(itemMaster: [['ItemCode' => 'I-UNKNOWN', 'ItemName' => 'Stiker', 'InventoryUOM' => 'PCS', 'InventoryItem' => 'tYES', 'PurchaseItem' => 'tYES', 'ManageSerialNumbers' => 'tNO', 'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction', 'Frozen' => 'tNO']]);

        $listed = $this->actingAs($user)->getJson(route('lookups.items', ['context' => 'goods-issue']))->assertOk()->json('data');
        $this->assertSame('NON_SERIAL', collect($listed)->firstWhere('item_code', 'I-UNKNOWN')['serial_requirement']);

        $this->submit($user, $vehicle, [$this->line($items['unknown'], $issueTypes['SPR-MT'], 1, [])])->assertSessionHasNoErrors();
        $this->assertSame(1, SapDocument::query()->count());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), '/Items?')
            && str_contains(urldecode($request->url()), "ItemCode eq 'I-UNKNOWN'")
            && ! str_contains(urldecode($request->url()), 'I-BAN-SN'));
    }

    public function test_the_server_reads_the_item_master_even_when_the_item_was_not_listed_first(): void
    {
        [$user, $vehicle, $items, $issueTypes] = $this->context();
        $this->approvalWorkflow($user);
        $this->fakeSap(itemMaster: [['ItemCode' => 'I-UNKNOWN', 'ItemName' => 'Aki', 'InventoryItem' => 'tYES', 'ManageSerialNumbers' => 'tYES', 'Frozen' => 'tNO']]);

        $this->submit($user, $vehicle, [$this->line($items['unknown'], $issueTypes['SPR-MT'], 1, [])])
            ->assertSessionHasErrors(['lines.0.serial_system_numbers' => 'Pilih tepat 1 serial untuk I-UNKNOWN (terpilih 0).']);
        $this->assertSame(0, SapDocument::query()->count());
    }

    /**
     * @return array{User, SapCostCenterCache, array<string, SapItemCache>, array<string, IssueType>}
     */
    private function context(string $warehouse = 'DMI-01'): array
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create(['pool_id' => $pool->id, 'external_id' => 'dept-tsp', 'code' => 'TSP', 'name' => 'Transport', 'is_active' => true]);
        $user = User::factory()->create(['role' => UserRole::AdminOperational]);
        UserOperationalProfile::query()->create(['user_id' => $user->id, 'pool_id' => $pool->id, 'department_id' => $department->id, 'warehouse_code' => $warehouse]);
        SapCostCenterCache::query()->create(['company_db' => 'TEST_DB', 'center_code' => 'TSP', 'center_name' => 'Transport', 'dimension' => 1, 'is_active' => true]);
        SapCostCenterCache::query()->create(['company_db' => 'TEST_DB', 'center_code' => 'DMI', 'center_name' => 'Dumai', 'dimension' => 2, 'is_active' => true]);
        $vehicle = SapCostCenterCache::query()->create(['company_db' => 'TEST_DB', 'center_code' => 'BK8748ES', 'center_name' => 'Mobil Tangki', 'dimension' => 3, 'is_active' => true]);
        $items = [];

        foreach ([
            'ban-serial' => ['I-BAN-SN', 'Ban serial', 'BAN', ['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction']],
            'ban-plain' => ['I-BAN-PLAIN', 'Ban tanpa serial', 'BAN', ['ManageSerialNumbers' => 'tNO', 'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction']],
            'oli-serial' => ['I-OLI-SN', 'Aki serial', 'AKI', ['ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_OnReleaseOnly']],
            'unknown' => ['I-UNKNOWN', 'Item belum terbaca', 'LAIN', null],
        ] as $key => [$code, $name, $group, $payload]) {
            $items[$key] = SapItemCache::query()->create([
                'company_db' => 'TEST_DB', 'item_code' => $code, 'item_name' => $name, 'item_group_name' => $group, 'inventory_uom' => 'PCS',
                'is_inventory_item' => true, 'is_active' => true, 'source_payload' => $payload,
            ]);
            SapItemWarehouseStock::query()->create([
                'company_db' => 'TEST_DB', 'sap_item_cache_id' => $items[$key]->id, 'warehouse_code' => $warehouse,
                'in_stock' => 10, 'committed_stock' => 0, 'available_stock' => 10, 'ordered_stock' => 0, 'minimum_stock' => 0, 'is_locked' => false, 'synced_at' => now(),
            ]);
        }

        $issueTypes = [
            'BAN-MT' => IssueType::query()->updateOrCreate(['code' => 'BAN-MT'], ['name' => 'Ban Mobil Tangki', 'is_active' => true]),
            'SPR-MT' => IssueType::query()->updateOrCreate(['code' => 'SPR-MT'], ['name' => 'Sparepart Mobil Tangki', 'is_active' => true]),
        ];

        return [$user, $vehicle, $items, $issueTypes];
    }

    /** @return array<string, mixed> */
    private function line(SapItemCache $item, IssueType $issueType, float|int $quantity, array $serials): array
    {
        return [
            'item_id' => $item->id,
            'vehicle_cost_center_id' => SapCostCenterCache::query()->where('center_code', 'BK8748ES')->value('id'),
            'issue_type_id' => $issueType->id,
            'requested_quantity' => $quantity,
            'quantity' => $quantity,
            'ending_km' => 125000,
            'serial_system_numbers' => $serials,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function submit(User $user, SapCostCenterCache $vehicle, array $lines, string $fpkDate = '2026-10-21'): TestResponse
    {
        return $this->actingAs($user)->post(route('goods-issues.store'), [
            'transaction_uuid' => (string) Str::uuid(),
            'fpk_number' => 'FPK'.random_int(1000000, 9999999),
            'fpk_date' => $fpkDate,
            'complaint' => 'Ban aus',
            'damage_analysis' => 'Tread habis',
            'lines' => $lines,
        ]);
    }

    private function approve(User $approver): TestResponse
    {
        $step = DocumentApprovalStep::query()->latest('id')->firstOrFail();

        return $this->actingAs($approver)->post(route('goods-issue-approvals.decide', $step), ['decision' => 'APPROVE']);
    }

    /** @return array<string, mixed> */
    private function serialRow(string $item, string $warehouse, ?int $systemNumber, ?string $internal, ?string $manufacturer, int $quantity = 1): array
    {
        return ['ItemCode' => $item, 'WhsCode' => $warehouse, 'SysNumber' => $systemNumber, 'AbsEntry' => $systemNumber === null ? null : 5000 + $systemNumber, 'MnfSerial' => $manufacturer, 'DistNumber' => $internal, 'Quantity' => $quantity];
    }

    /** Fake SAP: the serial source follows the filter it is given; stock is always enough. */
    private function fakeSap(mixed $goodsIssueResponse = null, ?array $itemMaster = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => fn (Request $request) => Http::response(['SessionId' => $request['UserName'] === 'IT01'
                ? ($request['CompanyDB'] === 'SECOND_DB' ? 'session-serial-second' : 'session-serial')
                : 'session-main']),
            'https://sap.test/b1s/v2/$metadata' => Http::response('<Schema><EntityContainer><EntitySet EntityType="SAPB1.Document" Name="InventoryGenExits" /></EntityContainer></Schema>'),
            'https://sap.test/b1s/v2/$crossjoin*' => fn () => Http::response(['value' => collect(['I-BAN-SN', 'I-BAN-PLAIN', 'I-OLI-SN', 'I-UNKNOWN'])->map(fn (string $code): array => [
                'Items' => ['ItemCode' => $code, 'InventoryItem' => 'Y', 'Frozen' => 'N'],
                'Items/ItemWarehouseInfoCollection' => ['WarehouseCode' => 'DMI-01', 'InStock' => 10.0, 'Committed' => 0.0, 'Ordered' => 0.0, 'Locked' => 'N'],
            ])->all()]),
            // Without an Item Master answer the read fails, like an unreachable SAP.
            'https://sap.test/b1s/v2/Items*' => $itemMaster === null ? Http::response([], 500) : Http::response(['value' => $itemMaster]),
            'https://sap.test/b1s/v2/ItemGroups*' => Http::response(['value' => []]),
            self::SERIAL_URL => fn (Request $request) => $this->serialSourceDown
                ? Http::response(['error' => ['message' => ['value' => 'down']]], 500)
                : Http::response($this->serialPageFor($request->url())),
            'https://sap.test/b1s/v2/InventoryGenExits' => $goodsIssueResponse ?? Http::response(['DocEntry' => 131, 'DocNum' => 70000131], 201),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function serialRowsFor(string $url): array
    {
        preg_match("/ItemCode eq '([^']+)'/", $url, $item);
        preg_match("/WhsCode eq '([^']+)'/", $url, $warehouse);
        preg_match_all("/SysNumber eq '(\d+)'/", $url, $numbers);
        preg_match("/contains\(DistNumber,'((?:''|[^'])*)'\)/", $url, $search);
        $needle = str_replace("''", "'", $search[1] ?? '');

        return collect($this->sapSerials)
            ->filter(fn (array $row): bool => $row['ItemCode'] === ($item[1] ?? null)
                && $row['WhsCode'] === ($warehouse[1] ?? null)
                && (float) $row['Quantity'] > 0
                && ($needle === '' || str_contains((string) $row['DistNumber'], $needle) || str_contains((string) $row['MnfSerial'], $needle))
                && ($numbers[1] === [] || in_array((string) $row['SysNumber'], $numbers[1], true)))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialPageFor(string $url): array
    {
        $rows = $this->serialRowsFor(urldecode($url));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $offset = (int) ($query['$skip'] ?? 0);
        $pageSize = min(2, (int) ($query['$top'] ?? 50));
        $page = array_slice($rows, $offset, $pageSize);

        return [
            'value' => $page,
            '@odata.nextLink' => $offset + count($page) < count($rows) ? 'next-page' : null,
        ];
    }

    private function approvalWorkflow(User $submitter): User
    {
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'TEST_DB', 'submitter_id' => $submitter->id, 'pool_id' => $submitter->operationalProfile->pool_id,
            'document_type' => 'GOODS_ISSUE', 'name' => 'Approval Admin HO', 'is_active' => true,
        ]);
        $approver = User::factory()->create(['role' => UserRole::AdminHo]);
        UserOperationalProfile::query()->create([
            'user_id' => $approver->id, 'pool_id' => $submitter->operationalProfile->pool_id,
            'department_id' => $submitter->operationalProfile->department_id, 'warehouse_code' => $submitter->operationalProfile->warehouse_code,
        ]);
        $workflow->steps()->create(['approver_id' => $approver->id, 'step_order' => 1, 'notification_email' => $approver->email]);

        return $approver;
    }
}
