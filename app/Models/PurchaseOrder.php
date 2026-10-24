<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number', 'supplier_id', 'status',
        'order_date', 'deadline', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'deadline'   => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Supplier invoices billed against this PO (purchase bills and any returns). */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /** Goods receivings (GRNs) made against this PO. */
    public function receivings(): HasMany
    {
        return $this->hasMany(StockReceiving::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** Sum of all line items' total_amount — computed, not stored on the header. */
    public function getTotalAmountAttribute(): float
    {
        return (float) ($this->relationLoaded('items')
            ? $this->items->sum('total_amount')
            : $this->items()->sum('total_amount'));
    }

    /** Sum of all line items' quantity — computed, not stored on the header. */
    public function getTotalQuantityAttribute(): float
    {
        return (float) ($this->relationLoaded('items')
            ? $this->items->sum('quantity')
            : $this->items()->sum('quantity'));
    }
}
