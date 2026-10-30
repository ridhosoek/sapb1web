<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->foreignId('submitter_id')
                ->nullable()
                ->after('company_db')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('approval_workflows')
            ->whereNotNull('pool_id')
            ->orderBy('id')
            ->each(function (object $workflow): void {
                $submitterId = DB::table('users')
                    ->join('user_operational_profiles', 'user_operational_profiles.user_id', '=', 'users.id')
                    ->where('users.role', 'POOL_USER')
                    ->where('users.is_active', true)
                    ->where('user_operational_profiles.pool_id', $workflow->pool_id)
                    ->orderBy('users.id')
                    ->value('users.id');

                if ($submitterId !== null) {
                    DB::table('approval_workflows')
                        ->where('id', $workflow->id)
                        ->update(['submitter_id' => $submitterId]);
                }
            });

        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->dropUnique('approval_workflows_company_type_pool_unique');
            $table->unique(
                ['company_db', 'document_type', 'submitter_id'],
                'approval_workflows_company_type_submitter_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasDuplicatePoolScopes = DB::table('approval_workflows')
            ->select(['company_db', 'document_type', 'pool_id'])
            ->whereNotNull('pool_id')
            ->groupBy(['company_db', 'document_type', 'pool_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicatePoolScopes) {
            throw new RuntimeException('Rollback dibatalkan karena beberapa workflow user berada pada Pool yang sama.');
        }

        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->dropUnique('approval_workflows_company_type_submitter_unique');
            $table->unique(
                ['company_db', 'document_type', 'pool_id'],
                'approval_workflows_company_type_pool_unique',
            );
            $table->dropConstrainedForeignId('submitter_id');
        });
    }
};
