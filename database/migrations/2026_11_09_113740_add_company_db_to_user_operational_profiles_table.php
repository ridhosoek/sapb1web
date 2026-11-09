<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TD-09: Pool, Department and Warehouse are mapped per user per SAP CompanyDB.
 *
 * Existing rows predate the company column and keep company_db = NULL: they are kept for reference, never used by any
 * operational flow, and nothing is copied to any company here. Attaching a legacy row to a company needs proof from
 * SAP (an active warehouse of that company), which a migration must not fetch; use
 * `php artisan sap:operational-profiles:backfill --company-db=IA` after migrating, or let Admin HO map each company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_operational_profiles', function (Blueprint $table) {
            $table->string('company_db', 100)->nullable()->after('user_id');
            // Added before the old unique key is dropped so the user_id foreign key always keeps an index.
            $table->unique(['user_id', 'company_db'], 'user_operational_profiles_user_company_unique');
        });

        Schema::table('user_operational_profiles', function (Blueprint $table) {
            $table->dropUnique('user_operational_profiles_user_id_unique');
        });
    }

    /**
     * Rolling back never deletes mappings. Once any profile belongs to a company, the single-profile layout cannot
     * represent it, so the rollback is refused and the data must be handled by hand first.
     */
    public function down(): void
    {
        $mapped = DB::table('user_operational_profiles')->whereNotNull('company_db')->exists();
        $duplicated = DB::table('user_operational_profiles')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists();

        if ($mapped || $duplicated) {
            throw new RuntimeException('Rollback ditolak: user_operational_profiles sudah berisi pemetaan per CompanyDB. Ekspor atau selesaikan data tersebut secara manual; migrasi ini tidak menghapus pemetaan.');
        }

        Schema::table('user_operational_profiles', function (Blueprint $table) {
            $table->unique('user_id', 'user_operational_profiles_user_id_unique');
        });

        Schema::table('user_operational_profiles', function (Blueprint $table) {
            $table->dropUnique('user_operational_profiles_user_company_unique');
            $table->dropColumn('company_db');
        });
    }
};
