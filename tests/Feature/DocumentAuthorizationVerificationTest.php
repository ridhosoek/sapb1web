<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\DocumentApproval;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentAuthorizationVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_signed_link_shows_checked_by_information(): void
    {
        $checker = User::factory()->create([
            'name' => 'Admin HO Dumai',
            'job_title' => 'Supervisor Administrasi HO',
            'role' => UserRole::AdminHo,
        ]);
        $document = SapDocument::factory()->create([
            'sap_doc_num' => '260002168',
            'admin_ho_checked_by' => $checker->id,
            'admin_ho_checked_at' => '2026-11-03 13:30:00',
            'normalized_data' => [
                'document_date' => '2026-11-03',
                'warehouse_code' => 'DMI-01',
                'lines' => [],
            ],
        ]);

        $url = URL::signedRoute('document-authorizations.show', [
            'document' => $document,
            'authorization' => 'checked',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Tautan valid')
            ->assertSee('CHECKED')
            ->assertSee('Admin HO Dumai')
            ->assertSee('Supervisor Administrasi HO')
            ->assertSee('03/11/2026 13:30')
            ->assertSee('260002168');
    }

    public function test_signed_link_shows_approved_by_information(): void
    {
        $manager = User::factory()->create([
            'name' => 'Manager Transport',
            'job_title' => 'Region Manager Transport',
            'role' => UserRole::ManagerOperational,
        ]);
        $document = SapDocument::factory()->create([
            'sap_doc_num' => '260002169',
            'normalized_data' => [
                'document_date' => '2026-11-04',
                'warehouse_code' => 'DMI-01',
                'lines' => [],
            ],
        ]);
        $approval = DocumentApproval::query()->create([
            'sap_document_id' => $document->id,
            'approval_workflow_id' => null,
            'submitted_by' => $manager->id,
            'status' => 'APPROVED',
            'current_step' => 1,
            'submitted_at' => now()->subHour(),
            'decided_at' => '2026-11-04 09:15:00',
        ]);
        $approval->steps()->create([
            'approver_id' => $manager->id,
            'approver_name' => $manager->name,
            'notification_email' => $manager->email,
            'step_order' => 1,
            'status' => 'APPROVED',
            'token' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
            'decided_at' => '2026-11-04 09:15:00',
        ]);

        $url = URL::signedRoute('document-authorizations.show', [
            'document' => $document,
            'authorization' => 'approved',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('APPROVED')
            ->assertSee('Manager Transport')
            ->assertSee('Region Manager Transport')
            ->assertSee('04/11/2026 09:15')
            ->assertSee('260002169');
    }

    public function test_verification_link_rejects_a_tampered_authorization_status(): void
    {
        $document = SapDocument::factory()->create();
        $approvedUrl = URL::signedRoute('document-authorizations.show', [
            'document' => $document,
            'authorization' => 'approved',
        ]);
        $tamperedUrl = str_replace('/approved?', '/checked?', $approvedUrl);

        $this->get($tamperedUrl)->assertForbidden();
    }
}
