<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sap_documents', function (Blueprint $table): void {
            $table->foreignId('admin_ho_checked_by')
                ->nullable()
                ->after('created_from_web')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('admin_ho_checked_at')->nullable()->after('admin_ho_checked_by');
            $table->string('admin_ho_check_ip', 45)->nullable()->after('admin_ho_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_documents', function (Blueprint $table): void {
            $table->dropForeign(['admin_ho_checked_by']);
            $table->dropColumn(['admin_ho_checked_by', 'admin_ho_checked_at', 'admin_ho_check_ip']);
        });
    }
};
