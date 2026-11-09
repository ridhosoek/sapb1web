<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ItemMasterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_item_master_requires_a_search_before_loading_items(): void
    {
        $user = $this->operationalUser();
        SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00084',
            'item_name' => 'Ban Baru Bridgestone',
            'is_active' => true,
        ]);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('item-master.index'))
            ->assertOk()
            ->assertSee('Masukkan Item Code atau nama item')
            ->assertDontSee('Ban Baru Bridgestone');
    }

    public function test_item_master_search_is_scoped_to_the_selected_company(): void
    {
        $user = $this->operationalUser();
        SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00084',
            'item_name' => 'Ban Baru Bridgestone',
            'item_group_code' => 200,
            'item_group_name' => 'BAN',
            'manages_serial_numbers' => true,
            'serial_management_method' => 'bomm_OnEveryTransaction',
            'is_active' => true,
        ]);
        SapItemCache::query()->create([
            'company_db' => 'SECOND_DB',
            'item_code' => 'I00085',
            'item_name' => 'Ban Database Lain',
            'is_active' => true,
        ]);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('item-master.index', ['q' => 'Ban']))
            ->assertOk()
            ->assertSee('I00084')
            ->assertSee('Ban Baru Bridgestone')
            ->assertSee('BAN')
            ->assertDontSee('I00085');
    }

    public function test_item_master_detail_shows_serial_settings_and_warehouse_stock(): void
    {
        $user = $this->operationalUser();
        $item = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00084',
            'item_name' => 'Ban Baru Bridgestone',
            'inventory_uom' => 'PCS',
            'item_group_code' => 200,
            'item_group_name' => 'BAN',
            'is_inventory_item' => true,
            'is_purchase_item' => true,
            'manages_serial_numbers' => true,
            'serial_management_method' => 'bomm_OnEveryTransaction',
            'is_active' => true,
            'source_payload' => ['ItemsGroupCode' => 200, 'ManageSerialNumbers' => 'tYES', 'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction'],
        ]);
        SapItemWarehouseStock::query()->create([
            'company_db' => 'TEST_DB',
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => 'DMI-01',
            'in_stock' => 4,
            'committed_stock' => 1,
            'available_stock' => 3,
            'ordered_stock' => 2,
            'minimum_stock' => 1,
            'is_locked' => false,
        ]);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('item-master.show', $item))
            ->assertOk()
            ->assertSee('Ban Baru Bridgestone')
            ->assertSee('bomm_OnEveryTransaction')
            ->assertSee('Serial wajib saat GRPO')
            ->assertSee('DMI-01');
    }

    /** @param  array<string, mixed>|null  $payload */
    #[DataProvider('serialSettingsOnItemMaster')]
    public function test_item_master_reports_serial_settings_from_sap_without_guessing_unknown_values(
        ?array $payload,
        string $groupName,
        string $manageLabel,
        string $receiptText,
        string $metadataLabel,
        string $listLabel,
    ): void {
        $user = $this->operationalUser();
        $item = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00090',
            'item_name' => 'Selendang Ban',
            'item_group_name' => $groupName,
            'manages_serial_numbers' => false,
            'is_active' => true,
            'source_payload' => $payload,
        ]);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('item-master.show', $item))
            ->assertOk()
            ->assertSee('<th>Manage Serial Numbers</th><td>'.$manageLabel.'</td>', false)
            ->assertSee('<th>Serial wajib saat GRPO</th><td>'.$receiptText.'</td>', false)
            ->assertSee('<th>Status Metadata</th><td>'.$metadataLabel.'</td>', false);
        $this->withoutVite()
            ->actingAs($user)
            ->get(route('item-master.index', ['q' => 'Selendang']))
            ->assertOk()
            ->assertSee('<td>'.$listLabel.'</td>', false);
    }

    /** @return array<string, array{array<string, mixed>|null, string, string, string, string, string}> */
    public static function serialSettingsOnItemMaster(): array
    {
        $payload = fn (mixed $flag, mixed $method): array => ['ItemsGroupCode' => 200, 'ManageSerialNumbers' => $flag, 'SRIAndBatchManageMethod' => $method];

        return [
            'cache row without SAP metadata' => [null, 'BAN', 'Belum dapat dipastikan', 'Belum dapat dipastikan — GRPO ditolak', 'Belum tersedia', 'Belum pasti'],
            'nonserial item' => [$payload('tNO', 'bomm_OnEveryTransaction'), 'BAN', 'Tidak', 'Tidak', 'Terbaca dari SAP', 'Tidak'],
            'unreadable serial flag' => [$payload(null, null), 'BAN', 'Belum dapat dipastikan', 'Belum dapat dipastikan — GRPO ditolak', 'Terbaca, serial belum dapat dipastikan', 'Belum pasti'],
            'serial on every transaction' => [$payload('tYES', 'bomm_OnEveryTransaction'), 'BAN', 'Ya', 'Ya', 'Terbaca dari SAP', 'Dikelola'],
            'serial with an unknown method' => [$payload('tYES', 'bomm_FutureMethod'), 'SPAREPART', 'Ya', 'Belum dapat dipastikan — GRPO ditolak', 'Terbaca, serial belum dapat dipastikan', 'Dikelola'],
            'tire serial with release only method' => [$payload('tYES', 'bomm_OnReleaseOnly'), 'BAN', 'Ya', 'Belum didukung — GRPO ditolak', 'Terbaca dari SAP', 'Dikelola'],
        ];
    }

    public function test_item_master_detail_from_another_company_is_not_visible(): void
    {
        $user = $this->operationalUser();
        $item = SapItemCache::query()->create([
            'company_db' => 'SECOND_DB',
            'item_code' => 'I00085',
            'item_name' => 'Ban Database Lain',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('item-master.show', $item))->assertNotFound();
    }

    private function operationalUser(): User
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-dumai',
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

        return $user;
    }
}
