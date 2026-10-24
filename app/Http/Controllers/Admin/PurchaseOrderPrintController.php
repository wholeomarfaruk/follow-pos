<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Services\Purchase\PurchaseOrderProgress;
use Illuminate\Contracts\View\View;

/**
 * Printable purchase order (admin → Purchase → Orders → Print). Same
 * standalone print-page pattern as OrderPrintController: "order" is the PO
 * sent to the supplier (prices, terms, signatures); "checklist" is the
 * warehouse receiving slip — no prices, ordered vs received so far, and tick
 * boxes for counting the delivery in.
 */
class PurchaseOrderPrintController extends Controller
{
    public function __invoke(int $id, string $type = 'order'): View
    {
        abort_unless(in_array($type, ['order', 'checklist'], true), 404);

        $order = PurchaseOrder::with(['supplier', 'items.product', 'items.variant'])->findOrFail($id);

        app(PurchaseOrderProgress::class)->attach(collect([$order]));

        $company = collect([
            'name', 'logo', 'favicon', 'email', 'phone', 'mobile', 'website',
            'address', 'city', 'state', 'postal_code', 'tax_number',
        ])->mapWithKeys(fn ($field) => [$field => Setting::get("company_{$field}", null, 'company')]);

        $company['name'] = $company['name'] ?: Setting::get('site_name', config('app.name'));

        return view('admin.purchase-order-print', [
            'order'    => $order,
            'company'  => $company,
            'type'     => $type,
            'currency' => Setting::get('currency_symbol', '৳', 'localization'),
        ]);
    }
}
