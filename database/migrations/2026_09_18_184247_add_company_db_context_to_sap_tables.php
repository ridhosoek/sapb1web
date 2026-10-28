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
        Schema::table('sap_items_cache', function (Blueprint $table) {
            $table->string('company_db', 100)->nullable()->after('id');
            $table->dropUnique('sap_items_cache_item_code_unique');
            $table->unique(['company_db', 'item_code']);
            $table->index(['company_db', 'is_active', 'item_code']);
        });

        Schema::table('sap_documents', function (Blueprint $table) {
            $table->string('company_db', 100)->nullable()->after('id');
            $table->dropUnique('sap_documents_sap_entity_sap_doc_entry_unique');
            $table->unique(['company_db', 'sap_entity', 'sap_doc_entry'], 'sap_documents_company_entity_entry_unique');
            $table->index(['company_db', 'pool_id', 'document_type'], 'sap_documents_company_pool_type_index');
        });

        Schema::table('sap_commands', function (Blueprint $table) {
            $table->string('company_db', 100)->nullable()->after('id')->index();
        });

        Schema::table('sap_api_logs', function (Blueprint $table) {
            $table->string('company_db', 100)->nullable()->after('id');
            $table->index(['company_db', 'created_at']);
        });

        $defaultCompanyDb = config('sap.company_db');

        if (is_string($defaultCompanyDb) && trim($defaultCompanyDb) !== '') {
            foreach (['sap_items_cache', 'sap_documents', 'sap_commands', 'sap_api_logs'] as $table) {
                DB::table($table)->whereNull('company_db')->update(['company_db' => $defaultCompanyDb]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_api_logs', function (Blueprint $table) {
            $table->dropIndex(['company_db', 'created_at']);
            $table->dropColumn('company_db');
        });

        Schema::table('sap_commands', function (Blueprint $table) {
            $table->dropIndex(['company_db']);
            $table->dropColumn('company_db');
        });

        Schema::table('sap_documents', function (Blueprint $table) {
            $table->dropIndex('sap_documents_company_pool_type_index');
            $table->dropUnique('sap_documents_company_entity_entry_unique');
            $table->unique(['sap_entity', 'sap_doc_entry']);
            $table->dropColumn('company_db');
        });

        Schema::table('sap_items_cache', function (Blueprint $table) {
            $table->dropIndex(['company_db', 'is_active', 'item_code']);
            $table->dropUnique(['company_db', 'item_code']);
            $table->unique('item_code');
            $table->dropColumn('company_db');
        });
    }
};
