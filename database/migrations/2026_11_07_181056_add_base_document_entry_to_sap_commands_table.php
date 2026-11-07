<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `base_document_entry` links a Goods Receipt PO command to its Purchase Order so that an uncertain
     * POST (POSTING / POSTING_UNKNOWN) can block further receipts for that PO (TD-05). Existing commands are
     * backfilled from their stored request payload so that an uncertain legacy POST also keeps blocking.
     */
    public function up(): void
    {
        Schema::table('sap_commands', function (Blueprint $table) {
            $table->unsignedBigInteger('base_document_entry')->nullable()->after('sap_entity');
            $table->index(['company_db', 'command_type', 'base_document_entry', 'status'], 'sap_commands_base_document_lookup_index');
        });

        DB::table('sap_commands')
            ->where('command_type', 'CREATE_GOODS_RECEIPT_PO')
            ->orderBy('id')
            ->select(['id', 'request_payload'])
            ->chunkById(200, function ($commands): void {
                foreach ($commands as $command) {
                    $payload = json_decode((string) $command->request_payload, true);
                    $baseEntry = is_array($payload) ? data_get($payload, 'DocumentLines.0.BaseEntry') : null;

                    if (is_numeric($baseEntry) && (int) $baseEntry > 0) {
                        DB::table('sap_commands')->where('id', $command->id)->update(['base_document_entry' => (int) $baseEntry]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_commands', function (Blueprint $table) {
            $table->dropIndex('sap_commands_base_document_lookup_index');
            $table->dropColumn('base_document_entry');
        });
    }
};
