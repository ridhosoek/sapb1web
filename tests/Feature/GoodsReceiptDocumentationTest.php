<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodsReceiptDocumentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
        config()->set('sap.documents.goods_receipt_attachments.disk', 'local');
    }

    public function test_user_can_upload_private_goods_receipt_photos_without_calling_sap(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);

        $response = $this->actingAs($user)->post(route('ready-to-receive.attachments.store', $purchaseOrder), [
            'images' => [$this->fakeImage('surat-jalan.png')],
            'caption' => 'Barang diterima lengkap',
        ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHas('status', '1 foto penerimaan berhasil disimpan di portal.');
        $attachment = SapDocumentAttachment::query()->sole();
        $this->assertSame($purchaseOrder->id, $attachment->sap_document_id);
        $this->assertSame($user->id, $attachment->uploaded_by);
        $this->assertSame('surat-jalan.png', $attachment->original_name);
        $this->assertSame('Barang diterima lengkap', $attachment->caption);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertModelExists(AuditLog::query()->where('event', 'goods_receipt_po.documentation_uploaded')->sole());
        Http::assertNothingSent();
    }

    public function test_open_synced_purchase_order_is_listed_without_legacy_progress_marker(): void
    {
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = SapDocument::factory()->create([
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'sap_doc_num' => '260001614',
            'normalized_data' => [
                'document' => ['CardName' => 'Sekawan, CV'],
                'lines' => [[
                    'ItemCode' => 'I02042',
                    'ItemDescription' => 'Baterai Yuasa NS 70',
                    'Quantity' => 1,
                    'WarehouseCode' => 'DMI-01',
                ]],
            ],
        ]);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('ready-to-receive.index'))
            ->assertOk()
            ->assertSee($purchaseOrder->sap_doc_num)
            ->assertSee('Sekawan, CV')
            ->assertSee('Ready For Grpo');
    }

    public function test_uploaded_photo_is_visible_only_through_authorized_private_route(): void
    {
        Storage::fake('local');
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);
        $this->actingAs($user)->post(route('ready-to-receive.attachments.store', $purchaseOrder), [
            'images' => [$this->fakeImage('barang.png')],
        ]);
        $attachment = SapDocumentAttachment::query()->sole();

        $response = $this->actingAs($user)->get(route('document-attachments.show', $attachment));

        $response
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=3600, private')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_upload_rejects_non_image_file(): void
    {
        Storage::fake('local');
        [$user, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseOrder = $this->purchaseOrder($pool, $department);

        $response = $this->from(route('ready-to-receive.show', $purchaseOrder))
            ->actingAs($user)
            ->post(route('ready-to-receive.attachments.store', $purchaseOrder), [
                'images' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
            ]);

        $response
            ->assertRedirect(route('ready-to-receive.show', $purchaseOrder))
            ->assertSessionHasErrors('images.0');
        $this->assertSame(0, SapDocumentAttachment::query()->count());
    }

    public function test_goods_receipt_documentation_does_not_leak_other_company_or_pool(): void
    {
        [$user] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        [, $otherPool, $otherDepartment] = $this->operationalUser(UserRole::AdminOperational, 'MEDAN');
        $otherPoolPurchaseOrder = $this->purchaseOrder($otherPool, $otherDepartment);
        $otherCompanyPurchaseOrder = $this->purchaseOrder($otherPool, $otherDepartment, 'SECOND_DB');

        $this->withoutVite()->actingAs($user)
            ->get(route('ready-to-receive.show', $otherPoolPurchaseOrder))
            ->assertForbidden();
        $this->withoutVite()->actingAs($user)
            ->get(route('ready-to-receive.show', $otherCompanyPurchaseOrder))
            ->assertNotFound();
    }

    public function test_private_attachment_does_not_leak_other_company_or_pool(): void
    {
        [$user] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        [, $otherPool, $otherDepartment] = $this->operationalUser(UserRole::AdminOperational, 'MEDAN');
        $otherPoolAttachment = SapDocumentAttachment::factory()->create([
            'sap_document_id' => $this->purchaseOrder($otherPool, $otherDepartment)->id,
            'uploaded_by' => null,
        ]);
        $otherCompanyAttachment = SapDocumentAttachment::factory()->create([
            'sap_document_id' => $this->purchaseOrder($otherPool, $otherDepartment, 'SECOND_DB')->id,
            'uploaded_by' => null,
        ]);

        $this->actingAs($user)
            ->get(route('document-attachments.show', $otherPoolAttachment))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('document-attachments.show', $otherCompanyAttachment))
            ->assertNotFound();
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalUser(UserRole $role, string $poolCode): array
    {
        $pool = ControlPool::query()->create(['code' => $poolCode, 'name' => $poolCode, 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.strtolower($poolCode),
            'code' => 'TSP-'.$poolCode,
            'name' => 'Transport '.$poolCode,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => $poolCode.'-01',
        ]);

        return [$user, $pool, $department];
    }

    private function purchaseOrder(ControlPool $pool, ControlDepartment $department, string $companyDb = 'TEST_DB'): SapDocument
    {
        return SapDocument::factory()->create([
            'company_db' => $companyDb,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'sap_doc_entry' => null,
            'sap_doc_num' => '260002001',
            'normalized_data' => [
                'progress_status' => 'READY_FOR_GRPO',
                'vendor_name' => 'PT Sparepart',
                'lines' => [[
                    'ItemCode' => 'I05358',
                    'ItemDescription' => 'Baterai GS NS 70',
                    'Quantity' => 2,
                    'WarehouseCode' => 'DMI-01',
                ]],
            ],
        ]);
    }

    private function fakeImage(string $name): UploadedFile
    {
        $onePixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $onePixelPng ?: '');
    }
}
