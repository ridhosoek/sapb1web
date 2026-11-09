<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\DocumentApproval;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodsIssueAttachmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.documents.goods_issue_attachments.disk', 'local');
        Storage::fake('local');
    }

    public function test_submitter_can_upload_private_fpk_scan_to_goods_issue(): void
    {
        [$user, $document] = $this->goodsIssueContext();

        $response = $this->actingAs($user)->post(route('goods-issues.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('scan-fpk.jpg', 250, 'image/jpeg')],
            'caption' => 'FPK sudah ditandatangani',
        ]);

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('status');
        $attachment = SapDocumentAttachment::query()->sole();
        $this->assertSame('scan-fpk.jpg', $attachment->original_name);
        $this->assertSame('FPK sudah ditandatangani', $attachment->caption);
        $this->assertSame($document->id, $attachment->sap_document_id);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertModelExists(AuditLog::query()->where('event', 'goods_issue.fpk_scan_uploaded')->sole());

        $this->withoutVite()->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Foto Hardcopy FPK')
            ->assertSee('scan-fpk.jpg');
    }

    public function test_other_operational_user_cannot_upload_scan_to_someone_elses_goods_issue(): void
    {
        [$submitter, $document, $pool, $department] = $this->goodsIssueContext();
        $otherUser = User::factory()->create(['role' => UserRole::AdminOperational]);
        UserOperationalProfile::query()->create([
            'user_id' => $otherUser->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        $this->actingAs($otherUser)->post(route('goods-issues.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('fpk.jpg', 100, 'image/jpeg')],
        ])->assertForbidden();

        $this->assertNotSame($submitter->id, $otherUser->id);
        $this->assertSame(0, SapDocumentAttachment::query()->count());
    }

    public function test_goods_issue_attachment_rejects_pdf_and_photo_larger_than_one_megabyte(): void
    {
        [$user, $document] = $this->goodsIssueContext();

        $this->actingAs($user)
            ->from(route('documents.show', $document))
            ->post(route('goods-issues.attachments.store', $document), [
                'attachments' => [UploadedFile::fake()->create('scan-fpk.pdf', 250, 'application/pdf')],
            ])
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHasErrors('attachments.0');

        $this->actingAs($user)
            ->from(route('documents.show', $document))
            ->post(route('goods-issues.attachments.store', $document), [
                'attachments' => [UploadedFile::fake()->create('scan-fpk.png', 1025, 'image/png')],
            ])
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('sap_document_attachments', 0);
    }

    /** @return array{User, SapDocument, ControlPool, ControlDepartment} */
    private function goodsIssueContext(): array
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-tsp',
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
        $document = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'GOODS_ISSUE',
            'sap_entity' => 'InventoryGenExits',
            'normalized_data' => ['fpk_number' => 'FPK000001', 'warehouse_code' => 'DMI-01', 'lines' => []],
        ]);
        DocumentApproval::query()->create([
            'sap_document_id' => $document->id,
            'submitted_by' => $user->id,
            'status' => 'PENDING',
            'current_step' => 1,
            'submitted_at' => now(),
        ]);

        return [$user, $document, $pool, $department];
    }
}
