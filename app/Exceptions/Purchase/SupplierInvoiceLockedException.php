<?php

namespace App\Exceptions\Purchase;

use RuntimeException;

/**
 * The Accounts side of a supplier invoice can't be undone — it has already
 * been settled there — so the invoice can't be deleted, or have its type or
 * amount changed, from the Purchase module.
 */
class SupplierInvoiceLockedException extends RuntimeException
{
    public static function billPaid(): self
    {
        return new self('This bill already has payments applied in Accounts. Reverse those payments first.');
    }

    public static function belowPaid(float $paid): self
    {
        return new self('This bill already has ' . number_format($paid, 2) . ' paid against it — the amount can\'t go below that.');
    }

    public static function advanceApplied(): self
    {
        return new self('This advance is already applied to a bill in Accounts. Reverse that first.');
    }
}
