# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CSC TIMS — a Training Information Management System for a Civil Service Commission regional office. It is a rewrite of an older app ("csc-tms" / v1) on a Laravel 13 + Inertia + Vue 3 + Tailwind v4 stack. Domains: trainings, registrations, attendance (QR check-in), certificates, payments/refunds, request queues, and reporting/exports.

## Commands

```bash
composer setup            # install, .env, key, migrate, npm install, build

composer dev              # php artisan dev — serve + vite + queue + pail together
npm run dev               # vite only (if the app is served by XAMPP/Apache)
npm run build

composer test             # config:clear + php artisan test
php artisan test --filter=ExportScopingTest
php artisan test tests/Feature/UndoTest.php
php artisan test --testsuite=Feature

vendor/bin/pint           # formatting (no pint.json — Laravel preset)

php artisan migrate:fresh --seed   # demo data
php artisan tims:send-reminders --days=1
```

Dev DB is MySQL (`csc_tims-db` under XAMPP); tests run on in-memory SQLite via `phpunit.xml`, so migrations must stay SQLite-compatible.

Seeded demo logins (password `Password123`): `superadmin@csc.gov.ph`, `admin@csc.gov.ph`, `fieldoffice@csc.gov.ph`, `management@csc.gov.ph`, `participant@example.com`. `DatabaseSeeder` order matters: offices → demo logins → randomised users → activity.

## Architecture

**Controllers stay thin; the domain lives in `app/Support/*Service.php`.** `RegistrationService`, `CertificateService`, `PaymentService`, `AttendanceService`, `CancellationRequestService`, `TrainingRequestService`, `UndoService` hold the rules — capacity locking, idempotent issuance, status transitions, notification dispatch. When adding behaviour, extend the service, not the controller. Services are static-method classes wrapping `DB::transaction` with `lockForUpdate` where a race is possible (see `RegistrationService::register`).

**Authorization is route-middleware, not policies.** `EnsureUserIsStaff` takes a pipe-separated role list as a parameter: `EnsureUserIsStaff::class.':admin|superadmin'`. `routes/web.php` is therefore the authoritative map of who can do what, and its comments explain the *why* of each grouping (payments → collecting officer, trainings → HRD/admin, attendance → any staff). Add new admin routes inside the right role group rather than checking roles in a controller.

`EnsureProfileIsComplete` gates the entire participant area and redirects to `profile.complete`; the profile form itself is deliberately registered outside that group.

**Roles** (`App\Enums\Role`): participant, field-office, collecting-officer, admin, management, superadmin. `Role::staff()` / `isStaff()` / `handlesPayments()` are the predicates.

**Field-office scoping is the main security invariant.** A `field-office` user sees only participants of their own office; everyone else sees the region. `User::isScopedToFieldOffice()` / `scopedFieldOfficeId()` drive this, and the scoped id resolves to `0` (matching nothing) when unassigned — failing closed. Scoping is applied inside each controller query, and `tests/Feature/ExportScopingTest.php` and `FieldOfficeScopingTest.php` are the guard on it. Any new list, export, or detail view must apply the same filter and grow a test there.

**Undo window.** Staff roster decisions are reversible for `UndoService::WINDOW_SECONDS` (30s). The snapshot lives in the actor's *session* keyed by an opaque token — never in the Inertia payload — and only the fields a decision writes are captured. Participant notifications are `->delay()`ed by the same window so an undone decision is never mailed. Keep those two numbers tied together.

**Enums back every status column** (`RegistrationStatus`, `PaymentStatus`, `AttendanceStatus`, `TrainingStatus`, `TrainingMode`, `RequestStatus`) and carry their own predicates (`isOpenToParticipants()` etc.). Put transition logic on the enum, not in `match` blocks scattered around.

**Certificates** are rendered to PDF once at release (dompdf) and stored on the private `local` disk, then served through an authorising controller — a template change must not alter documents already in circulation. Public, throttled verification lives at `/verify/{code}`.

**Private files** (certificates, payment proofs, registration outputs) always go through a download controller; never expose them via the `public` disk.

## Frontend

- Inertia pages resolve from `resources/js/Pages/**/*.vue`; title template is `"{title} - CSC TIMS"` (`resources/js/app.js`). `@` aliases `resources/js`.
- `HandleInertiaRequests::share()` provides `auth.user` (incl. `role`, `role_label`, `profile_completed`), `unreadNotifications`, `visitors`, and `flash.{success,error,undo}`. The `undo` flash is what renders the Undo button in `AppToast`.
- Layouts: `PublicLayout` (marketing/auth-adjacent), `AuthenticatedLayout` (app shell), `LegalLayout`.
- Shared primitives live in `resources/js/Components/App*.vue`. Reuse them (`AppButton`, `AppInput`, `AppSelect`, `AppCard`, `AppBadge`, `AppModal`, `AppPromptModal`, `AppToast`, `AppStat`, `AppEmptyState`, `AppSkeleton`, `AppIcon`) instead of hand-rolling markup. Icons come from `resources/js/icons.js` via `AppIcon`.

### Design rules (enforced by `resources/css/app.css`)

Never hardcode hex values — everything is a Tailwind v4 `@theme` token.

- Brand: `--color-csc-blue` carries the layout, white carries content, `--color-csc-red` is an accent used once or twice per viewport, never as a large fill.
- White text on `--color-csc-red` fails AA. Use `--color-csc-red-ink` for red text or red behind white text; reserve `--color-csc-red` for non-text accents.
- Inside the signed-in app the brand red is retired in favour of the semantic set (`--color-success/warning/danger/info` + `-soft` backgrounds), so a red badge never reads as branding. Status is never colour-alone: badges carry an icon and a label.
- Stacking uses the named `--z-*` tokens; don't invent z-indexes.
- Print styles matter — rosters and attendance sheets get printed, so new shell chrome should be hidden in the existing `@media print` block.

## Testing

Feature tests under `tests/Feature` are organised by workflow (registration, attendance, certificates, payments, request workflow, roster bulk actions, undo, scoping, seeders). There is no Pest — plain PHPUnit with Laravel's `TestCase`. New domain behaviour belongs in the matching workflow test; new admin surface belongs in `AdminAreaTest`.

## Notes

- `docs/prompts/` holds the original build prompts for the UI; useful for design intent, not current state.
- Comments in this codebase explain *why*, often at length, and that convention is worth matching.
