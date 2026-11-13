<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TD-11: the first sender of a Goods Receipt PO owns its history. `sap_commands.requested_by` is written before
     * the POST and is never changed by a retry or a reconciliation; `sap_documents.web_created_by` carries the same
     * user on the stored Goods Receipt PO. Both stay empty for older rows until
     * `sap:goods-receipts:attribute-creators` proves the sender from the audit log.
     */
    public function up(): void
    {
        Schema::table('sap_commands', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('base_document_entry')->constrained('users')->nullOnDelete();
        });

        Schema::table('sap_documents', function (Blueprint $table) {
            $table->foreignId('web_created_by')->nullable()->after('created_from_web')->constrained('users')->nullOnDelete();
            $table->index(['company_db', 'document_type', 'web_created_by'], 'sap_documents_web_creator_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_documents', function (Blueprint $table) {
            $table->dropIndex('sap_documents_web_creator_index');
            $table->dropConstrainedForeignId('web_created_by');
        });

        Schema::table('sap_commands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
        });
    }
};
