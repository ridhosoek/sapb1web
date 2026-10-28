<?php

namespace Database\Seeders;

use App\Models\ControlPool;
use App\Models\ControlVehicle;
use App\Models\IssueType;
use App\Models\SapItemCache;
use Illuminate\Database\Seeder;

class DemoReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $pool = ControlPool::query()->where('code', 'DUMAI')->first();

        if (! $pool) {
            return;
        }

        foreach ([
            ['license_plate' => 'BK8748ES', 'description' => 'Mobil Tangki', 'last_known_km' => 789120],
            ['license_plate' => 'BK1234XX', 'description' => 'Kendaraan Operasional', 'last_known_km' => 145200],
            ['license_plate' => 'BK9921XY', 'description' => 'Unit Workshop', 'last_known_km' => 98250],
        ] as $vehicle) {
            ControlVehicle::query()->updateOrCreate(
                ['license_plate' => $vehicle['license_plate']],
                [...$vehicle, 'pool_id' => $pool->id, 'is_active' => true, 'synced_at' => now()],
            );
        }

        foreach ([
            ['item_code' => 'I00790', 'item_name' => 'Kain Majun', 'inventory_uom' => 'KG'],
            ['item_code' => 'I08521', 'item_name' => 'Kompresor AC Hino', 'inventory_uom' => 'PCS'],
            ['item_code' => 'I08811', 'item_name' => 'Kondensor AC Hino', 'inventory_uom' => 'PCS'],
            ['item_code' => 'I01025', 'item_name' => 'Oli Mesin Diesel', 'inventory_uom' => 'LTR'],
            ['item_code' => 'I04210', 'item_name' => 'Filter Solar', 'inventory_uom' => 'PCS'],
        ] as $item) {
            SapItemCache::query()->updateOrCreate(
                ['company_db' => config('sap.company_db'), 'item_code' => $item['item_code']],
                [...$item, 'is_inventory_item' => true, 'is_active' => true, 'synced_at' => now()],
            );
        }

        foreach ([
            ['code' => 'SPR-MT', 'name' => 'Sparepart Mobil Tangki'],
            ['code' => 'SPR-KO', 'name' => 'Sparepart Kendaraan Operasional'],
            ['code' => 'SPR-MEKANIK', 'name' => 'Kebutuhan Mekanik'],
            ['code' => 'BAN-MT', 'name' => 'Ban Mobil Tangki'],
            ['code' => 'BBM-KO', 'name' => 'BBM Ownuse Kendaraan Operasional'],
        ] as $issueType) {
            IssueType::query()->updateOrCreate(
                ['code' => $issueType['code']],
                [...$issueType, 'is_active' => true, 'source' => 'LOCAL_CONFIG'],
            );
        }
    }
}
