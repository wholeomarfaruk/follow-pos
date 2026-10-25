# Cheque management + money reminders

Status: implemented (not yet tested; migration not run)

## Decisions (from the owner)

1. A received cheque does **not** reduce the customer's due. The due goes down only when the cheque is cleared (money is in the bank).
2. An issued cheque is reminded **every day from 2 days before its release date up to the release date**. The date can be rescheduled.
3. A bounced cheque sends an SMS (and email when there is one) to the customer / supplier.

## What exists today (audit)

- `PaymentMethod::CHEQUE` + a free-text `payment_reference` ("Cheque no.") — no cheque date, bank, status or register.
- Notification Hub: channels SMS / email / web push / in-app; audiences customer, supplier, owner, staff…; daily scanners (`ScheduledNotifications`).
- Receivable reminders: `due.reminder`, `promise.due`, kisti SMS (outside the Hub).
- Payables: `PayablesSchedule` lists every payable by date, but only loan EMI has a reminder (owners, SMS, 3 days before).
- No "accountant" audience in the Hub.

## Plan

### 1. Data — `cheques` table + `App\Models\Cheque`

| column | notes |
|---|---|
| direction | `received` (party gave us) · `issued` (we gave) |
| cheque_no, bank_name, branch | |
| master_profile_id / party_label | party (profile, or a typed name) |
| amount | |
| cheque_date | the date written on the cheque |
| due_date | when it can be cashed / is released — reschedulable; reminders run on this |
| account_id | bank account it goes into / is drawn on |
| ledger_id | the other side of the voucher made on clearing (receivable, payable, …) |
| status | `pending` → (`deposited`, received only) → `cleared` · `bounced` · `cancelled` |
| journal_entry_id | the receipt / payment voucher made on clearing (null on delete) |
| deposited_at, cleared_at, bounced_at, bounce_reason, bounce_count, note, created_by | |

Added to Data Reset › Accounting.

### 2. Service — `App\Services\Accounts\Cheques`

- `create()` — registers the cheque, no journal entry (decision 1). Timeline line on the profile.
- `deposit()` — received cheque handed to the bank.
- `clear(date, account, post)` — makes the receipt (Dr bank / Cr ledger) or payment (Dr ledger / Cr bank) voucher through `Vouchers::draft()` (method cheque, reference = cheque no, source = the cheque) and tries to post it like "Save & post". The due changes only when that voucher posts. A posted receipt / payment fires `payment.received` / `payment.sent`.
- `undoClear()` — back to open while the voucher is not posted (deletes the waiting draft).
- `bounce(date, reason)` — open → bounced; fires `cheque.bounced`.
- `reschedule(date)` — new due date; a bounced cheque goes back to pending (re-presented).
- `cancel(reason)` — returned / void.

### 3. Accounts Panel › Cheques page (`accounts.cheques`)

Stat cards, direction and status filters, search, a table with per-row actions (Deposit / Clear / Bounce / Reschedule / Cancel / Undo) and modals. Sidebar item after Payables.

### 4. Cheques from the existing money forms

- Voucher form (receipt / payment, new voucher) and profile Collect / Pay: picking **Cheque** shows cheque date + bank, and saving registers the cheque instead of posting money.
- Payables › Pay modal: a hint that links to the Cheques page (the row-specific drafts there — EMI, salary, bills — stay as they are).
- Admin Sales / POS / Purchase forms are unchanged (follow-up).

### 5. Notification Hub

- New audience **`accountant`** (users with `accounts.post` / `accounts.approve`, as in report delivery); counts as staff (in-app, push).
- New events (group Accounts):
  - `cheque.received_due` — daily; N days (default 1) before a received cheque's due date. Owner / staff / accountant digest, optional customer SMS.
  - `cheque.issued_due` — daily; every day from N days (default 2) before up to the release date. Owner / staff / accountant digest, with the bank balance.
  - `cheque.bounced` — instant; customer (received) or supplier (issued) SMS + email, owner / staff / accountant alert.
  - `payable.due` — daily; supplier bills, kisti, EMI, salary and bills due within N days (default 1), from `PayablesSchedule`.
