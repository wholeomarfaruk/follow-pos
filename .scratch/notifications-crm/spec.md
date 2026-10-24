# Notification Hub + CRM (Leads → Customers)

Status: implemented (phase 1) — see "Phase 2 / follow-ups" at the bottom for what is deliberately not built yet.

## 1. Problem

The business needs to message every kind of party it deals with — customers, suppliers,
employees, owners, staff and new leads — over SMS, email, web push and the in-app bell, for
transactional moments (order placed, payment received, salary paid), reminders (due collection,
promise-to-pay, follow-ups), alerts (low stock, big order) and offers (broadcast campaigns).
It also needs a light CRM: capture a lead, move it through a few stages, follow up, convert it
to a customer.

## 2. What already exists (reuse, don't duplicate)

| Need | Existing piece | Decision |
|---|---|---|
| SMS sending + logs | `App\Sms\SmsManager` (drivers, queue, `sms_logs`) | **Reuse** as the SMS channel. |
| Web push | `App\Push\PushManager` + `push_subscriptions` (staff users only) | **Reuse** for users; customers without a login get no push. |
| In-app bell | `notifications` table + `Admin\Topbar\Notifications` (reads `data.message`) | **Reuse**: hub writes `message` + `url`. |
| Party identity | `master_profiles` + `master_profile_roles` (customer/supplier/lender/owner/employee) | **Recipient** for every non-staff audience. Every send is written to the profile timeline. |
| Auth events (login, OTP, reset…) | `notification_events` + `NotificationManager` (User-only) | **Leave alone** — it works for auth; the hub covers business events. |
| Order processing SMS | `SendOrderProcessingSms` | **Leave alone** (already live, sends once). Hub does not define `order.processing`. |
| Kisti reminders | `KistiPlans::runDaily()` | **Leave alone** (already live). Hub does not re-send kisti. |
| Owner/accountant alerts | `AccountsAlerts` | Leave alone. |
| Customer creation | `CreatesMasterProfile` + `Customer` hooks | **Reuse** in lead conversion. |

## 3. Glossary

- **Event** — a business moment with a key (`order.placed`, `due.reminder`) defined in code in
  `NotificationCatalog` (label, category, allowed audiences, placeholders, default texts, trigger).
- **Audience** — who an event goes to: `customer`, `supplier`, `employee`, `owner`, `staff`,
  `lead`, `assignee` (the staff user a lead is assigned to).
- **Rule** — admin-editable row per event × audience: on/off, channels, templates, timing.
- **Dispatch** — one message to one recipient over one channel; the outbox *and* the log.
- **Campaign** — a manual broadcast (offers, announcements) to a segment.
- **Lead** — a prospect not yet a customer; lives in `crm_leads` until converted.
- **Stage** — a pipeline step (New → Contacted → Interested → Negotiation → Won / Lost).

## 4. Data model

- `notification_rules` — event_key, audience (unique pair), is_active, channels json,
  sms_body, email_subject, email_body, push_title, push_body, offset_days, send_time,
  staff_role, conditions json.
- `notification_dispatches` — rule_id, campaign_id, event_key, audience, channel,
  recipient morph (MasterProfile / User / CrmLead), recipient_name, to, subject, body, url,
  status (scheduled|queued|sent|failed|skipped), error, dedupe_key (unique), related morph,
  scheduled_at, sent_at, attempts.
- `notification_opt_outs` — master_profile_id / lead_id, channel (`*` = all), category
  (`marketing` | `all`). Transactional + reminders still go unless category `all`.
- `notification_campaigns` — name, segment + segment_filters json, channels, texts,
  status (draft|scheduled|sending|sent|cancelled), scheduled_at, counters.
- `crm_stages` — name, color, sort, probability, is_won, is_lost.
- `crm_leads` — code (L-0001), name, phone (normalised, indexed), email, company, source,
  stage_id, assigned_to, priority, expected_value, interest, address, tags, notes,
  next_follow_up_at, last_contacted_at, lost_reason, won_at/lost_at, converted_at,
  master_profile_id, customer_id, created_by; soft deletes.
- `crm_activities` — lead_id, user_id, type (note|call|sms|email|meeting|whatsapp|stage|
  follow_up|system), body, outcome, due_at, done_at, meta.

## 5. Event catalog (phase 1)

