# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CSC TIMS — a Training Information Management System for a Civil Service Commission regional office. It is a rewrite of an older app ("csc-tms" / v1) on a Laravel 13 + Inertia + Vue 3 + Tailwind v4 stack. Domains: trainings, registrations, attendance (QR check-in, incl. an offline scanning station), certificates, payments/refunds/official receipts, request queues (training, cancellation, agency, supervisory documents), and reporting/exports.

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

npm run test               # Vitest — pure-JS helpers and shared components only, not pages

vendor/bin/pint           # formatting (no pint.json — Laravel preset)
vendor/bin/pint --dirty   # only what you touched

php artisan migrate:fresh --seed   # demo data
php artisan tims:send-reminders --days=1
php artisan tims:import-google-avatars
php artisan tims:backup            # db + storage/app/private into one zip (scheduled 02:00)
```

Dev DB and the test suite run on MySQL under XAMPP (dev `csc_tims-db`, tests `csc_tims_test`); `tests/TestCase.php` auto-creates the test database, so `composer test` needs no manual SQL — but MySQL must be running. `phpunit.xml` pins queue to `sync` and mail to `array`, so queued notifications execute inline in tests.

Seeded demo logins (password `Password123`): `superadmin@csc.gov.ph`, `admin@csc.gov.ph`, `fieldoffice@csc.gov.ph`, `management@csc.gov.ph`, `participant@example.com`. `DatabaseSeeder` order matters: offices → demo logins → randomised users → activity.

`.env` details that bite: `APP_URL` is baked into queued mail and into certificates at render time, so a wrong value is permanent in already-issued documents. `VITE_DEV_ORIGIN` exists for tunnelled dev (VS Code port forwarding / ngrok), which also needs `TRUSTED_PROXIES=*` — proxy trust defaults to *nothing*, because `X-Forwarded-For` is what every IP-keyed `throttle:` counts against and trusting it blindly hands out a fresh rate-limit bucket per forged header (`TrustProxiesTest` guards the default). Google sign-in needs `GOOGLE_CLIENT_ID`/`SECRET`/`REDIRECT_URI`, and the callback host must match the host the flow started on.

## Architecture

**Controllers stay thin; the domain lives in `app/Support/*Service.php`.** `RegistrationService`, `CertificateService`, `PaymentService`, `RefundService`, `AttendanceService`, `ScanStationService`, `CancellationRequestService`, `TrainingRequestService`, `AgencyRequestService`, `PhysicalOrRequestService`, `SupervisoryDocumentService`, `WalkInService`, `RescheduleService`, `RevenueService`, `ProfileService`, `UndoService` hold the rules — capacity locking, idempotent issuance, status transitions, notification dispatch. When adding behaviour, extend the service, not the controller. Services are static-method classes wrapping `DB::transaction` with `lockForUpdate` where a race is possible (see `RegistrationService::register`).

**Authorization is route-middleware, not policies.** `EnsureUserIsStaff` takes a pipe-separated role list as a parameter: `EnsureUserIsStaff::class.':admin|superadmin'`. `routes/web.php` is therefore the authoritative map of who can do what, and its comments explain the *why* of each grouping (payments → collecting officer, trainings → HRD/admin, attendance → any staff). Add new admin routes inside the right role group rather than checking roles in a controller. `EnsureUserCollectsPayments` is the money gate — it checks `User::collectsPayments()`, not a bare role.

`EnsureProfileIsComplete` gates the entire participant area and redirects to `profile.complete`; the profile form itself, password change, profile photo, and email verification are deliberately registered outside that group.

**Roles** (`App\Enums\Role`): participant, field-office, collecting-officer, admin, management, superadmin. `Role::staff()` / `isStaff()` / `financial()` are the predicates; `Role::financial()` is who reaches money screens by job title, with everyone else needing the collecting-officer designation.

**Field-office scoping is the main security invariant.** A `field-office` user sees only participants of their own office; everyone else sees the region. `User::isScopedToFieldOffice()` / `scopedFieldOfficeId()` drive this, and the scoped id resolves to `0` (matching nothing) when unassigned — failing closed. Scoping is applied inside each controller query, and `tests/Feature/ExportScopingTest.php` and `FieldOfficeScopingTest.php` are the guard on it. Any new list, export, or detail view must apply the same filter and grow a test there.

**Undo window.** Staff roster decisions are reversible for `UndoService::WINDOW_SECONDS` (30s). The snapshot lives in the actor's *session* keyed by an opaque token — never in the Inertia payload — and only the fields a decision writes are captured. Participant notifications are `->delay()`ed by the same window so an undone decision is never mailed. Keep those two numbers tied together.

**Attendance has two doors into one service.** `/admin/scanner` (signed-in staff) and `/station/{token}` (a shareable link, unauthenticated, unlocked by a six-digit code) both go through `ScanStationService`, which takes an *actor* and a *scope* rather than a request. That is the whole security argument for the public door: a link can never read or write more than its issuer could. New behaviour belongs in the service so the public door cannot drift more permissive than the staff one — `ScanLinkTest` is the guard.

The station is offline-first: `public/scanner-sw.js` plus `resources/js/scanner/{station,camera,resolve,store,sync}.js` cache the roster and queue scans on the device. Consequences to preserve: the roster response stamps its watermark *before* the query (stamped after, a busy door loses rows forever); sync is idempotent; the device stores a digest, never the raw token; and `station/*/unlock` and `station/*/sync` are CSRF-exempt in `bootstrap/app.php` because a page served hours ago to an offline device has no live token — the encrypted grant is what authorises them.

**Enums back every status column** (`RegistrationStatus`, `PaymentStatus`, `RefundStatus`, `AttendanceStatus`, `TrainingStatus`, `TrainingMode`, `TrainingLevel`, `RequestStatus`, `AgencyRequestStatus`, `PhysicalOrRequestStatus`, `SupervisoryDocumentStatus`, `PaymentMethod`, `ChargeTo`, `Curriculum`) and carry their own predicates (`isOpenToParticipants()` etc.). Put transition logic on the enum, not in `match` blocks scattered around.

**SME evaluations are keyed to a training *day*, not a training.** `SubjectMatterExpert` is reference data (like a field office: deactivate, never delete — `sme_evaluations` restricts the delete). Experts are attached to a run through `training_subject_matter_expert`, whose `days` column narrows an expert to particular day numbers, with null meaning the whole run — read it via `Training::daysForExpert()` / `expertsForDay()`, never directly. A session that carries over is rated *once, at its end*: an expert is evaluated on the last day of each unbroken stretch they are present for (`expertsEvaluatedOnDay()`), so a two-day run with one expert throughout collects one form on day 2, not two. `Training::evaluationDays()` is therefore the denominator for response rates — never `duration_days`. A submission is one `TrainingDayEvaluation` per (registration, day), unique in the database, owning one `SmeEvaluation` per expert; `SmeEvaluationService` owns every rule (which days are open, who may be rated, the aggregates) so the list, the form and the POST cannot disagree. Certificates' signature line is `signatory_name` — the old `facilitator_name` renamed — and is deliberately not one of the experts.

**Certificates** are rendered to PDF once at release (dompdf) and stored on the private `local` disk, then served through an authorising controller — a template change must not alter documents already in circulation. Public, throttled verification lives at `/verify/{code}`.

**Private files** (certificates, payment proofs, agency documents, registration outputs) always go through a download controller; never expose them via the `public` disk.

**Audit trail.** `ActivityLogger::record()` is called from the *services*, not from controllers or model events — the services are the choke point every state change already passes through, and they know *why* something changed. Logging failures are swallowed and reported to the app log: an audit row is never allowed to roll back a verified payment.

**Mail is data, not just Blade.** `EmailTemplate` + `EmailTemplateRenderer` let staff edit outgoing copy under `/admin/emails`, `EmailLog` records what went out, and `Notifications\Concerns\BrandsMail` applies the shared branding. Participant-facing mail goes through `ParticipantNotification`. The masthead seal is *embedded* in each message (`MailBranding` + the `EmbedMailLogo` listener on `MessageSending`) rather than linked, because a recipient's mail client cannot fetch an image from a non-public host — `MAIL_LOGO_URL` is now only an opt-in to link it from a CDN instead.

**Maintenance mode is app-level, not `artisan down`.** `EnsureSiteIsAvailable` reads `SiteSetting`, runs before Inertia shares props (so a closed site builds no page payload and counts no visitors), and exempts the named `maintenance` route so the notice can never close itself.

**Exports** stream through `App\Support\Exports\SpreadsheetExport` (openspout), not an Excel package. Reporting scope lives in `ReportScope` + `RevenueService`; the shell's badge counts come from `PendingActionCounter`.

## Frontend

- Inertia pages resolve from `resources/js/Pages/**/*.vue`; title template is `"{title} - CSC TIMS"` (`resources/js/app.js`). `@` aliases `resources/js`.
- `HandleInertiaRequests::share()` provides `auth.user` (incl. `role`, `role_label`, `profile_completed`), `unreadNotifications`, `visitors`, and `flash.{success,error,undo}`. The `undo` flash is what renders the Undo button in `AppToast`.
- Layouts: `PublicLayout` (marketing/auth-adjacent), `AuthenticatedLayout` (app shell), `LegalLayout`.
- Shared primitives live in `resources/js/Components/App*.vue`. Reuse them (`AppButton`, `AppInput`, `AppSelect`, `AppCard`, `AppBadge`, `AppModal`, `AppPromptModal`, `AppToast`, `AppStat`, `AppEmptyState`, `AppSkeleton`, `AppIcon`) instead of hand-rolling markup. Icons come from `resources/js/icons.js` via `AppIcon`.
- Not everything is an Inertia page: `resources/js/scanner/` (the offline station), `analytics.js`, and `authSplash.js` are standalone entry points.

### Design rules (enforced by `resources/css/app.css`)

Never hardcode hex values — everything is a Tailwind v4 `@theme` token.

- Brand: `--color-csc-blue` carries the layout, white carries content, `--color-csc-red` is an accent used once or twice per viewport, never as a large fill.
- White text on `--color-csc-red` fails AA. Use `--color-csc-red-ink` for red text or red behind white text; reserve `--color-csc-red` for non-text accents.
- Secondary text is `--color-csc-ink-muted` (body copy) and `--color-csc-ink-subtle` (hints, metadata, footnotes), never an opacity on `--color-csc-ink`. `text-csc-ink/70` composites to 4.33:1 on white and 4.03:1 on the tint — under AA — and was the default for every paragraph on the public site until it was swept out. There is no tint-safe step below `subtle`, so don't invent one. The only surviving `text-csc-ink/40` is `AppPagination`'s disabled arrows and its `aria-hidden` ellipsis, where WCAG exempts the control and the faintness is the affordance.
- On `--color-csc-blue`, white text needs `/60` or more (`/40` is 3.04:1); on `--color-csc-blue-deep`, `/60`. Decorative SVG strokes may stay at `/40` — the 3:1 bar for graphical objects.
- Inside the signed-in app the brand red is retired in favour of the semantic set (`--color-success/warning/danger/info` + `-soft` backgrounds), so a red badge never reads as branding. Status is never colour-alone: badges carry an icon and a label.
- Stacking uses the named `--z-*` tokens; don't invent z-indexes.
- Print styles matter — rosters and attendance sheets get printed, so new shell chrome should be hidden in the existing `@media print` block.

## Testing

Feature tests under `tests/Feature` are organised by workflow (registration, attendance, certificates, payments, refunds, request workflows, agency requests, scan links, roster bulk actions, undo, scoping, analytics, seeders). There is no Pest — plain PHPUnit with Laravel's `TestCase`. New domain behaviour belongs in the matching workflow test; new admin surface belongs in `AdminAreaTest`.

Client-side logic that has no server round-trip to exercise it lives outside PHPUnit's reach entirely, so those pieces (`resources/js/dateRange.js`, `resources/js/statusTone.js`, and shared components like `AppFileField`) carry their own `*.test.js` beside the file they test, run with `npm run test` (Vitest + `@vue/test-utils`, config in `vitest.config.js`). This is deliberately narrow — Inertia pages are not unit-tested; the workflow they drive is already covered end-to-end by the matching PHPUnit feature test, and mounting a full page component under jsdom would just be a slower, weaker copy of that.

## Notes

- `docs/prompts/` holds the original build prompts for the UI; useful for design intent, not current state. `docs/prime-hrm-discount.md` documents the one pricing rule that is not derivable from code.
- `README.md` is still stock Laravel — it is not a source of truth for this app.
- Comments in this codebase explain *why*, often at length, and that convention is worth matching. `routes/web.php` in particular is ~800 lines that are as much documentation as routing.
