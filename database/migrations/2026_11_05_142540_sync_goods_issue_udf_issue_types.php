<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $issueTypes = [
            ['code' => '1', 'name' => '1'],
            ['code' => 'AT-TRANSIT', 'name' => 'Aktiva Tetap Transit'],
            ['code' => 'BAN-KO', 'name' => 'Ban Kendaraan Operasional'],
            ['code' => 'BAN-MT', 'name' => 'Ban Mobil Tangki'],
            ['code' => 'BBM-KO', 'name' => 'BBM Ownuse Kendaraan Operasional'],
            ['code' => 'BBM-KTR', 'name' => 'BBM Ownuse Genset'],
            ['code' => 'BBM-OU', 'name' => 'BBM Ownuse Mobil Tangki'],
            ['code' => 'LSS-OU', 'name' => 'Susut/Losses BBM'],
            ['code' => 'SPR-ADJ', 'name' => 'Adjustment Sparepart'],
            ['code' => 'SPR-BT', 'name' => 'Sparepart BT'],
            ['code' => 'SPR-HS', 'name' => 'Sparepart HS'],
            ['code' => 'SPR-IK', 'name' => 'Sparepart Inventaris Kantor'],
            ['code' => 'SPR-KO', 'name' => 'Sparepart Kendaraan Operasional'],
            ['code' => 'SPR-MAP', 'name' => 'Sparepart MAP'],
            ['code' => 'SPR-MEKANIK', 'name' => 'Kebutuhan Mekanik'],
            ['code' => 'SPR-MNP', 'name' => 'Sparepart MNP'],
            ['code' => 'SPR-MT', 'name' => 'Sparepart Mobil Tangki'],
            ['code' => 'SPR-TS', 'name' => 'Sparepart TS'],
        ];

        DB::table('issue_types')->upsert(
            collect($issueTypes)->map(fn (array $issueType): array => [
                ...$issueType,
                'is_active' => true,
                'source' => 'SAP_UDF_U_VTPI',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['code'],
            ['name', 'is_active', 'source', 'updated_at'],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reference values are retained to avoid removing issue types already used by transactions.
    }
};
