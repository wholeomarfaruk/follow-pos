# Stock quantity in two units (primary + converted)

Status: done (2026-10-24) — all four phases built; browser check pending

## Goal

Every stock quantity in admin shows in the **stock unit** (Settings › Default Unit)
and, when switched on, a **converted unit** next to it — e.g. fabric `5 m · 5.47 yd`.
The converted unit comes from Settings; any viewer can also flip quantities to
another unit of the same kind on the spot (feet, inch, cm …) without changing
Settings.

## Decisions (owner, 2026-10-24)

1. **One global unit** for all stock — Settings › Localization › Default Unit. No
   per-product unit.
2. **One converted unit**, chosen in Settings.
3. **Display only** for now — quantities are still typed and stored in the stock
   unit. Typing in another unit is a later phase.
4. Settings live in **Site Settings › Localization**, next to Default Unit.

## What exists today (research)

- No unit on products / variants / stock tables; every quantity is a bare
  `decimal(20,3)`.
- `localization.default_unit` (default `meter`), list in `SiteSettings::UNITS`;
  saved, not used anywhere yet.
- Quantities are printed as plain numbers (`number_format($qty, 3)` or small
  per-file helpers) on ~20 admin views, mainly:
  inventory/stock-list, stock-detail (batches, movements), batches, movement-list,
  stock-in, receivings, receiving-show; catalog/products, product-stocks,
  product-variants, product-edit; sales/pos-screen, pack-order-modal, orders,
  bulk-order-create; purchase/purchase-order-form; dashboards / accounts stock report.

## Rules

- Quantities stay **stored in the stock unit**; conversion is display-only — no data
  migration, no change to stock math, batches, COGS or accounts.
- Changing Default Unit later only relabels: it does **not** convert stored numbers.
  The Settings screen says so under the field.
- The converted unit must be the same kind as the stock unit (meter → yard / feet /
  inch / cm). The Settings dropdown only lists units of that kind; if Default Unit is
  changed to another kind, a mismatched converted unit is ignored (primary only) and
  cleared on save.

## Unit catalog (one place: `App\Support\Units`)

Replaces `SiteSettings::UNITS`. Each unit has a kind, a short label and a factor to
the kind's base.

| Kind   | Units (factor to base)                                      |
|--------|-------------------------------------------------------------|
| length | meter (m) 1 · yard (yd) 0.9144 · feet (ft) 0.3048 · inch (in) 0.0254 · cm 0.01 |
| weight | kg 1 · gram (g) 0.001                                       |
| volume | liter (L) 1 · ml 0.001                                      |
| count  | pcs 1 · dozen 12 · pair 2                                   |
| other  | box · set — no conversion                                   |

`Units::convert(5, 'meter', 'yard') = 5.468`; across kinds → null.

## Settings (Site Settings › Localization)

- Default Unit (exists) — + note "changing this doesn't convert existing stock".
- **Show converted unit** — on / off (default off).
- **Converted unit** — units of the stock unit's kind (default yard for meter).
  Hidden when the stock unit has no conversions (box, set).

## Display

- One Blade component `<x-qty :value="$qty" />` → `5 m`, or with the toggle on
  `5 m · 5.47 yd`. Reads the Settings once per request (helper `Units::display()`).
- **Instant other unit**: click the unit → menu of the same kind (m / yd / ft / in /
  cm); picking one re-renders every `<x-qty>` on the page in that unit (Alpine
  store, client-side, no request). Remembered per viewer in localStorage; "Reset"
  returns to the Settings units.
- Numbers: up to 3 decimals, trailing zeros trimmed (`5`, `5.47`, `0.125`).

## Phases (one issue each under `issues/`)

1. **Foundation** — `Units` catalog (SiteSettings uses it), Settings toggle +
   converted unit + note, `<x-qty>` component + Alpine switcher.
2. **Inventory & catalog views** — stock list, stock detail (batches, movements),
   batches, movement list, product stocks / variants / list.
3. **Sales & purchase views** — POS cart stock line, pack modal, orders, bulk order,
   stock-in, receivings, purchase order form.
4. **Reports** — accounts stock valuation, dashboard stock tiles.

Later (not now): typing a quantity in another unit, price per converted unit,
storefront.

## Comments
