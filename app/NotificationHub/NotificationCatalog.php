<?php

namespace App\NotificationHub;

use App\Models\NotificationRule;
use Illuminate\Support\Collection;

/**
 * Every business event the Notification Hub knows, in code: who it can go
 * to, which placeholders its texts can use and the default rule per
 * audience. Admins edit the rules (Notification Center › Rules); this file
 * only seeds them.
 *
 * Defaults are deliberately conservative: messages that cost money or reach
 * outsiders (customer / supplier SMS) start switched off; in-app alerts to
 * staff start on.
 */
class NotificationCatalog
{
    public const CHANNELS = ['sms', 'email', 'push', 'database'];

    public const CHANNEL_LABELS = [
        'sms' => 'SMS',
        'email' => 'Email',
        'push' => 'Web push',
        'database' => 'In-app',
    ];

    public const AUDIENCES = [
        'customer' => 'Customer',
        'supplier' => 'Supplier',
        'employee' => 'Employee',
        'owner' => 'Owners',
        'staff' => 'Staff',
        'accountant' => 'Accountants',
        'lead' => 'Lead',
        'assignee' => 'Assigned staff',
    ];

    /** Audiences that are staff users (have a login, so push / in-app work). */
    public const STAFF_AUDIENCES = ['staff', 'assignee', 'accountant'];

    /**
     * Categories decide quiet hours and opt-outs: transactional goes out at
     * any hour; marketing is the one customers can opt out of separately.
     */
    public const CATEGORIES = [
        'transactional' => 'Transactional',
        'reminder' => 'Reminder',
        'alert' => 'Alert',
        'marketing' => 'Marketing',
    ];

