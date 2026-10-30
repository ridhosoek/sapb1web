<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
