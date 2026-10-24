<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\AccountsCustomerInvoice;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Setting;

/**
 * Books a paid order payment in Accounts: against the customer invoice once
 * the sale is posted (Dr cash / Cr Receivable), otherwise as an advance held
 * for the order (Dr cash / Cr Customer Advance) that PostOrderCompletion
 * applies to the invoice when the sale posts. Nothing to book with the
 * Accounts module off, without a customer, or without a received-into account.
 * Shared by Order Detail (Add / Confirm payment) and the POS screen.
 */
class PostOrderPayment
{
    public function __construct(
        protected PostCustomerPayment $postCustomerPayment,
        protected PostCustomerAdvance $postCustomerAdvance,
    ) {}

    public function handle(Order $order, OrderPayment $payment): void
    {
        if (! Setting::get('accounts_enabled', false, 'modules') || ! $order->customer_id || ! $payment->cashAccount) {
            return;
        }

        $cashAccount = $payment->cashAccount;

        if (AccountsCustomerInvoice::where('order_id', $order->id)->exists()) {
            $this->postCustomerPayment->handle(
                customer: $order->customer,
                cashAccountId: $cashAccount->id,
                receivableAccountId: $this->accountId('1100'),
                amount: (float) $payment->amount,
                entryDate: now()->toDateString(),
                description: "Payment for Order #{$order->id} ({$cashAccount->name})",
            );

            return;
        }

        $this->postCustomerAdvance->handle(
            customer: $order->customer,
            order: $order,
            customerAdvanceAccountId: $this->accountId('2160'),
            cashAccountId: $cashAccount->id,
            amount: (float) $payment->amount,
            entryDate: now()->toDateString(),
            description: "Advance for Order #{$order->id} ({$cashAccount->name})",
        );
    }

    protected function accountId(string $code): int
    {
        return Account::where('code', $code)->value('id')
            ?? throw new \RuntimeException("Chart of accounts is missing account code {$code}.");
    }
}
