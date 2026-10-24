<?php

namespace App\Enums\Purchase;

enum SupplierInvoiceType: string
{
    case PURCHASE = 'purchase';
    case ADVANCE  = 'advance';
    case PAYMENT  = 'payment';
    case RETURN   = 'return';

    /**
     * Only a purchase increases what is owed to the supplier (debit);
     * advance/payment/return (goods sent back) reduce it (credit).
     */
    public function isDebit(): bool
    {
        return match ($this) {
            self::PURCHASE => true,
            self::ADVANCE, self::PAYMENT, self::RETURN => false,
        };
    }

    /** Purchase and return carry product lines; advance/payment are a bare amount. */
    public function usesItems(): bool
    {
        return in_array($this, [self::PURCHASE, self::RETURN], true);
    }

    /** Document title on the printed slip. */
    public function documentTitle(): string
    {
        return match ($this) {
            self::PURCHASE => 'Purchase Bill',
            self::ADVANCE  => 'Advance Voucher',
            self::PAYMENT  => 'Payment Voucher',
            self::RETURN   => 'Purchase Return Note',
        };
    }

    public function isCredit(): bool
    {
        return ! $this->isDebit();
    }

    public function signedAmount(float $amount): float
    {
        return $this->isDebit() ? $amount : -$amount;
    }

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Purchase',
            self::ADVANCE  => 'Advance',
            self::PAYMENT  => 'Payment',
            self::RETURN   => 'Return',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PURCHASE => 'bg-indigo-50 text-indigo-600',
            self::ADVANCE  => 'bg-blue-50 text-blue-600',
            self::PAYMENT  => 'bg-emerald-50 text-emerald-600',
            self::RETURN   => 'bg-red-50 text-red-500',
        };
    }
}
