<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Header-level PO link for supplier invoices. Lines already carry
     * purchase_order_item_id; this backfills the header from them so lists,
     * slips and the PO page can show "billed against PO-…" without walking
     * the lines. purchase_orders.supplier_invoice_id (never written) stays
     * in place but is no longer read.
     */
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')
                ->constrained('purchase_orders')->nullOnDelete();
        });

        DB::table('supplier_invoice_items as sii')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'sii.purchase_order_item_id')
            ->select('sii.supplier_invoice_id', DB::raw('MIN(poi.purchase_order_id) as purchase_order_id'))
            ->groupBy('sii.supplier_invoice_id')
            ->get()
            ->each(fn ($row) => DB::table('supplier_invoices')
                ->where('id', $row->supplier_invoice_id)
                ->update(['purchase_order_id' => $row->purchase_order_id]));
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
