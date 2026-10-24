<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM: leads move through a small pipeline of stages and are converted to a
 * customer (MasterProfile + Customer) when won. Activities are the lead's
 * timeline and its follow-up tasks (an activity with due_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('color', 20)->default('slate');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamps();
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name');
            $table->string('phone', 20)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('source', 30)->default('other');
            $table->foreignId('stage_id')->nullable()->constrained('crm_stages')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 10)->default('normal');
            $table->decimal('expected_value', 20, 2)->default(0);
            $table->string('interest', 500)->nullable();
            $table->string('address', 500)->nullable();
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->timestamp('last_contacted_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('master_profile_id')->nullable()->constrained('master_profiles')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage_id', 'assigned_to']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
            $table->index(['type', 'due_at', 'done_at']);
        });

        $now = now();
        DB::table('crm_stages')->insert([
            ['name' => 'New',         'color' => 'sky',     'sort' => 1, 'probability' => 10,  'is_won' => false, 'is_lost' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Contacted',   'color' => 'indigo',  'sort' => 2, 'probability' => 25,  'is_won' => false, 'is_lost' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Interested',  'color' => 'amber',   'sort' => 3, 'probability' => 50,  'is_won' => false, 'is_lost' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Negotiation', 'color' => 'violet',  'sort' => 4, 'probability' => 75,  'is_won' => false, 'is_lost' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Won',         'color' => 'emerald', 'sort' => 5, 'probability' => 100, 'is_won' => true,  'is_lost' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Lost',        'color' => 'rose',    'sort' => 6, 'probability' => 0,   'is_won' => false, 'is_lost' => true,  'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_stages');
    }
};