- `due.reminder` skips customers who have an open received cheque.
- Customer collection reminders default to SMS + email (fresh rules; email is only sent when the profile has one).
- The daily scan creates missing rule rows itself, so new daily events work before anyone opens the Notification Center.

## Plan review (checked against the code)

- `journal_entries` is unique on (source_type, source_id, purpose) and vouchers are hard-deleted. The clearing voucher uses purpose `cheque_cleared`, which stops a double clear at the database. Undo clear is allowed only while that voucher is not posted (deleting the draft frees the key). Once it is posted, the cheque stays cleared and the voucher is reversed in Transactions if needed.
- A cheque voucher can wait in Posting Pending (maker-checker, approval threshold). So `payment.received` / `payment.sent` fire from `Vouchers::afterPost()` for cheque vouchers, which covers both "posted now" and "posted later".
- The clear date can't be in the future, otherwise `Vouchers::draft()` would make a scheduled voucher that posts itself.
- A receivable / payable ledger needs a profile (the subledger), otherwise the due would not move.
- In cheque mode the voucher form hides attachments, because a cheque is not a voucher yet.
- Owners: the cheque rules start **on** (SMS). They are low volume and were asked for explicitly. `payable.due` to owners starts off. Staff and accountants get in-app alerts, and accountants also get email.
- The Hub sends only while Notification Center › Settings has it and the channel switched on (both are on by default).

## Code review (after implementation)

Fixed:
- `VoucherForm`: the "cheque no." validation label was also applied to bKash / bank references. It is now set only in cheque mode, and `chequeMode()` is no longer a public Livewire action.
- `ScheduledNotifications::ensureRules()` compared a row count, so a stale extra rule row could hide a missing one. It now checks every (event, audience) pair.
- `Cheques::bounce()` had an assignment buried inside the update array. Moved it out.
- `NotificationCatalog.php` had picked up CRLF line endings from a script. Normalised back to LF.

Known limits (by design):
- A cheque whose voucher has **posted** can't be bounced or undone. If the bank reverses it later, reverse the voucher in Transactions; the cheque stays "cleared".
- The clearing voucher follows the normal checks: maker-checker, approval threshold and the attachment threshold (no attachment in the clear modal). When a check blocks posting, the voucher waits in Posting Pending and the due changes when it is posted there.
- `due.reminder` skips a customer with any open received cheque, even one smaller than the due (like an open promise).
- New `due.reminder` / `promise.due` channel defaults (SMS + email) apply only to rules created from now on. Existing rules keep what an admin set.

## Phase 2 — remaining work (admin panel, relations, charges, reconciliation, Hub)

### Plan

