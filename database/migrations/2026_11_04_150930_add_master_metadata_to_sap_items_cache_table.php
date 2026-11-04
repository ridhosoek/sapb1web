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
            $table->unsignedInteger('item_group_code')->nullable()->after('inventory_uom')->index();
            $table->string('item_group_name', 100)->nullable()->after('item_group_code')->index();
            $table->boolean('manages_serial_numbers')->default(false)->after('is_purchase_item');
            $table->string('serial_management_method', 50)->nullable()->after('manages_serial_numbers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_items_cache', function (Blueprint $table) {
            $table->dropIndex(['item_group_code']);
            $table->dropIndex(['item_group_name']);
            $table->dropColumn([
                'item_group_code',
                'item_group_name',
                'manages_serial_numbers',
                'serial_management_method',
            ]);
        });
    }
};
