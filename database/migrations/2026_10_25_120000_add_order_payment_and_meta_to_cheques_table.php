<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cheque taken against an order (Sales › Order › Add payment) is linked to
 * its pending order payment, which turns paid when the cheque clears. meta
 * keeps what the clearing voucher should settle (meta.bill_ids for a
 * supplier cheque from the Purchase module).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('order_payment_id')->nullable()->after('journal_entry_id')->constrained('order_payments')->nullOnDelete();
            $table->json('meta')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_payment_id');
            $table->dropColumn('meta');
        });
    }
};
