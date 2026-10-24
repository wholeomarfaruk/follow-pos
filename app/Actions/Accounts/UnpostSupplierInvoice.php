<?php

namespace App\Actions\Accounts;

use App\Enums\Accounts\JournalEntryStatus;
use App\Enums\Accounts\TransactionType;
use App\Exceptions\Purchase\SupplierInvoiceLockedException;
use App\Models\AccountsPaymentAllocation;
use App\Models\AccountsSupplierAdvance;
use App\Models\AccountsSupplierBill;
use App\Models\JournalEntry;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Undoes everything a supplier invoice posted to Accounts, so deleting it
 * (or changing its type/amount) can't leave Payables out of step with the
 * Purchase ledger: every journal entry sourced from the invoice is reversed
 * (never edited — Golden Rule #2), a return's bill allocations are taken
 * back, and its AccountsSupplierBill / AccountsSupplierAdvance is removed.
 *
 * Refuses (SupplierInvoiceLockedException) once Accounts has settled it —
 * a bill with payments applied, or an advance already applied to a bill.
 *
 * The reversed entry's `purpose` gets a "_reversed_{id}" suffix: purpose is
 * PostJournalEntry's one-per-source idempotency key, and the invoice may be
 * posted again right after (an edit), which must not be mistaken for a
 * duplicate of the entry that was just reversed.
 */
class UnpostSupplierInvoice
{
    public function __construct(protected ReverseJournalEntry $reverseJournalEntry) {}

    public function handle(SupplierInvoice $invoice, ?string $reason = null): void
    {
        DB::transaction(function () use ($invoice, $reason) {
            $bill = AccountsSupplierBill::where('supplier_invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($bill && (float) $bill->amount_allocated > 0.009) {
                throw SupplierInvoiceLockedException::billPaid();
            }

            $advance = AccountsSupplierAdvance::where('supplier_invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($advance && (float) $advance->amount_applied > 0.009) {
                throw SupplierInvoiceLockedException::advanceApplied();
            }

            $entries = JournalEntry::query()
                ->where('source_type', SupplierInvoice::class)
                ->where('source_id', $invoice->id)
                ->where('status', JournalEntryStatus::POSTED->value)
                ->where('transaction_type', '!=', TransactionType::REVERSAL->value)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('journal_entries as reversal')
                    ->whereColumn('reversal.reversed_journal_entry_id', 'journal_entries.id'))
                ->get();

            AccountsPaymentAllocation::whereIn('payment_journal_entry_id', $entries->pluck('id'))
                ->where('allocatable_type', AccountsSupplierBill::class)
                ->get()
                ->each(function (AccountsPaymentAllocation $allocation) {
                    $allocatedBill = AccountsSupplierBill::lockForUpdate()->find($allocation->allocatable_id);

                    if ($allocatedBill) {
                        $allocatedBill->amount_allocated = max(0, round((float) $allocatedBill->amount_allocated - (float) $allocation->amount, 2));
                        $allocatedBill->status = $allocatedBill->amountDue() <= 0.01
                            ? 'paid'
                            : ((float) $allocatedBill->amount_allocated > 0 ? 'partial' : 'open');
                        $allocatedBill->save();
                    }

                    $allocation->delete();
                });

            $reason ??= "Supplier invoice #{$invoice->serial_number} unposted";

            foreach ($entries as $entry) {
                $this->reverseJournalEntry->handle($entry, $reason);

                if ($entry->purpose) {
                    $entry->update(['purpose' => "{$entry->purpose}_reversed_{$entry->id}"]);
                }
            }

            $bill?->delete();
            $advance?->delete();
        });
    }
}
