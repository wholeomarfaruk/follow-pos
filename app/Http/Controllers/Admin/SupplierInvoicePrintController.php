<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SupplierInvoice;
use Illuminate\Contracts\View\View;

/**
 * Printable supplier invoice (admin → Purchase → Invoices / Supplier Ledger
 * → Print): a purchase bill, return note, payment or advance voucher,
 * depending on the invoice type. Shows the PO it was billed against, its
 * Accounts settlement (paid / due on the bill) and the supplier's running
 * balance right after this transaction, worked out the same way as
 * SupplierLedger::attachRunningBalances().
 */
class SupplierInvoicePrintController extends Controller
{
    public function __invoke(int $id): View
    {
        $invoice = SupplierInvoice::with(['supplier', 'items.variant', 'purchaseOrder', 'bill'])->findOrFail($id);

        $newerDelta = SupplierInvoice::where('supplier_id', $invoice->supplier_id)
            ->where('serial_number', '>', $invoice->serial_number)
            ->get(['type', 'amount'])
            ->sum(fn (SupplierInvoice $newer) => $newer->type->signedAmount((float) $newer->amount));

        $company = collect([
            'name', 'logo', 'favicon', 'email', 'phone', 'mobile', 'website',
            'address', 'city', 'state', 'postal_code', 'tax_number',
        ])->mapWithKeys(fn ($field) => [$field => Setting::get("company_{$field}", null, 'company')]);

        $company['name'] = $company['name'] ?: Setting::get('site_name', config('app.name'));

        return view('admin.supplier-invoice-print', [
            'invoice'      => $invoice,
            'balanceAfter' => (float) $invoice->supplier->balance - $newerDelta,
            'company'      => $company,
            'currency'     => Setting::get('currency_symbol', '৳', 'localization'),
        ]);
    }
}
