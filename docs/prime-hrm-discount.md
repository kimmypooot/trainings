# PRIME-HRM 20% Discount — design & progress

**Status:** signed off, in implementation.

## Context

Participants from agencies accredited under PRIME-HRM (Program to Institutionalize
Meritocracy and Excellence in Human Resource Management) are entitled to a 20%
discount on CSC training fees. The office needs to record when that discount was
granted, and report on revenue per training with the discounted participants
identified and the arithmetic done for them.

**This is new functionality, not a port.** v1 (`csc-tms`) has no discount concept
anywhere — no column, no form field, no report. The only "prime" strings in its
database are an unrelated Gmail address in the OAuth logs. So there is no v1
behaviour to match and no legacy shape to inherit; the design below is ours.

Likewise, **no revenue report exists in v2 today**. `AnalyticsController::payments()`
returns a single global `verified_total`; there is nothing per-training. So the
reporting half is a new surface, not an edit to an existing one.

## The central design decision

> Store the arithmetic, not just the flag.

The obvious implementation is a single boolean on `payments`, with the discount
computed at report time as `training.payment_amount × 0.20`. That is a trap. The
training fee is editable, so raising a course fee next year would silently rewrite
every historical revenue figure for that course — money that was never collected
would appear to have been.

This codebase already refuses that class of bug elsewhere: certificates are
rendered to PDF once at release precisely so "a template change must not alter
documents already in circulation" (CLAUDE.md). Money deserves the same treatment.

So each payment freezes what it was assessed against:

| Column | Meaning |
| --- | --- |
| `prime_hrm_discount` | boolean — the checkbox |
| `discount_amount` | decimal(10,2) — peso value deducted, frozen at recording |
| `amount` | *unchanged* — what was actually received (net) |

Gross is `amount + discount_amount`; the rate is `discount_amount / gross`. Both are
derivable, so neither is stored — but the two figures that cannot be recovered
later are. A fee change from here on cannot touch a closed payment.

The 20% itself lives as one constant (`PaymentService::PRIME_HRM_RATE`). It is
deliberately *not* a row in `payment_settings` alongside the bank details: those are
clerical, a discount rate is policy, and it should take a deploy to change.

## Where the checkbox goes

Three surfaces touch a payment. My recommendation:

1. **Counter-payment modal** (Roster → Record Payment) — officer ticks it while
   entering the OR. **Yes.**
2. **Payment verification** (Payments queue) — officer confirms a participant's
   uploaded proof. **Yes** — this is where an entitlement gets applied to money
   that arrived before anyone checked.
3. **Participant's own upload** (My/Payments) — **no, recommended.** The discount
   is an entitlement CSC verifies against accreditation records. A participant who
   can tick it themselves can decide to pay 80%, and the office is left chasing the
   balance. Let them pay what they were quoted; the officer applies the discount.

## Auto-computation

The checkbox drives the amount, and **the server computes it, never the browser**.
The form posts the *flag*; PHP derives the figures from `training.payment_amount`:

```
discount = round(fee × 0.20, 2)
net      = fee − discount          // subtraction, so the identity always holds
```

Computing both independently and hoping they reconcile is how you get a payment
where gross − discount ≠ net. The Vue previews the same numbers for the officer,
but a client-supplied amount is an amount that can be edited in devtools, so the
amount field goes read-only while the box is ticked.

### No rounding policy

Training fees are always whole, round figures — ₱1,000, ₱1,400, ₱1,500, ₱2,400.
Every such fee divides by 5, so 20% of it is exact to the centavo and no rounding
is ever needed. The code therefore does plain 2-decimal arithmetic and derives the
net by subtraction, which keeps `gross − discount = net` true by construction.

Introducing a rounding rule for a case that cannot arise would be inventing
behaviour nobody can check. If a fee that does not divide cleanly is ever entered,
the arithmetic stays exact and simply carries centavos.

### Guards

- Refuse the discount on a training with no fee (`payment_required = false`).
- A promissory note **may** carry a discount — the note is then written for the
  discounted amount. Optional, not required: the checkbox is available whatever the
  method.

## The revenue report

Two surfaces, sharing one query:

**On-screen, on the Roster** — a card beside the existing "By Field Office"
breakdown: gross assessed, total discount granted, net collected, and the count of
discounted participants. This is where staff already stand when asking the question.

**Export** — `/admin/exports/trainings/{training}/revenue`, one row per paying
participant: name, agency, field office, gross, discount ₱, discount %, net, OR
number, method, date, status. A totals row closes it.

Both field-office scoped, like every other listing and export.

The figure CSC will eventually be asked for — *how much revenue did we forgo to
PRIME-HRM incentives this year* — falls out of `SUM(discount_amount)` for free once
the column exists, so the annual view is cheap to add later.

## Decisions (settled)

1. **Officer only.** No participant self-declaration. The discount is an entitlement
   CSC verifies; a participant who could tick it themselves could decide to pay 80%.
2. **Per participant, ticked fresh each payment.** The entitlement is not stored
   against the participant or their agency. Nothing to keep in sync, nothing to go
   stale when an accreditation lapses, and the officer is accountable for each
   individual grant.
3. **Promissory notes may carry a discount**, optionally — the note is written for
   the discounted amount. The checkbox is available whatever the method.
