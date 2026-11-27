<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TD-14: what the last read-only SAP check of an uncertain command found, and when it may be checked again. The
     * columns are additive; existing commands count as never checked.
     */
    public function up(): void
    {
        Schema::table('sap_commands', function (Blueprint $table) {
            $table->unsignedInteger('reconcile_attempts')->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('next_reconcile_at')->nullable();
            $table->string('reconcile_outcome', 40)->nullable();
            $table->unsignedSmallInteger('reconcile_match_count')->nullable();
            $table->string('reconcile_detail', 500)->nullable();
            $table->index(['company_db', 'command_type', 'status', 'next_reconcile_at'], 'sap_commands_reconcile_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_commands', function (Blueprint $table) {
            $table->dropIndex('sap_commands_reconcile_index');
            $table->dropColumn([
                'reconcile_attempts',
                'last_reconciled_at',
                'next_reconcile_at',
                'reconcile_outcome',
                'reconcile_match_count',
                'reconcile_detail',
            ]);
        });
    }
};
