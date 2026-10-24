<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Hub (.scratch/notifications-crm/spec.md): business events to
 * customers, suppliers, employees, owners, staff and leads over SMS, email,
 * web push and the in-app bell. Rules are editable per event × audience;
 * dispatches are the outbox and the log in one table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 60);
            $table->string('audience', 20);
            $table->boolean('is_active')->default(false);
            $table->json('channels')->nullable();
            $table->text('sms_body')->nullable();
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->string('push_title')->nullable();
            $table->string('push_body', 500)->nullable();
            $table->integer('offset_days')->default(0);
            $table->string('send_time', 5)->default('10:00');
            $table->string('staff_role', 60)->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();

            $table->unique(['event_key', 'audience']);
        });

        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('segment', 40);
            $table->json('segment_filters')->nullable();
            $table->json('channels');
            $table->text('sms_body')->nullable();
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->string('push_title')->nullable();
            $table->string('push_body', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->nullable()->constrained('notification_rules')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('notification_campaigns')->cascadeOnDelete();
            $table->string('event_key', 60);
            $table->string('audience', 20)->nullable();
            $table->string('channel', 20);
            $table->nullableMorphs('recipient');
            $table->string('recipient_name')->nullable();
            $table->string('to')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('error', 500)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->nullableMorphs('related');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['event_key', 'created_at']);
            $table->index(['channel', 'created_at']);
        });

        Schema::create('notification_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_profile_id')->nullable()->constrained('master_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('channel', 20)->default('*');
            $table->string('category', 20)->default('marketing');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['master_profile_id', 'lead_id', 'channel', 'category'], 'notification_opt_outs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_opt_outs');
        Schema::dropIfExists('notification_dispatches');
        Schema::dropIfExists('notification_campaigns');
        Schema::dropIfExists('notification_rules');
    }
};