| Event | Trigger | Audiences | Default channels |
|---|---|---|---|
| `order.placed` | Order created (after commit) | customer, staff | sms / database |
| `order.delivered` | Order status → delivered | customer | sms |
| `order.cancelled` | Order status → cancelled | customer, staff | sms / database |
| `order.big` | Order created with total ≥ threshold | owner, staff | sms / database+push |
| `payment.received` | Accounts › Profile › Collect | customer | sms |
| `payment.sent` | Accounts › Profile › Pay | supplier | sms |
| `salary.paid` | Accounts › Payroll › Pay | employee | sms |
| `due.reminder` | Daily scan, receivable ≥ minimum, every N days | customer, staff (digest) | sms / database |
| `promise.due` | Daily scan, promise / callback due today | customer, staff | sms / database |
| `birthday` | Daily scan, profile DOB = today | customer, employee | sms |
| `stock.low` | Daily scan, variant stock ≤ reorder level | owner, staff (digest) | database+push |
| `lead.created` | Lead created | lead, assignee | sms / database+push |
| `lead.assigned` | Lead (re)assigned | assignee | database+push |
| `lead.followup_due` | Daily scan of follow-ups due today / overdue | assignee (digest) | database+push+email |
| `lead.converted` | Lead converted to customer | lead→customer | sms |

Owner = profiles with role `owner`; Staff = users holding the rule's `staff_role` (default: users
with `crm.manage` for lead events, else superadmin/admin).

## 6. Delivery rules

1. Business code calls `NotificationHub::fire($event, $context)` — it **never throws**.
2. Each active rule for the event resolves recipients → one dispatch per recipient × channel,
   rendered with `{placeholders}`; channel skipped (status `skipped`, reason) when the recipient has
   no phone/email/user, opted out, or the channel is globally off.
3. `dedupe_key` (unique) stops repeats: scheduled scans key on event + subject + date.
4. **Quiet hours** (default 22:00–08:00) push SMS/push of non-urgent categories to the next
   morning (`scheduled_at`); `notifications:flush` (every minute) releases them.
5. Daily SMS cap (optional) — over the cap, dispatches are `skipped` with a reason.
6. Each sent/failed dispatch to a profile is written to its timeline (`MasterProfileActivity`).
7. Failed dispatches can be retried from the Outbox.

## 7. CRM rules

- Phone normalised `01XXXXXXXXX`; creating a lead whose phone matches an open lead or an existing
  profile warns and links instead of duplicating.
- Auto-assign (Settings): none | fixed user | round-robin over users with `crm.manage`.
- Stage change logs a `stage` activity; moving to a won stage offers conversion; lost asks reason.
- Follow-up = an activity with `due_at`; the lead's `next_follow_up_at` = earliest open follow-up.
- **Convert**: reuses an existing profile with the same phone or creates one (role customer) +
  `Customer` row (code CUS-xxxxx), links back, marks won, fires `lead.converted`.
- Users with `crm.view` but not `crm.manage` see only leads assigned to them.

## 8. Screens

Admin panel (`/admin`):
- **CRM** group: Dashboard (`crm.dashboard`), Leads list + Kanban (`crm.leads`), Lead detail
  (`crm.leads.show`), Follow-ups (`crm.followups`), Settings (`crm.settings`: stages, sources,
  auto-assign).
- **Notification Center** group: Overview, Rules (per event × audience, template editor, test
  send), Campaigns (compose → segment → preview count → send now / schedule), Outbox (filter,
  retry), Settings (quiet hours, cap, due-reminder thresholds, big-order threshold, scan time).

Accounts panel: no new pages — payment / salary hooks fire from its actions and every send shows up
on the profile timeline.

## 9. Permissions

`crm.view`, `crm.manage`, `crm_settings.manage`, `notification_center.view`,
`notification_center.manage`, `notification_campaign.manage`. Created **by name** (not fixed id —
ids above 201 are taken by the Accounts Panel permission rows) in a migration (live sites) and in
`PermissionSeeder`; superadmin and admin get all.

## 10. Scheduler

- `notifications:flush` — every minute: release due `scheduled` dispatches + campaigns.
- `notifications:daily-scan` — every 15 min; each scanner runs once per day after its rule's
  `send_time` (dedupe makes re-runs harmless).

## Phase 2 / follow-ups (not built)

- WhatsApp channel (needs a gateway driver).
- Public lead-capture endpoint / landing-page form → lead.
- Customer-facing web push (storefront service worker + guest subscriptions).
- Supplier bill due reminders (needs bill due dates surfaced from `PayablesSchedule`).
- Merging the old auth `notification_events` screen into the Notification Center.
