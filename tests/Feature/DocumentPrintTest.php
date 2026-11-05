<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\DocumentApproval;
use App\Models\SapDocument;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentPrintTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
    }

    #[DataProvider('printableDocumentTypes')]
    public function test_user_can_open_print_layout_for_operational_document(string $documentType, string $title): void
    {
        [$user, $pool, $department] = $this->operationalUser('DUMAI');
        $document = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => $documentType,
            'sap_entity' => match ($documentType) {
                'GOODS_ISSUE' => 'InventoryGenExits',
                'PURCHASE_ORDER' => 'PurchaseOrders',
                default => 'PurchaseRequests',
            },
            'sap_doc_entry' => null,
            'sap_doc_num' => '260000777',
            'normalized_data' => [
                'warehouse_code' => 'DMI-01',
                'notes' => 'Kebutuhan operasional',
                'lines' => [[
                    'item_code' => 'I05358',
                    'item_name' => 'Baterai GS NS 70',
                    'vehicle_cost_center' => ['center_code' => 'BK8815FG'],
                    'quantity' => 2,
                    'uom' => 'PCS',
                ]],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('documents.print', $document));

        $response->assertOk()
            ->assertSee($title)
            ->assertSee('260000777')
            ->assertSee('I05358')
            ->assertSee('Baterai GS NS 70')
            ->assertSee('BK8815FG')
            ->assertDontSee('Nama &amp; Tanda Tangan', false)
            ->assertSee('window.print()', false);
    }

    public function test_print_route_rejects_unsupported_document_type(): void
    {
        [$user, $pool, $department] = $this->operationalUser('DUMAI');
        $paymentRequest = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PAYMENT_REQUEST',
            'sap_entity' => 'PaymentRequests',
            'sap_doc_entry' => null,
            'normalized_data' => ['warehouse_code' => 'DMI-01'],
        ]);

        $this->actingAs($user)->get(route('documents.print', $paymentRequest))->assertNotFound();
    }

    public function test_purchase_request_print_matches_the_reference_format_and_renders_digital_approval_stamps(): void
    {
        [$submitter, $pool, $department] = $this->operationalUser('DUMAI');
        $submitter->update(['job_title' => 'Admin Operasional Dumai']);
        $adminHoChecker = User::factory()->create([
            'job_title' => 'Supervisor Admin HO',
            'role' => UserRole::AdminHo,
        ]);
        $managerTransport = User::factory()->create([
            'job_title' => 'Manager Transport Area',
            'role' => UserRole::ManagerOperational,
        ]);
        $managerAdminHo = User::factory()->create([
            'job_title' => 'Manager Admin Operasional',
            'role' => UserRole::ManagerAdminHo,
        ]);
        $checkedAt = now()->startOfSecond()->subMinutes(30);

        $document = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_num' => '260002168',
            'admin_ho_checked_by' => $adminHoChecker->id,
            'admin_ho_checked_at' => $checkedAt,
            'normalized_data' => [
                'document_date' => '2026-11-03',
                'warehouse_code' => 'DMI-01',
                'ending_km' => 219099,
                'notes' => 'Jasa perbaikan unit operasional',
                'lines' => [[
                    'item_code' => 'I05380',
                    'item_name' => 'Jasa Perbaikan',
                    'vehicle_cost_center' => ['center_code' => 'BK8815FG'],
                    'quantity' => 1,
                    'manual_uom' => 'LOT',
                    'free_text' => 'Jasa perbaikan bumper belakang kanan',
                ]],
            ],
        ]);
        $decidedAt = now()->startOfSecond();
        $approval = DocumentApproval::query()->create([
            'sap_document_id' => $document->id,
            'approval_workflow_id' => null,
            'submitted_by' => $submitter->id,
            'status' => 'APPROVED',
            'current_step' => 2,
            'submitted_at' => $decidedAt->copy()->subHour(),
            'decided_at' => $decidedAt,
        ]);
        foreach ([1 => $managerTransport, 2 => $managerAdminHo] as $stepOrder => $stepApprover) {
            $approval->steps()->create([
                'approver_id' => $stepApprover->id,
                'approver_name' => $stepApprover->name,
                'notification_email' => $stepApprover->email,
                'step_order' => $stepOrder,
                'status' => 'APPROVED',
                'token' => (string) Str::uuid(),
                'expires_at' => now()->addDay(),
                'decided_at' => $decidedAt,
            ]);
        }

        $response = $this->actingAs($submitter)->get(route('documents.print', $document));
        $response->assertOk()
            ->assertSee('PT INDRA ANGKOLA')
            ->assertSee('Page 1 of 1')
            ->assertSee('size: A4 portrait', false)
            ->assertSee('grid-template-columns: repeat(4, minmax(0, 1fr))', false)
            ->assertSee('margin-left: -6px', false)
            ->assertSee('QTY REQ')
            ->assertSee('NOPOL')
            ->assertSee('BK8815FG')
            ->assertSee('219,099')
            ->assertSee('Requested by')
            ->assertSee('Checked by')
            ->assertSee('Approved by')
            ->assertSee('Verified by')
            ->assertSee('logo-indra-angkola.jpg')
            ->assertDontSee('OPERATIONAL PORTAL')
            ->assertSee($adminHoChecker->name)
            ->assertSee($managerTransport->name)
            ->assertSee($managerAdminHo->name)
            ->assertSee('Admin Operasional Dumai')
            ->assertSee('Supervisor Admin HO')
            ->assertSee('Manager Transport Area')
            ->assertSee('Manager Admin Operasional')
            ->assertSee('[Signature Name & Date]')
            ->assertSee('CHECKED')
            ->assertSee('APPROVED')
            ->assertSee('data-document-qr="'.$document->id.'"', false)
            ->assertSee('data-authorization-status="CHECKED"', false)
            ->assertSee('data-authorization-status="APPROVED"', false)
            ->assertSee('data-ecc="M"', false)
            ->assertDontSee('verification-qr-logo')
            ->assertSee('QR verifikasi checked dokumen Portal #'.$document->id)
            ->assertSee('QR verifikasi approved dokumen Portal #'.$document->id)
            ->assertSee('PR disetujui pada '.$decidedAt->format('d/m/Y H:i'))
            ->assertDontSee('Riwayat Approval');
        $this->assertSame(2, substr_count($response->getContent(), 'data-document-qr='));
    }

    public function test_print_route_forbids_document_from_another_pool(): void
    {
        [$user] = $this->operationalUser('DUMAI');
        [, $otherPool, $otherDepartment] = $this->operationalUser('MEDAN');
        $document = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $otherPool->id,
            'department_id' => $otherDepartment->id,
            'sap_doc_entry' => null,
            'normalized_data' => ['warehouse_code' => 'MDN-01'],
        ]);

        $this->actingAs($user)->get(route('documents.print', $document))->assertForbidden();
    }

    public function test_print_route_hides_document_from_another_company(): void
    {
        [$user, $pool, $department] = $this->operationalUser('DUMAI');
        $document = SapDocument::factory()->create([
            'company_db' => 'SECOND_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'sap_doc_entry' => null,
        ]);

        $this->actingAs($user)->get(route('documents.print', $document))->assertNotFound();
    }

    /** @return array<string, array{string, string}> */
    public static function printableDocumentTypes(): array
    {
        return [
            'purchase request' => ['PURCHASE_REQUEST', 'Purchase Request'],
            'goods issue' => ['GOODS_ISSUE', 'Goods Issue'],
            'goods receipt po' => ['PURCHASE_ORDER', 'Form Goods Receipt PO'],
        ];
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalUser(string $poolCode): array
    {
        $pool = ControlPool::query()->create(['code' => $poolCode, 'name' => $poolCode, 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.strtolower($poolCode),
            'code' => 'TSP-'.$poolCode,
            'name' => 'Transport '.$poolCode,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => UserRole::AdminOperational]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => $poolCode === 'DUMAI' ? 'DMI-01' : 'MDN-01',
        ]);

        return [$user, $pool, $department];
    }
}