    public static function events(): array
    {
        return [
            // ── Sales ──────────────────────────────────────────────
            'order.placed' => [
                'label' => 'Order placed',
                'group' => 'Sales',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'As soon as an order is created (website, admin, bulk, POS…).',
                'placeholders' => ['name', 'order_id', 'amount', 'due', 'source', 'website_tracking_url', 'business'],
                'conditions' => [
                    'exclude_sources' => ['label' => 'Skip orders from', 'type' => 'sources', 'default' => ['pos']],
                ],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms'],
                        'sms' => 'প্রিয় {name}, আপনার অর্ডার #{order_id} ({amount} টাকা) গ্রহণ করা হয়েছে। ধন্যবাদ — {business}',
                        'email_subject' => 'Order #{order_id} received',
                        'email' => "Hi {name},\n\nWe have received your order #{order_id} for {amount} Tk. We will let you know when it ships.\n\n{business}",
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database'],
                        'push_title' => 'New order #{order_id}',
                        'push' => '{name} · {amount} Tk · {source}',
                    ],
                ],
            ],
            'order.big' => [
                'label' => 'Big order alert',
                'group' => 'Sales',
                'category' => 'alert',
                'trigger' => 'instant',
                'description' => 'An order at or above the amount set below.',
                'placeholders' => ['name', 'order_id', 'amount', 'source', 'business'],
                'conditions' => [
                    'min_total' => ['label' => 'Order total at least (Tk)', 'type' => 'number', 'default' => 20000],
                ],
                'audiences' => [
                    'owner' => [
                        'channels' => ['sms'],
                        'sms' => 'বড় অর্ডার #{order_id}: {amount} টাকা ({name}, {source}) — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'push_title' => 'Big order #{order_id}',
                        'push' => '{amount} Tk from {name} ({source})',
                    ],
                ],
            ],
            'order.delivered' => [
                'label' => 'Order delivered',
                'group' => 'Sales',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'When an order moves to Delivered.',
                'placeholders' => ['name', 'order_id', 'amount', 'business'],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms'],
                        'sms' => 'প্রিয় {name}, আপনার অর্ডার #{order_id} ডেলিভারি হয়েছে। আমাদের সাথে থাকার জন্য ধন্যবাদ — {business}',
                        'email_subject' => 'Order #{order_id} delivered',
                        'email' => "Hi {name},\n\nYour order #{order_id} has been delivered. Thank you for shopping with us.\n\n{business}",
                    ],
                ],
            ],
            'order.cancelled' => [
                'label' => 'Order cancelled',
                'group' => 'Sales',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'When an order moves to Cancelled.',
                'placeholders' => ['name', 'order_id', 'amount', 'business'],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms'],
                        'sms' => 'প্রিয় {name}, আপনার অর্ডার #{order_id} বাতিল করা হয়েছে। প্রশ্ন থাকলে আমাদের জানান — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database'],
                        'push_title' => 'Order #{order_id} cancelled',
                        'push' => '{name} · {amount} Tk',
                    ],
                ],
            ],

            // ── Accounts / collection ─────────────────────────────
            'payment.received' => [
                'label' => 'Payment received',
                'group' => 'Accounts',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'Accounts › Profile › Collect: a money receipt to the customer.',
                'placeholders' => ['name', 'amount', 'due', 'date', 'business'],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms'],
                        'sms' => 'প্রিয় {name}, {date} তারিখে {amount} টাকা পেয়েছি। বাকি: {due} টাকা। ধন্যবাদ — {business}',
                        'email_subject' => 'Payment received — {amount} Tk',
                        'email' => "Dear {name},\n\nWe received {amount} Tk on {date}. Remaining due: {due} Tk.\n\nThank you,\n{business}",
                    ],
                ],
            ],
            'payment.sent' => [
                'label' => 'Payment made to supplier',
                'group' => 'Accounts',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'Accounts › Profile › Pay: lets the supplier know the money is on its way.',
                'placeholders' => ['name', 'amount', 'date', 'business'],
                'audiences' => [
                    'supplier' => [
                        'channels' => ['sms'],
                        'sms' => 'প্রিয় {name}, {date} তারিখে আপনাকে {amount} টাকা পরিশোধ করা হয়েছে — {business}',
                    ],
                ],
            ],
            'due.reminder' => [
                'label' => 'Due collection reminder',
                'group' => 'Accounts',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Customers who owe at least the amount below, repeated every N days. Skips anyone with an open promise to pay or a cheque not cleared yet. Email goes only to customers who have one.',
                'placeholders' => ['name', 'due', 'date', 'business', 'count', 'total', 'list'],
                'conditions' => [
                    'min_amount' => ['label' => 'Remind when due is at least (Tk)', 'type' => 'number', 'default' => 500],
                    'repeat_days' => ['label' => 'Repeat every (days)', 'type' => 'number', 'default' => 7],
                ],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms', 'email'],
                        'send_time' => '11:00',
                        'sms' => 'প্রিয় {name}, {date} পর্যন্ত আপনার কাছে {due} টাকা বাকি আছে। অনুগ্রহ করে পরিশোধ করুন — {business}',
                        'email_subject' => 'Payment reminder — {due} Tk due',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database'],
                        'send_time' => '10:00',
                        'push_title' => 'Collections today',
                        'push' => '{count} customers owe {total} Tk. Top: {list}',
                    ],
                ],
            ],
            'promise.due' => [
                'label' => 'Promise to pay / callback due',
                'group' => 'Accounts',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Promises and callbacks logged on a profile, on their date (or days before, via offset).',
                'placeholders' => ['name', 'amount', 'date', 'business', 'count', 'list'],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms', 'email'],
                        'send_time' => '10:00',
                        'sms' => 'প্রিয় {name}, {date} তারিখে {amount} টাকা পরিশোধের কথা ছিল। মনে করিয়ে দিচ্ছি — {business}',
                        'email_subject' => 'Payment reminder — {amount} Tk on {date}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database'],
                        'send_time' => '09:30',
                        'push_title' => 'Promises & callbacks today',
                        'push' => '{count} due today: {list}',
                    ],
                ],
            ],
            'payable.due' => [
                'label' => 'Payments due (আমি দিবো)',
                'group' => 'Accounts',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Supplier bills, supplier kisti, loan EMI, salary and recurring bills due within the days below (Accounts › Payables).',
                'placeholders' => ['count', 'total', 'list', 'overdue', 'date', 'business'],
                'conditions' => [
                    'days_before' => ['label' => 'Remind about payments due within (days)', 'type' => 'number', 'default' => 1],
                ],
                'audiences' => [
                    'owner' => [
                        'channels' => ['sms'],
                        'send_time' => '09:00',
                        'sms' => 'দিতে হবে: {count}টা payment, মোট {total} টাকা — {list}। মেয়াদ পার: {overdue}টা — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'send_time' => '09:00',
                        'push_title' => 'Payments due',
                        'push' => '{count} payment(s) · {total} Tk: {list} · overdue {overdue}',
                    ],
                    'accountant' => [
                        'active' => true,
                        'channels' => ['database', 'email'],
                        'send_time' => '09:00',
                        'email_subject' => 'Payments due — {total} Tk',
                        'email' => "{count} payment(s) are due soon, {total} Tk in all:\n{list}\n\nOverdue: {overdue}\n\n{business}",
                        'push_title' => 'Payments due',
                        'push' => '{count} payment(s) · {total} Tk: {list} · overdue {overdue}',
                    ],
                ],
            ],

            'kisti.due' => [
                'label' => 'Kisti due (customer)',
                'group' => 'Accounts',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Customer kisti (Loans & EMI › kisti sales), the days below before and on the due date. While the customer rule is on with SMS, the older Accounts › Settings kisti SMS stops (no double SMS).',
                'placeholders' => ['name', 'amount', 'kisti_no', 'date', 'when', 'count', 'total', 'list', 'business'],
                'conditions' => [
                    'days_before' => ['label' => 'Also remind this many days before the due date', 'type' => 'number', 'default' => 1],
                ],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms', 'email'],
                        'send_time' => '09:00',
                        'sms' => 'প্রিয় {name}, আপনার কিস্তি {kisti_no} — {amount} টাকা {when} ({date}) দেওয়ার কথা। ধন্যবাদ — {business}',
                        'email_subject' => 'Kisti {kisti_no} — {amount} Tk due {date}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database'],
                        'send_time' => '09:00',
                        'push_title' => 'Kisti to collect',
                        'push' => '{count} kisti · {total} Tk: {list}',
                    ],
                    'accountant' => [
                        'active' => true,
                        'channels' => ['database'],
                        'send_time' => '09:00',
                        'push_title' => 'Kisti to collect',
                        'push' => '{count} kisti · {total} Tk: {list}',
                    ],
                ],
            ],
            'loan.emi_due' => [
                'label' => 'Loan EMI due (আমি দিবো)',
                'group' => 'Accounts',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Loan EMI the days below before its date. While the owner rule is on with SMS, the older Accounts › Settings "EMI in 3 days" SMS stops (no double SMS).',
                'placeholders' => ['count', 'total', 'list', 'date', 'when', 'business'],
                'conditions' => [
                    'days_before' => ['label' => 'Remind this many days before the EMI date', 'type' => 'number', 'default' => 3],
                ],
                'audiences' => [
                    'owner' => [
                        'channels' => ['sms'],
                        'send_time' => '09:30',
                        'sms' => 'Loan EMI {when} ({date}): {list} — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'send_time' => '09:30',
                        'push_title' => 'Loan EMI {when}',
                        'push' => '{count} EMI · {total} Tk on {date}: {list}',
                    ],
                    'accountant' => [
                        'active' => true,
                        'channels' => ['database', 'email'],
                        'send_time' => '09:30',
                        'email_subject' => 'Loan EMI on {date} — {total} Tk',
                        'email' => "{count} loan EMI(s) are due on {date}, {total} Tk in all:\n{list}\n\n{business}",
                        'push_title' => 'Loan EMI {when}',
                        'push' => '{count} EMI · {total} Tk on {date}: {list}',
                    ],
                ],
            ],

            // ── Cheques ────────────────────────────────────────────
            'cheque.received_due' => [
                'label' => 'Cheque to deposit (received)',
                'group' => 'Cheques',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Cheques we received and still hold, the days below before their date (Accounts › Cheques). Rescheduling moves the reminder.',
                'placeholders' => ['name', 'amount', 'cheque_no', 'bank', 'date', 'count', 'total', 'list', 'business'],
                'conditions' => [
                    'days_before' => ['label' => 'Remind this many days before the cheque date', 'type' => 'number', 'default' => 1],
                ],
                'audiences' => [
                    'owner' => [
                        'active' => true,
                        'channels' => ['sms'],
                        'send_time' => '09:00',
                        'sms' => 'Cheque জমা দিন ({date}): {count}টা, মোট {total} টাকা — {list} — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'send_time' => '09:00',
                        'push_title' => 'Cheques to deposit on {date}',
                        'push' => '{count} cheque(s) · {total} Tk: {list}',
                    ],
                    'accountant' => [
                        'active' => true,
                        'channels' => ['database', 'email'],
                        'send_time' => '09:00',
                        'email_subject' => 'Cheques to deposit on {date} — {total} Tk',
                        'email' => "{count} received cheque(s) can be deposited on {date}, {total} Tk in all:\n{list}\n\n{business}",
                        'push_title' => 'Cheques to deposit on {date}',
                        'push' => '{count} cheque(s) · {total} Tk: {list}',
                    ],
                    'customer' => [
                        'channels' => ['sms', 'email'],
                        'send_time' => '10:00',
                        'sms' => 'প্রিয় {name}, আপনার {bank} cheque নং {cheque_no} ({amount} টাকা) {date} তারিখে জমা দেওয়া হবে। অনুগ্রহ করে account-এ টাকা রাখুন — {business}',
                        'email_subject' => 'Cheque {cheque_no} will be deposited on {date}',
                    ],
                ],
            ],
            'cheque.issued_due' => [
                'label' => 'Our cheque is due (issued)',
                'group' => 'Cheques',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Cheques we gave, every day from the days below before the release date until that date — keep enough in the bank. Rescheduling moves the reminder.',
                'placeholders' => ['count', 'total', 'list', 'balance', 'date', 'business'],
                'conditions' => [
                    'days_before' => ['label' => 'Start reminding this many days before the release date', 'type' => 'number', 'default' => 2],
                ],
                'audiences' => [
                    'owner' => [
                        'active' => true,
                        'channels' => ['sms'],
                        'send_time' => '09:00',
                        'sms' => 'দেওয়া cheque: {count}টা, মোট {total} টাকা — {list}। Bank-এ আছে {balance} টাকা — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'send_time' => '09:00',
                        'push_title' => 'Issued cheques due',
                        'push' => '{count} cheque(s) · {total} Tk: {list} · bank {balance} Tk',
                    ],
                    'accountant' => [
                        'active' => true,
                        'channels' => ['database', 'email'],
                        'send_time' => '09:00',
                        'email_subject' => 'Issued cheques due — {total} Tk',
                        'email' => "{count} cheque(s) we issued are due, {total} Tk in all:\n{list}\n\nBalance in those bank accounts: {balance} Tk\n\n{business}",
                        'push_title' => 'Issued cheques due',
                        'push' => '{count} cheque(s) · {total} Tk: {list} · bank {balance} Tk',
                    ],
                ],
            ],
            'cheque.bounced' => [
                'label' => 'Cheque bounced',
                'group' => 'Cheques',
                'category' => 'alert',
                'trigger' => 'instant',
                'description' => 'Accounts › Cheques › Bounce: the customer (a cheque they gave) or the supplier (a cheque we gave), and our own people.',
                'placeholders' => ['name', 'party', 'amount', 'cheque_no', 'bank', 'direction', 'reason', 'date', 'business'],
                'audiences' => [
                    'customer' => [
                        'active' => true,
                        'channels' => ['sms', 'email'],
                        'sms' => 'প্রিয় {name}, আপনার দেওয়া {bank} cheque নং {cheque_no} ({amount} টাকা) bounce করেছে। অনুগ্রহ করে দ্রুত পরিশোধ করুন — {business}',
                        'email_subject' => 'Cheque {cheque_no} bounced',
                    ],
                    'supplier' => [
                        'active' => true,
                        'channels' => ['sms', 'email'],
                        'sms' => 'প্রিয় {name}, আমাদের দেওয়া cheque নং {cheque_no} ({amount} টাকা) clear হয়নি। শীঘ্রই পরিশোধের ব্যবস্থা করছি — {business}',
                        'email_subject' => 'About our cheque {cheque_no}',
                    ],
                    'owner' => [
                        'active' => true,
                        'channels' => ['sms'],
                        'sms' => 'Cheque bounce: {party} · {cheque_no} · {amount} টাকা ({direction}) — {reason} — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'push_title' => 'Cheque bounced — {party}',
                        'push' => '{direction} {cheque_no} · {amount} Tk · {reason}',
                    ],
                    'accountant' => [
                        'active' => true,
                        'channels' => ['database', 'email'],
                        'email_subject' => 'Cheque bounced — {party}, {amount} Tk',
                        'email' => "{direction} {bank} {cheque_no} ({amount} Tk) from / to {party} bounced on {date}.\nReason: {reason}\n\n{business}",
                        'push_title' => 'Cheque bounced — {party}',
                        'push' => '{direction} {cheque_no} · {amount} Tk · {reason}',
                    ],
                ],
            ],

            // ── HR ─────────────────────────────────────────────────
            'salary.paid' => [
                'label' => 'Salary paid',
                'group' => 'HR',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'Accounts › Payroll › Pay: tells the employee their salary was paid.',
                'placeholders' => ['name', 'amount', 'date', 'business'],
                'audiences' => [
                    'employee' => [
                        'channels' => ['sms'],
                        'sms' => 'প্রিয় {name}, {date} তারিখে আপনার বেতন {amount} টাকা পরিশোধ করা হয়েছে — {business}',
                    ],
                ],
            ],

            // ── Inventory ──────────────────────────────────────────
            'stock.low' => [
                'label' => 'Low stock alert',
                'group' => 'Inventory',
                'category' => 'alert',
                'trigger' => 'daily',
                'description' => 'Variants at or below their reorder level (set on the product variant).',
                'placeholders' => ['count', 'list', 'business'],
                'audiences' => [
                    'owner' => [
                        'channels' => ['sms'],
                        'send_time' => '09:00',
                        'sms' => '{count}টা product এর stock কম: {list} — {business}',
                    ],
                    'staff' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'send_time' => '09:00',
                        'push_title' => 'Low stock: {count} items',
                        'push' => '{list}',
                    ],
                ],
            ],

            // ── Greetings ──────────────────────────────────────────
            'birthday' => [
                'label' => 'Birthday wish',
                'group' => 'Greetings',
                'category' => 'marketing',
                'trigger' => 'daily',
                'description' => 'Profiles whose date of birth is today.',
                'placeholders' => ['name', 'business'],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms'],
                        'send_time' => '10:00',
                        'sms' => 'শুভ জন্মদিন {name}! আপনার দিনটি আনন্দময় হোক — {business}',
                    ],
                    'employee' => [
                        'channels' => ['sms'],
                        'send_time' => '09:00',
                        'sms' => 'শুভ জন্মদিন {name}! {business} পরিবারের পক্ষ থেকে শুভেচ্ছা।',
                    ],
                ],
            ],

            // ── CRM ────────────────────────────────────────────────
            'lead.created' => [
                'label' => 'New lead',
                'group' => 'CRM',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'A lead is added: thank the lead, alert the assigned person.',
                'placeholders' => ['name', 'phone', 'source', 'interest', 'code', 'business'],
                'audiences' => [
                    'lead' => [
                        'channels' => ['sms'],
                        'sms' => 'প্রিয় {name}, {business}-এ আগ্রহ দেখানোর জন্য ধন্যবাদ। আমরা শীঘ্রই আপনার সাথে যোগাযোগ করব।',
                    ],
                    'assignee' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'push_title' => 'New lead {code}',
                        'push' => '{name} ({phone}) · {source}',
                    ],
                ],
            ],
            'lead.assigned' => [
                'label' => 'Lead assigned to you',
                'group' => 'CRM',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'A lead is re-assigned to someone else.',
                'placeholders' => ['name', 'phone', 'code', 'assigned_by'],
                'audiences' => [
                    'assignee' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'push_title' => 'Lead {code} assigned to you',
                        'push' => '{name} ({phone}) — by {assigned_by}',
                    ],
                ],
            ],
            'lead.followup_due' => [
                'label' => 'Follow-ups due',
                'group' => 'CRM',
                'category' => 'reminder',
                'trigger' => 'daily',
                'description' => 'Each morning, every person with follow-ups due today or overdue gets their list.',
                'placeholders' => ['count', 'overdue', 'list'],
                'audiences' => [
                    'assignee' => [
                        'active' => true,
                        'channels' => ['database', 'push'],
                        'send_time' => '09:00',
                        'push_title' => '{count} follow-ups today',
                        'push' => '{overdue} overdue. {list}',
                        'email_subject' => 'Your follow-ups for today ({count})',
                        'email' => "Follow-ups due today: {count} ({overdue} overdue)\n\n{list}",
                    ],
                ],
            ],
            'lead.converted' => [
                'label' => 'Lead became a customer',
                'group' => 'CRM',
                'category' => 'transactional',
                'trigger' => 'instant',
                'description' => 'A lead is converted to a customer: a welcome message.',
                'placeholders' => ['name', 'code', 'business'],
                'audiences' => [
                    'customer' => [
                        'channels' => ['sms'],
                        'sms' => 'স্বাগতম {name}! {business}-এর সাথে যুক্ত হওয়ার জন্য ধন্যবাদ।',
                    ],
                    'staff' => [
                        'channels' => ['database'],
                        'push_title' => 'Lead converted',
                        'push' => '{name} ({code}) is now a customer',
                    ],
                ],
            ],
        ];
    }

    public static function event(string $key): ?array
    {
        return self::events()[$key] ?? null;
    }

    /** @return array<string, array<string, array>> group => [key => event] */
    public static function grouped(): array
    {
        return collect(self::events())->groupBy('group', true)->map->all()->all();
    }

    /** Rules for one event, creating any that are missing from the defaults. */
    public static function rulesFor(string $eventKey): Collection
    {
        $event = self::event($eventKey);
        if (! $event) {
            return collect();
        }

        $rules = NotificationRule::where('event_key', $eventKey)->get();

        if ($rules->count() < count($event['audiences'])) {
            self::syncRules($eventKey);
            $rules = NotificationRule::where('event_key', $eventKey)->get();
        }

        return $rules->filter(fn (NotificationRule $r) => isset($event['audiences'][$r->audience]))->values();
    }

    /** Create missing rule rows from the defaults (never overwrites an edited rule). */
    public static function syncRules(?string $onlyEvent = null): void
    {
        foreach (self::events() as $key => $event) {
            if ($onlyEvent && $key !== $onlyEvent) {
                continue;
            }

            foreach ($event['audiences'] as $audience => $def) {
                NotificationRule::firstOrCreate(
                    ['event_key' => $key, 'audience' => $audience],
                    self::defaultsFor($key, $audience),
                );
            }
        }
    }

    /** The rule attributes a fresh install (or "Reset to default") gets. */
    public static function defaultsFor(string $eventKey, string $audience): array
    {
        $event = self::event($eventKey) ?? [];
        $def = $event['audiences'][$audience] ?? [];

        return [
            'is_active' => (bool) ($def['active'] ?? false),
            'channels' => $def['channels'] ?? [],
            'sms_body' => $def['sms'] ?? null,
            'email_subject' => $def['email_subject'] ?? null,
            'email_body' => $def['email'] ?? null,
            'push_title' => $def['push_title'] ?? null,
            'push_body' => $def['push'] ?? null,
            'offset_days' => (int) ($def['offset_days'] ?? 0),
            'send_time' => $def['send_time'] ?? '10:00',
            'conditions' => collect($event['conditions'] ?? [])->map(fn ($c) => $c['default'])->all() ?: null,
        ];
    }

    public static function category(string $eventKey): string
    {
        return self::event($eventKey)['category'] ?? 'transactional';
    }
}
