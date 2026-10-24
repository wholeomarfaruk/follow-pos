<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How money moved (App\Enums\PaymentMethod) plus its reference (bKash
     * transaction ID, cheque no., …) on the two records that move money
     * outside of sales: supplier payments / advances in the Purchase module,
     * and Accounts vouchers. order_payments already has both
     * (payment_method, transaction_id).
     */
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('amount');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('money_account_id');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['payment_method']);
            $table->dropColumn(['payment_method', 'payment_reference']);
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
