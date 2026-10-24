<?php

namespace App\Actions\Accounts;

use App\Enums\Accounts\TransactionType;
use App\Models\AccountsPaymentAllocation;
use App\Models\AccountsSupplierAdvance;
use App\Models\AccountsSupplierBill;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Case 4.4 for a "return" SupplierInvoice — goods sent back to the supplier.
 * Cr Inventory for the full amount; Dr Accounts Payable for as much as the
 * supplier's open bills still owe (allocated oldest-first, so Payables shows
 * those bills reduced), and Dr Supplier Advance for any excess — money the
 * supplier now owes us, opened as an AccountsSupplierAdvance that can be
 * applied to a later bill. Same split PostPurchaseReturn describes, decided
 * here from the open bills instead of by the caller.
 */
class PostSupplierReturn
{
    public function __construct(protected PostJournalEntry $postJournalEntry) {}

    public function handle(
        Supplier $supplier,
        int $payableAccountId,
        int $supplierAdvanceAccountId,
        int $inventoryAccountId,
        float $amount,
        string $entryDate,
        int $supplierInvoiceId,
        ?string $description = null,
    ): JournalEntry {
        return DB::transaction(function () use ($supplier, $payableAccountId, $supplierAdvanceAccountId, $inventoryAccountId, $amount, $entryDate, $supplierInvoiceId, $description) {
            $allocations = $this->allocateAgainstOpenBills($supplier, $amount);
            $againstBills = round(array_sum($allocations), 2);
            $excess = round($amount - $againstBills, 2);

            $lines = [];
            if ($againstBills > 0) {
                $lines[] = ['account_id' => $payableAccountId, 'debit' => $againstBills, 'subledger_type' => Supplier::class, 'subledger_id' => $supplier->id];
            }
            if ($excess > 0) {
                $lines[] = ['account_id' => $supplierAdvanceAccountId, 'debit' => $excess, 'subledger_type' => Supplier::class, 'subledger_id' => $supplier->id];
            }
            $lines[] = ['account_id' => $inventoryAccountId, 'credit' => $amount];

            $entry = $this->postJournalEntry->handle([
                'entry_date'       => $entryDate,
                'description'      => $description ?? "Purchase return — {$supplier->name}",
                'transaction_type' => TransactionType::PURCHASE_RETURN->value,
                'source_type'      => SupplierInvoice::class,
                'source_id'        => $supplierInvoiceId,
                'purpose'          => 'purchase_return',
            ], $lines);

            foreach ($allocations as $billId => $allocated) {
                $bill = AccountsSupplierBill::findOrFail($billId);

                AccountsPaymentAllocation::create([
                    'payment_journal_entry_id' => $entry->id,
                    'allocatable_type'         => AccountsSupplierBill::class,
                    'allocatable_id'           => $bill->id,
                    'amount'                   => $allocated,
                ]);

                $bill->increment('amount_allocated', $allocated);
                $bill->refresh();
                $bill->update(['status' => $bill->amountDue() <= 0.01 ? 'paid' : 'partial']);
            }

            if ($excess > 0) {
                AccountsSupplierAdvance::create([
                    'supplier_id'         => $supplier->id,
                    'supplier_invoice_id' => $supplierInvoiceId,
                    'amount'              => $excess,
                    'amount_applied'      => 0,
                    'status'              => 'open',
                    'journal_entry_id'    => $entry->id,
                ]);
            }

            return $entry;
        });
    }

    /**
     * @return array<int, float> bill_id => amount, oldest open bill first
     */
    protected function allocateAgainstOpenBills(Supplier $supplier, float $amount): array
    {
        $remaining = $amount;
        $result = [];

        $openBills = AccountsSupplierBill::where('supplier_id', $supplier->id)
            ->whereIn('status', ['open', 'partial'])
            ->oldest()
            ->lockForUpdate()
            ->get();

        foreach ($openBills as $bill) {
            if ($remaining <= 0) {
                break;
            }

            $apply = round(min($remaining, $bill->amountDue()), 2);
            if ($apply > 0) {
                $result[$bill->id] = $apply;
                $remaining -= $apply;
            }
        }

        return $result;
    }
}
