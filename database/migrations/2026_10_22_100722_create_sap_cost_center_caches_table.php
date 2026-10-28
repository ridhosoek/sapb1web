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
        Schema::create('sap_cost_center_caches', function (Blueprint $table) {
            $table->id();
            $table->string('company_db', 100);
            $table->string('center_code', 100);
            $table->string('center_name');
            $table->unsignedSmallInteger('dimension');
            $table->boolean('is_active')->default(true);
            $table->json('source_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_db', 'center_code']);
            $table->index(['company_db', 'dimension', 'is_active', 'center_code'], 'sap_cost_centers_company_dimension_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sap_cost_center_caches');
    }
};
