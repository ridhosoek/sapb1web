<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentRequestNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_payment_request_with_addon_fields_and_business_flow(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('payment-requests.create'));

        $response->assertOk()
            ->assertSee('Payment Request')
            ->assertSee('Business Flow yang Dikendalikan')
            ->assertSee('VENDOR', false)
            ->assertSee('APDP')
            ->assertSee('BANK TRANSFER')
            ->assertSee('Payment on Account')
            ->assertSee('Execute');
    }

    public function test_admin_can_open_approval_queue_with_addon_filters(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('payment-approvals.index'));

        $response->assertOk()
            ->assertSee('List Approval Payment')
            ->assertSee('Payment Means')
            ->assertSee('Segregation of Duties')
            ->assertSee('@VIT_HISTAPP');
    }

    public function test_payment_request_source_invoices_are_scoped_to_selected_company_database(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);
        SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'document_type' => 'AP_INVOICE',
            'sap_entity' => 'PurchaseInvoices',
            'sap_doc_entry' => 101,
            'sap_doc_num' => '260000101',
            'sap_business_status' => 'OPEN',
            'integration_status' => 'IN_SYNC',
            'normalized_data' => ['vendor_name' => 'Vendor Database Aktif', 'balance' => 100000],
        ]);
        SapDocument::query()->create([
            'company_db' => 'SECOND_DB',
            'document_type' => 'AP_INVOICE',
            'sap_entity' => 'PurchaseInvoices',
            'sap_doc_entry' => 102,
            'sap_doc_num' => '260000102',
            'sap_business_status' => 'OPEN',
            'integration_status' => 'IN_SYNC',
            'normalized_data' => ['vendor_name' => 'Vendor Database Lain', 'balance' => 200000],
        ]);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->withSession(['sap_company_db' => 'TEST_DB'])
            ->get(route('payment-requests.create'));

        $response->assertSee('Vendor Database Aktif')
            ->assertDontSee('Vendor Database Lain');
    }

    public function test_non_admin_cannot_open_payment_pages(): void
    {
        $poolUser = $this->operationalUser(UserRole::AdminOperational);

        $this->actingAs($poolUser)->get(route('payment-requests.index'))->assertForbidden();
        $this->actingAs($poolUser)->get(route('payment-requests.create'))->assertForbidden();
        $this->actingAs($poolUser)->get(route('payment-approvals.index'))->assertForbidden();
    }

    private function operationalUser(UserRole $role): User
    {
        $suffix = strtolower($role->value).'-'.fake()->unique()->numerify('###');
        $pool = ControlPool::query()->create([
            'code' => 'POOL-'.$suffix,
            'name' => 'Pool '.$suffix,
            'is_active' => true,
        ]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.$suffix,
            'code' => 'FIN-'.$suffix,
            'name' => 'Finance '.$suffix,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        return $user;
    }
}
