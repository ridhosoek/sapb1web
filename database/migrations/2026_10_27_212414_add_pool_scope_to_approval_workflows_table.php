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
            $table->foreignId('pool_id')
                ->nullable()
                ->after('company_db')
                ->constrained('control_pools')
                ->nullOnDelete();
        });

        DB::table('approval_workflows')
            ->orderBy('id')
            ->each(function (object $workflow): void {
                $poolId = DB::table('approval_workflow_steps')
                    ->join('user_operational_profiles', 'user_operational_profiles.user_id', '=', 'approval_workflow_steps.approver_id')
                    ->where('approval_workflow_steps.approval_workflow_id', $workflow->id)
                    ->orderBy('approval_workflow_steps.step_order')
                    ->value('user_operational_profiles.pool_id');

                if ($poolId !== null) {
                    DB::table('approval_workflows')->where('id', $workflow->id)->update(['pool_id' => $poolId]);
                }
            });

        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->dropUnique(['company_db', 'document_type']);
            $table->unique(['company_db', 'document_type', 'pool_id'], 'approval_workflows_company_type_pool_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->dropUnique('approval_workflows_company_type_pool_unique');
            $table->unique(['company_db', 'document_type']);
            $table->dropConstrainedForeignId('pool_id');
        });
    }
};