1. **Relations + data:** `cheques.order_payment_id` (links an order's cheque payment) and `cheques.meta` (bills a supplier cheque should settle). `MasterProfile::cheques()`, `OrderPayment::cheque()`, `Cheque::orderPayment()`.
2. **Admin › Sales › Order detail:** Add Payment with method Cheque asks for cheque no, bank and date. The payment is saved as **pending** and linked to a cheque in the register. Confirm / Reject / Edit-to-paid are blocked on it ("Cheques থেকে clear করুন"). The row shows the cheque's state. Clearing marks the payment paid (on the clear date, into the clear account) and posts it through the existing `PostOrderPayment` path, which now carries method + reference + date and returns its entry. Bounce / cancel marks the payment failed.
3. **Admin › Purchase › supplier invoice modal:** "Payment" and "Mark as paid now" by cheque register an issued cheque (supplier's profile, payable), keeping the chosen bills (`meta.bill_ids`). When the clearing voucher posts, those bills are settled first. Advance by cheque stays immediate (with a note).
4. **POS:** no cheque method offered, so nothing to change.
5. **Bounce charges:** the Bounce modal takes an optional bank charge (expense voucher Dr 5300 / Cr bank) and, for a received cheque, a fee charged to the customer (journal Dr receivable / Cr 4150 Late Fee Income). Both are drafts in Posting Pending.
6. **Bank reconciliation (Cash & Bank):** when the statement differs from the books by an open cheque's amount on that account, suggest "Clear cheque #…". The suggestion opens the Cheques page with its Clear modal.
7. **Hub takes over kisti / EMI:** new daily events `kisti.due` (customer SMS + email the day before and on the day, staff digest) and `loan.emi_due` (owner / staff / accountant, N days before). The old direct SMS paths skip themselves while the matching Hub rule is on and can send SMS, so nothing is sent twice.
8. **Admin UI links:** a "Cheques" item in the admin sidebar (Accounts group, for users with the Accounts Panel). Profile page: an open-cheques card.

### Plan review (against the code)

- `PostCustomerAdvance` took no payment method, so it gained optional `paymentMethod` / `paymentReference` parameters. `PostOrderPayment` now returns its entry. The callers (POS, Order detail) ignore the return value, so nothing else changes.
- An order's customer may have no profile. An order cheque therefore skips the "receivable needs a profile" rule; it clears through the order payment, not through the ledger.
- The Hub is **on by default**, and the old EMI SMS can be switched off in Accounts › Settings. So the new owner (`loan.emi_due`) and customer (`kisti.due`) SMS rules start **off**: the old SMS keeps working as before. Turning a Hub rule on makes the old path stop. Staff and accountant in-app / email for these start on.

### Code review (Phase 2)

Fixed:
- Purchase "Mark as paid now": a duplicate-cheque error was thrown inside the invoice transaction with nothing catching it, so the user saw an error page. It is now caught (the invoice rolls back too) and shown on the cheque no. field.
- `payable.due` reminded about bills already paid by an issued cheque that hasn't cleared. Those bills are skipped now; `cheque.issued_due` covers them.
- `order-detail.blade.php` had turned CRLF in the working copy. Normalised to LF.

Known limits:
- The Payables list still shows a bill paid by a pending cheque as due (it is unpaid until the cheque clears), without a "cheque issued" marker.
- A purchase "Advance" by cheque still posts immediately (with an on-screen note to use "Payment" instead).
- The Order detail Edit on a cheque payment changes only the note. Amount or cheque number changes mean cancelling the cheque and adding a new payment.

## Phase 3 — the last follow-ups

- **Payables:** a bill paid by an issued cheque that hasn't cleared shows "Cheque দেওয়া" with the cheque's no / amount / date. Pay, bulk "Create payments" and the checkbox are blocked on it, so it can't be paid twice. A supplier's cheque that names no bills shows as a note. Received cheques due in 30 days count in the cash plan's inflow.
- **Supplier advance by cheque** (Purchase › Advance, profile › Advance, or any issued cheque against 1150 Supplier Advance) stays a cheque until it clears. Clearing it books the advance the usual way (`ProfileVouchers::supplierAdvance()`: Purchase ledger row + Dr Supplier Advance / Cr bank + an open advance). That action now also carries the method / reference and returns the advance.
- **Editing a cheque before it clears** (`Cheques::amend()`): number, bank, amount, cheque date, note. The reminder date moves with the cheque date unless the cheque was rescheduled. There is an "Edit" action on the Cheques page, and Order detail › Edit on a cheque payment changes the same fields; the pending order payment follows.
- **PDC ledger — intentionally not built.** A PDC ledger (cheques in hand / issued as balance-sheet accounts) is needed when a cheque is booked on receipt. Here nothing is booked until the cheque clears (decision 1), so the register is the sub-ledger. Adding PDC accounts would only add a second, duplicate record.

Code review (Phase 3), fixed: advance cheques (which pay no bill) were shown as the supplier's cheque on Payables rows. They are now left out.

## Recheck (full pass over the added code)

Fixed:
- Cheques page: bounced cheques older than 6 months dropped off the list (and out of the "Bounced" card), although they can still be presented again or cancelled. They are now always listed, like open ones.
- `Cheques::syncOrder()` overwrote a **refunded** order's payment status when its cheque payment changed. A refunded order is now left as it is.
- `Cheques::amend()` had no duplicate check, so editing could give a cheque the number of another open one. The check is now one helper, shared by create and edit.
- The clear toast said "বাকি কমবে" for issued cheques too. It now says দেনা for those.

Checked and fine: every Payables row carries `ref`; the installment / EMI `due_date` columns are DATE (so `whereIn` on date strings is safe); line endings are LF; views compile.

Known edge: if an order (and with it its order payment) is deleted, its cheque loses the link and would then clear as a normal receipt voucher.

## Out of scope / follow-ups

- None open from this spec. A separate PDC ledger is intentionally not built (see above).
