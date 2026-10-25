<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\AccountsCustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Setting;

/**
 * Books a paid order payment in Accounts: against the customer invoice once
 * the sale is posted (Dr cash / Cr Receivable), otherwise as an advance held
 * for the order (Dr cash / Cr Customer Advance) that PostOrderCompletion
 * applies to the invoice when the sale posts. Nothing to book with the
 * Accounts module off, without a customer, or without a received-into account.
 * Shared by Order Detail (Add / Confirm payment), the POS screen and a
 * cleared order cheque (Accounts › Cheques). Returns the entry it posted.
 */
class PostOrderPayment
{
    public function __construct(
        protected PostCustomerPayment $postCustomerPayment,
        protected PostCustomerAdvance $postCustomerAdvance,
    ) {}

    public function handle(Order $order, OrderPayment $payment, ?string $entryDate = null): ?JournalEntry
    {
        if (! Setting::get('accounts_enabled', false, 'modules') || ! $order->customer_id || ! $payment->cashAccount) {
            return null;
        }

        $cashAccount = $payment->cashAccount;
        $entryDate ??= now()->toDateString();

        if (AccountsCustomerInvoice::where('order_id', $order->id)->exists()) {
            return $this->postCustomerPayment->handle(
                customer: $order->customer,
                cashAccountId: $cashAccount->id,
                receivableAccountId: $this->accountId('1100'),
                amount: (float) $payment->amount,
                entryDate: $entryDate,
                description: "Payment for Order #{$order->id} ({$cashAccount->name})",
                paymentMethod: $payment->payment_method,
                paymentReference: $payment->transaction_id,
            );
        }

        return $this->postCustomerAdvance->handle(
            customer: $order->customer,
            order: $order,
            customerAdvanceAccountId: $this->accountId('2160'),
            cashAccountId: $cashAccount->id,
            amount: (float) $payment->amount,
            entryDate: $entryDate,
            description: "Advance for Order #{$order->id} ({$cashAccount->name})",
            paymentMethod: $payment->payment_method,
            paymentReference: $payment->transaction_id,
        )->journalEntry;
    }

    protected function accountId(string $code): int
    {
        return Account::where('code', $code)->value('id')
            ?? throw new \RuntimeException("Chart of accounts is missing account code {$code}.");
    }
}
