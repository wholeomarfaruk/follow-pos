<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A "return" supplier invoice used to count as a debit (raising what is
     * owed to the supplier). It is now a credit, so every supplier's cached
     * balance moves down by twice its returns: once to undo the old +amount,
     * once to apply the new -amount.
     */
    public function up(): void
    {
        $this->shift(-2);
    }

    public function down(): void
    {
        $this->shift(2);
    }

    protected function shift(int $factor): void
    {
        DB::table('supplier_invoices')
            ->where('type', 'return')
            ->selectRaw('supplier_id, SUM(amount) as total')
            ->groupBy('supplier_id')
            ->get()
            ->each(fn ($row) => DB::table('suppliers')
                ->where('id', $row->supplier_id)
                ->increment('balance', round($factor * (float) $row->total, 2)));
    }
};
