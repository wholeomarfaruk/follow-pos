# Purchase module — suppliers, POs, invoices, slips

Status: ready-for-human

## Findings (current state)

- **Return invoices go the wrong way.** `SupplierInvoiceType::RETURN` is a debit, so returning
  goods to a supplier *increases* `Supplier.balance` (what we owe). It also posts nothing to Accounts.
- **Deleting an invoice leaves Accounts behind.** Deleting the latest purchase / advance invoice
  fixes `Supplier.balance` but leaves its journal entry and `AccountsSupplierBill` /
  `AccountsSupplierAdvance` open, so the Purchase ledger and Accounts > Payables drift apart.
- **Editing an invoice's type** away from purchase leaves the bill posted (the code comments say so).
- **PO ↔ invoice link is dead at header level.** `purchase_orders.supplier_invoice_id` is never
  written; the real link is per line (`supplier_invoice_items.purchase_order_item_id`). The
  "linked to a PO" delete guard checks the dead column, so it never fires.
- **PO picker in the invoice modal** re-fills the *full* ordered quantity every time, even when
  part of the PO is already billed, and offers cancelled POs.
- **PO edits can break links.** Removing a line that already has receipts / invoice lines silently
  detaches them (FKs are `nullOnDelete`); quantity can drop below what's received; delete works
  even after stock was received. Saves aren't wrapped in a transaction.
- **The PO page doesn't show its documents** — no linked invoices, GRNs, or billed amounts.
- **No printable documents** for purchase orders or supplier invoices.
- The invoice modal markup is copy-pasted in `supplier-invoices` and `supplier-ledger` views.

## Plan

### A. Ledger integrity
1. `RETURN` becomes a credit (reduces the amount due). Migration corrects existing
   `Supplier.balance` by `-2 × sum(return amounts)` per supplier.
2. Return invoices post to Accounts (`PostSupplierReturn`): Cr Inventory; Dr Accounts Payable up to
   the supplier's open bills (allocated oldest-first), any excess Dr Supplier Advance (opens an
   `AccountsSupplierAdvance`).
3. `UnpostSupplierInvoice` reverses an invoice's Accounts side (reversal entries, undo
   allocations, remove the bill / advance). Blocked when the bill is already paid or the advance
   already applied. Used when deleting, and when editing the latest invoice changes its type or
   changes the amount of a non-purchase invoice (purchase → purchase keeps the delta adjustment).

### B. PO ↔ invoice ↔ receiving
1. `supplier_invoices.purchase_order_id` (nullable FK, backfilled from item links).
   Relations: `PurchaseOrder::invoices()`, `PurchaseOrder::receivings()`,
   `SupplierInvoice::purchaseOrder()`, `PurchaseOrderItem::invoicedQuantity()`.
   Drop the dead `PurchaseOrder::supplierInvoice()` relation and delete guard.
2. Invoice modal PO picker: no cancelled POs; fills only the not-yet-billed quantity; skips fully
   billed lines; shows billed / ordered in the option label.
3. PO page: per-line ordered / received / billed quantities, a "Documents" panel with linked
   invoices and GRNs, billed vs ordered totals, buttons for **Print**, **Create invoice**
   (opens the supplier ledger modal prefilled from this PO).
4. PO list: received progress, billed amount, print action.
5. PO guards: transaction on save; lines with receipts / invoices can't be removed or switch
   product; quantity can't drop below received; cancelled POs are read-only; delete is blocked once
   stock is received or invoices exist (cancel instead).

### C. Printable slips
1. Purchase order slip — `admin/purchase/orders/{id}/print/{order|checklist}`: the PO for the
   supplier (with prices + signatures) and a receiving checklist for the warehouse (no prices,
   tick boxes).
2. Supplier invoice print — `admin/purchase/invoices/{id}/print`: purchase bill / return note /
   payment / advance voucher, with items, linked PO, paid / due status.

### D. Supplier
1. Supplier ledger: "Purchase Orders" tab and **New PO** button (prefills the supplier).
2. Suppliers list: open PO count.
3. Extract the shared invoice modal into `purchase/partials/invoice-modal.blade.php`.

## Out of scope (follow-ups)

- Return invoices don't take stock out of a batch yet (Accounts inventory is reduced, physical stock isn't).
- A "partial" PO status (progress is shown instead; status stays pending / received / cancelled).
- Supplier statement print.

## Manual test checklist

Run `php artisan migrate` first (two migrations: return-balance fix, `supplier_invoices.purchase_order_id`).

- [ ] Supplier with a Return invoice: balance went **down** by 2 × return amount after migrating.
- [ ] New Return invoice → balance drops; Accounts journal shows Purchase Return (Dr 2100 / Cr 1200);
      the supplier's oldest open bill in Payables shows the return as allocated. Return bigger than
      open bills → excess shows as a supplier advance.
- [ ] Delete the latest Purchase invoice (unpaid) → its bill disappears from Payables, a reversal
      entry is posted. Paid bill → delete refused with a message.
- [ ] Edit latest invoice Purchase → Return → bill reversed, return posted. Edit an Advance's amount → asks for "Paid From", re-posts.
- [ ] PO page: Billed column per line, Documents panel lists invoices + GRNs, Print PO / Receiving
      Checklist open the slips, Create Invoice opens the ledger modal with only unbilled quantities.
- [ ] Bill a PO partially, pick it again → only the remaining quantity is filled; fully billed → error.
- [ ] PO edit: removing a received line / lowering below received / changing its product → refused.
- [ ] PO list: Received % and Billed columns; delete refused once received/billed; cancel only when pending.
- [ ] Cancelled PO: read-only, no Receive / Create Invoice buttons, Stock In refuses it.
- [ ] Supplier ledger: Purchase Orders tab, New Purchase Order prefills the supplier, Print on each row.
- [ ] Suppliers list: Open POs count links to the ledger's PO tab.
