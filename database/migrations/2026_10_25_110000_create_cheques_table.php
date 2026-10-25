<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts Panel › Cheques: cheques we received (to deposit) and cheques we
 * issued (to be presented). A cheque moves no money by itself — clearing it
 * makes the receipt / payment voucher (journal_entry_id), so a party's due
 * only changes once the money is in / out of the bank. due_date is the
 * cash / release date the reminders run on (reschedulable); cheque_date is
 * what is written on the cheque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10);
            $table->string('cheque_no', 60);
            $table->string('bank_name', 120)->nullable();
            $table->string('branch', 120)->nullable();
            $table->foreignId('master_profile_id')->nullable()->constrained('master_profiles')->nullOnDelete();
            $table->string('party_label', 120)->nullable();
            $table->decimal('amount', 20, 2);
            $table->date('cheque_date');
            $table->date('due_date');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('ledger_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->date('deposited_at')->nullable();
            $table->date('cleared_at')->nullable();
            $table->date('bounced_at')->nullable();
            $table->string('bounce_reason')->nullable();
            $table->unsignedSmallInteger('bounce_count')->default(0);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['direction', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques');
    }
};
