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
        Schema::create('sap_item_warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('company_db', 100);
            $table->foreignId('sap_item_cache_id')->constrained('sap_items_cache')->cascadeOnDelete();
            $table->string('warehouse_code', 50);
            $table->decimal('in_stock', 19, 6)->default(0);
            $table->decimal('committed_stock', 19, 6)->default(0);
            $table->decimal('ordered_stock', 19, 6)->default(0);
            $table->decimal('available_stock', 19, 6)->default(0);
            $table->decimal('minimum_stock', 19, 6)->default(0);
            $table->boolean('is_locked')->default(false);
            $table->json('source_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_db', 'sap_item_cache_id', 'warehouse_code'], 'sap_item_warehouse_unique');
            $table->index(['company_db', 'warehouse_code', 'available_stock'], 'sap_item_warehouse_available_index');
            $table->index(['company_db', 'warehouse_code', 'synced_at'], 'sap_item_warehouse_synced_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sap_item_warehouse_stocks');
    }
};