4. **No rounding.** Fees are always round figures that divide cleanly by 5, so 20%
   is exact. See above.

## Todo

Nothing is started. Ticked items are done and verified.

### Phase 1 — data ✅
- [x] Migration: `payments.prime_hrm_discount` (bool, default false), `payments.discount_amount` (decimal 10,2, default 0). Existing rows backfill to false/0, so historical revenue is unchanged.
- [x] `Payment` model: cast both; add `grossAmount()` and `discountRate()` accessors.
- [x] `PaymentService::PRIME_HRM_RATE` constant + `primeHrmBreakdown(Training)` helper, so no caller does the arithmetic itself.

### Phase 2 — recording ✅
- [x] `PaymentService::recordAtCounter()` accepts the flag; derives gross/discount/net server-side and **ignores any posted amount**.
- [x] `PaymentService::verify()` accepts the flag. Different semantics on purpose: the money has already moved, so the discount explains the amount rather than setting it, and a payment that does not reconcile to the discounted price is refused.
- [x] Validation: refused where the training has no fee. Promissory notes may carry it.
- [x] `ActivityLogger` records the flag and the peso value — forgone revenue belongs in the trail, not only in a column.
- [x] Tests (8): server-side computation ignores a posted amount; a later fee change cannot rewrite a closed payment; refused on a free training; promissory carries it; verification path both ways; participants cannot self-grant.

### Phase 3 — officer UI ✅
- [x] Checkbox in the Roster's Record Payment modal, with a live preview of gross → discount → net; amount read-only while ticked. The preview is a display of the arithmetic; only the flag is posted.
- [x] Checkbox in the Payments queue verification flow, worded for its different meaning ("this payment *is* the discounted fee").
- [x] Payments list shows the discount and the gross/net split on both the table and the mobile card.

### Phase 4 — reporting ✅
- [x] `TrainingController::roster()` payload gains a `revenue` block: assessed, discount, collected, promissory (counted apart — verified but no money arrived), and the named list of discounted participants.
- [x] Revenue card on `Roster.vue`, with the per-participant discount table.
- [x] `ExportController::revenue(Training)` + route, field-office scoped, gated on the collecting-officer designation.
- [x] Export button on the revenue card.

### Phase 5 — tests ✅
- [x] Discount computed server-side, ignoring a client-supplied amount.
- [x] `gross − discount = net` holds; revenue totals reconcile.
- [x] A fee change after the fact does not alter a closed payment.
- [x] Discount refused on a free training; refused when it does not reconcile at verification.
- [x] Revenue totals right with a mix of discounted, full-price and promissory payments.
- [x] Revenue export identifies the discounted participants and is closed to staff without the till.
- [x] Participants cannot self-grant the discount.

### Phase 6 — verification
- [x] Full feature suite green (603 tests, 3683 assertions); `npm run build` clean; `vendor/bin/pint` clean.
- [x] **Server-side pass against real seeded data** (dev DB, training 3 "Leadership Development Program", fee ₱2,500):
  - `primeHrmBreakdown()` → gross 2500 / discount 500 / net 2000.
  - `recordAtCounter()` posted with a deliberately wrong amount of ₱99,999 → stored ₱2,000 net, ₱500 discount, ₱2,500 gross, 20%, verified, officer stamped.
  - Roster `revenue` payload → assessed ₱10,000, discount ₱500, collected ₱9,500 (identity holds), promissory ₱7,500 across 3 counted apart, one discounted participant named with correct figures.
  - Revenue export → discounted row marked `Yes (20%)` with its OR number and collecting officer; full-price rows marked `No`.
  - Fee raised to ₱5,000 → the closed payment stayed at ₱2,000 / ₱500 / ₱2,500. Fee restored.
- [x] **Browser pass** — driven through Chrome as CSC SUPER ADMINISTRATOR against the dev app:
  - Revenue card renders: assessed ₱10,000 / discount −₱500 / collected ₱9,500 / promissory ₱7,500 (3 outstanding), with the Export button and the named discount table.
  - Record Payment modal opens with the amount pre-filled at the full fee (₱2,500).
  - Ticking PRIME-HRM shows the breakdown (₱2,500 / −₱500 / ₱2,000), rewrites the amount to 2000 and adds the "Set by the discount." hint.
  - The amount field is genuinely read-only — typing `999` into it left `2000` unchanged.
  - Unticking reverts to ₱2,500 and removes the breakdown; the watcher works both ways.
  - Submitting recorded the payment, toasted, and replaced the row's button with the OR number. Revenue updated to ₱12,500 / −₱1,000 / ₱11,500 with 2 participants named — the identity still holds.
  - `canRecordPayment()` correctly hides the button on an already-settled row, and shows "Payment awaiting review" where an upload is pending.
  - Verification modal: ticking the discount on a full-price ₱1,200 payment against a ₱1,200 fee produced *"A PRIME-HRM payment for this training should be PHP 960.00, but this one is for PHP 1,200.00. Resolve the difference before applying the discount."* and left the payment Pending. The reconciliation guard fires.

## Notes on the dev database

The dev DB was empty when this started (1 user, no trainings), so `db:seed` was run to
have something to work against. The verification pass left two real payment records on
training 3 plus their activity-log entries: `OR-MANUAL-1786870645` and `OR-BROWSER-001`,
both PRIME-HRM discounted. Delete them if the demo data should read clean.
