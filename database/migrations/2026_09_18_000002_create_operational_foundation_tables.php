<?php

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\WatchStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_pools', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->unique();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('control_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->nullable()->constrained('control_pools')->nullOnDelete();
            $table->string('external_id')->nullable()->unique();
            $table->string('code', 50)->index();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['pool_id', 'code']);
        });

        Schema::create('control_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('control_pools')->cascadeOnDelete();
            $table->string('external_id')->nullable()->unique();
            $table->string('license_plate', 32)->unique();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('last_known_km')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['pool_id', 'is_active']);
        });

        Schema::create('user_operational_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->constrained('control_pools')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('control_departments')->restrictOnDelete();
            $table->string('warehouse_code', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('sap_items_cache', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 100)->unique();
            $table->string('item_name');
            $table->string('inventory_uom', 50)->nullable();
            $table->boolean('is_inventory_item')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->json('source_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['item_name', 'is_active']);
        });

        Schema::create('issue_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->string('source', 32)->default('LOCAL_CONFIG');
            $table->timestamps();
        });

        Schema::create('sap_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->nullable()->constrained('control_pools')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('control_departments')->nullOnDelete();
            $table->string('document_type', 40)->index();
            $table->string('sap_entity', 100);
            $table->unsignedBigInteger('sap_doc_entry')->nullable();
            $table->string('sap_doc_num', 100)->nullable();
            $table->string('source', 32)->default('WEB');
            $table->string('fpk_number', 100)->nullable()->index();
            $table->uuid('transaction_uuid')->nullable()->unique();
            $table->string('sap_business_status', 32)->default(BusinessStatus::Open->value)->index();
            $table->string('integration_status', 32)->default(IntegrationStatus::PendingPost->value)->index();
            $table->boolean('created_from_web')->default(true);
            $table->json('normalized_data')->nullable();
            $table->timestamps();
            $table->unique(['sap_entity', 'sap_doc_entry']);
            $table->index(['pool_id', 'document_type', 'sap_business_status']);
        });

        Schema::create('sap_document_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_document_id')->constrained('sap_documents')->cascadeOnDelete();
            $table->foreignId('child_document_id')->constrained('sap_documents')->cascadeOnDelete();
            $table->string('relation_type', 50);
            $table->string('discovered_by', 50)->default('SERVICE_LAYER');
            $table->json('relation_metadata')->nullable();
            $table->timestamps();
            $table->unique(['parent_document_id', 'child_document_id', 'relation_type'], 'sap_doc_relation_unique');
        });

        Schema::create('sap_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sap_document_id')->unique()->constrained('sap_documents')->cascadeOnDelete();
            $table->string('watch_status', 20)->default(WatchStatus::Active->value)->index();
            $table->char('last_fingerprint', 64)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_document_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sap_document_id')->constrained('sap_documents')->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->json('normalized_data');
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(['sap_document_id', 'observed_at']);
        });

        Schema::create('sap_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_uuid')->unique();
            $table->string('command_type', 50)->index();
            $table->string('sap_entity', 100);
            $table->string('status', 32)->default(IntegrationStatus::PendingPost->value)->index();
            $table->json('request_payload')->nullable();
            $table->unsignedBigInteger('sap_doc_entry')->nullable();
            $table->string('sap_doc_num', 100)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_api_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('endpoint');
            $table->unsignedSmallInteger('response_status')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('result', 20)->index();
            $table->text('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['endpoint', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->nullableMorphs('auditable');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sap_api_logs');
        Schema::dropIfExists('sap_commands');
        Schema::dropIfExists('sap_document_snapshots');
        Schema::dropIfExists('sap_watchers');
        Schema::dropIfExists('sap_document_relations');
        Schema::dropIfExists('sap_documents');
        Schema::dropIfExists('issue_types');
        Schema::dropIfExists('sap_items_cache');
        Schema::dropIfExists('user_operational_profiles');
        Schema::dropIfExists('control_vehicles');
        Schema::dropIfExists('control_departments');
        Schema::dropIfExists('control_pools');
    }
};
