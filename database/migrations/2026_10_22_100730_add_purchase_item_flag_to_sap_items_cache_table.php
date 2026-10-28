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
        Schema::table('sap_items_cache', function (Blueprint $table) {
            $table->boolean('is_purchase_item')->default(true)->after('is_inventory_item');
            $table->index(['company_db', 'is_active', 'is_purchase_item', 'item_code'], 'sap_items_company_purchase_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_items_cache', function (Blueprint $table) {
            $table->dropIndex('sap_items_company_purchase_active_index');
            $table->dropColumn('is_purchase_item');
        });
    }
};
