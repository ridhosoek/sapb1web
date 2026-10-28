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
        Schema::table('approval_workflow_steps', function (Blueprint $table) {
            $table->string('notification_email')->nullable();
        });

        Schema::table('document_approval_steps', function (Blueprint $table) {
            $table->string('notification_email')->nullable();
            $table->timestamp('notification_queued_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamp('notification_failed_at')->nullable();
            $table->unsignedSmallInteger('notification_attempts')->default(0);
            $table->text('notification_error')->nullable();
        });

        DB::table('approval_workflow_steps')
            ->eachById(function (object $step): void {
                $email = DB::table('users')->where('id', $step->approver_id)->value('email');

                DB::table('approval_workflow_steps')->where('id', $step->id)->update([
                    'notification_email' => $email,
                ]);
            });

        DB::table('document_approval_steps')
            ->eachById(function (object $step): void {
                $email = DB::table('users')->where('id', $step->approver_id)->value('email');

                DB::table('document_approval_steps')->where('id', $step->id)->update([
                    'notification_email' => $email,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_approval_steps', function (Blueprint $table) {
            $table->dropColumn([
                'notification_email',
                'notification_queued_at',
                'notification_sent_at',
                'notification_failed_at',
                'notification_attempts',
                'notification_error',
            ]);
        });

        Schema::table('approval_workflow_steps', function (Blueprint $table) {
            $table->dropColumn('notification_email');
        });
    }
};
