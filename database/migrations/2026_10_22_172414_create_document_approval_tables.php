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
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('company_db', 100);
            $table->string('document_type', 40);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['company_db', 'document_type']);
        });

        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('notification_phone', 32)->nullable();
            $table->timestamps();
            $table->unique(['approval_workflow_id', 'step_order']);
            $table->unique(['approval_workflow_id', 'approver_id']);
        });

        Schema::create('document_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sap_document_id')->unique()->constrained('sap_documents')->cascadeOnDelete();
            $table->foreignId('approval_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('PENDING')->index();
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('document_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_approval_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('approver_name');
            $table->string('notification_phone', 32)->nullable();
            $table->unsignedSmallInteger('step_order');
            $table->string('status', 20)->default('PENDING')->index();
            $table->uuid('token')->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('decision_ip', 45)->nullable();
            $table->timestamps();
            $table->unique(['document_approval_id', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_approval_steps');
        Schema::dropIfExists('document_approvals');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
