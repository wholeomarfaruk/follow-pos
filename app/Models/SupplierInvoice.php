<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\Purchase\SupplierInvoiceType;
use App\Exceptions\Purchase\SupplierInvoiceDeletionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SupplierInvoice extends Model
{
    protected $fillable = [
        'supplier_id', 'purchase_order_id', 'serial_number', 'invoice_number', 'type',
        'amount', 'payment_method', 'payment_reference', 'is_adjusted', 'invoice_date', 'notes', 'document_ids',
    ];

    protected function casts(): array
    {
        return [
            'type'         => SupplierInvoiceType::class,
            'amount'       => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'is_adjusted'  => 'boolean',
            'invoice_date' => 'date',
            'document_ids' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    /** The PO this invoice was billed against (set when its lines came from a PO). */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Accounts > Payables open item for a "purchase" invoice. */
    public function bill(): HasOne
    {
        return $this->hasOne(AccountsSupplierBill::class);
    }

    /** Accounts advance for an "advance" invoice, or the excess of a "return". */
    public function advance(): HasOne
    {
        return $this->hasOne(AccountsSupplierAdvance::class);
    }

    /** Every journal entry posted for this invoice (bill, adjustments, return, reversals). */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    /** Display reference: the supplier's own invoice number, else our serial. */
    public function reference(): string
    {
        return $this->invoice_number ?: '#' . str_pad((string) $this->serial_number, 6, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::created(function (SupplierInvoice $invoice) {
            $invoice->supplier()->increment('balance', $invoice->type->signedAmount((float) $invoice->amount));
        });

        static::updating(function (SupplierInvoice $invoice) {
            if ($invoice->isDirty(['type', 'amount', 'supplier_id'])) {
                $originalType   = SupplierInvoiceType::from($invoice->getRawOriginal('type'));
                $originalDelta  = $originalType->signedAmount((float) $invoice->getOriginal('amount'));
                $newDelta       = $invoice->type->signedAmount((float) $invoice->amount);

                Supplier::whereKey($invoice->getOriginal('supplier_id'))->decrement('balance', $originalDelta);
                Supplier::whereKey($invoice->supplier_id)->increment('balance', $newDelta);
            }
        });

        static::deleting(function (SupplierInvoice $invoice) {
            // A payment's amount is already posted as a journal entry and
            // allocated against specific AccountsSupplierBill rows — deleting
            // this row alone would silently re-diverge Supplier.balance from
            // the Accounts ledger, since the allocation/journal entry would
            // be left behind untouched.
            if ($invoice->type === SupplierInvoiceType::PAYMENT) {
                throw SupplierInvoiceDeletionException::isPaymentWithAllocations();
            }

            if (! $invoice->isLatestSerial()) {
                throw SupplierInvoiceDeletionException::notLatestSerial();
            }
        });

        static::deleted(function (SupplierInvoice $invoice) {
            $invoice->supplier()->decrement('balance', $invoice->type->signedAmount((float) $invoice->amount));
        });
    }

    /**
     * Whether this is the most recently recorded invoice for its supplier
     * (the only one currently eligible for deletion).
     */
    public function isLatestSerial(): bool
    {
        return $this->serial_number == static::where('supplier_id', $this->supplier_id)->max('serial_number');
    }
}
