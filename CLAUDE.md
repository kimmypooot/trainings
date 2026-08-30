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

composer stan             # PHPStan/Larastan level 5, against phpstan-baseline.neon
npm run typecheck         # vue-tsc — checks only files that opt into TS

php artisan tims:types           # regenerate resources/js/types/enums.ts from app/Enums
php artisan tims:types --check   # CI form: fail instead of writing, if it has drifted

php artisan migrate:fresh --seed   # demo data
php artisan tims:send-reminders --days=1
php artisan tims:invite-evaluations --date=2026-01-31 --dry-run
php artisan tims:import-google-avatar user@example.com --force
php artisan tims:backup            # db + storage/app/private into one zip
```

The three `tims:*` jobs are scheduled in `routes/console.php` (reminders 08:00, evaluation invites 17:30, backup 02:00), every one of them `withoutOverlapping` — a slow run must never be started underneath itself. `tims:types` is not one of them: it is a build-time generator, not a job.

**CI runs six gates** (`.github/workflows/ci.yml`): Pint, `tims:types --check`, PHPStan, PHPUnit on a MySQL service, `vue-tsc`, and the Vite build. Two of them are easy to misread:

- **PHPStan is level 5 against a baseline**, so it reports only what is *newly* broken. `phpstan-baseline.neon` records the 1,239 findings that existed the day it landed — nearly all of them one thing, Eloquent's generics resolving `->get()` to `Collection<Model>` so every property read off the result looks undefined. Levels were measured before picking one (0 → 8, 2 → 910, 3 → 938, 4 → 1,111, 5 → 1,246); the note in `phpstan.neon` carries them so the cost of ratcheting up is known rather than guessed. The `DB::transaction()` entries in `ignoreErrors` are a framework-stub false positive — all six call sites were read, every closure ends in an explicit `return` — and they are ignored rather than baselined on purpose: a baseline entry is a promise to come back and fix something, and there is nothing there to fix.
- **The build is not a correctness check for Vue.** It catches syntax, not semantics: a `defineProps` whose result is never bound to `props` compiles clean, ships, and renders a blank page. That happened here. A green build means "it compiled", never "it works" — which is what the type checking is slowly closing.

Dev DB and the test suite run on MySQL under XAMPP (dev `csc_tims-db`, tests `csc_tims_test`); `tests/TestCase.php` auto-creates the test database, so `composer test` needs no manual SQL — but MySQL must be running. `phpunit.xml` pins queue to `sync` and mail to `array`, so queued notifications execute inline in tests.

Seeded demo logins (password `Password123`): `superadmin@csc.gov.ph`, `admin@csc.gov.ph`, `fieldoffice@csc.gov.ph`, `management@csc.gov.ph`, `participant@example.com`. `DatabaseSeeder` order matters: offices → demo logins → randomised users → activity.

`.env` details that bite: `APP_URL` is baked into queued mail and into certificates at render time, so a wrong value is permanent in already-issued documents. `VITE_DEV_ORIGIN` exists for tunnelled dev (VS Code port forwarding / ngrok), which also needs `TRUSTED_PROXIES=*` — proxy trust defaults to *nothing*, because `X-Forwarded-For` is what every IP-keyed `throttle:` counts against and trusting it blindly hands out a fresh rate-limit bucket per forged header (`TrustProxiesTest` guards the default). Google sign-in needs `GOOGLE_CLIENT_ID`/`SECRET`/`REDIRECT_URI`, and the callback host must match the host the flow started on.

## Architecture

**Controllers stay thin; the domain lives in `app/Support/*Service.php`.** `RegistrationService`, `CertificateService`, `PaymentService`, `RefundService`, `AttendanceService`, `ScanStationService`, `CancellationRequestService`, `TrainingRequestService`, `AgencyRequestService`, `PhysicalOrRequestService`, `SupervisoryDocumentService`, `WalkInService`, `RescheduleService`, `RevenueService`, `ProfileService`, `EmailChangeService`, `SmeEvaluationService`, `RegistrationOutputService`, `UndoService` hold the rules — capacity locking, idempotent issuance, status transitions, notification dispatch. When adding behaviour, extend the service, not the controller. Services are static-method classes wrapping `DB::transaction` with `lockForUpdate` where a race is possible (see `RegistrationService::register`). `app/Support` also holds helpers that are *not* the domain, and shouldn't be mistaken for it: read models and query builders (`GlobalSearch`, `PublicCatalogService`, `ParticipantFilter`, `ReportScope`, `PendingActionCounter`, `VisitorCounter`), reference data (`PhilippineGeography`, `ProfileOptions`, `FieldOfficeReference`, `SupervisoryEligibility`, `AnnouncementAudience`), and asset builders (`BrandAssets`, `MailBranding`, `QrCodeBuilder`, `ParticipantQrCode`, `AvatarImageService`).

**Authorization is route-middleware, not policies.** `EnsureUserIsStaff` takes a pipe-separated role list as a parameter: `EnsureUserIsStaff::class.':admin|superadmin'`. `routes/web.php` is therefore the authoritative map of who can do what, and its comments explain the *why* of each grouping (payments → collecting officer, trainings → HRD/admin, attendance → any staff). Add new admin routes inside the right role group rather than checking roles in a controller. `EnsureUserCollectsPayments` is the money gate — it checks `User::collectsPayments()`, not a bare role.

`EnsureProfileIsComplete` gates the entire participant area and redirects to `profile.complete`; the profile form itself, password change, profile photo, email verification, and the email *change* are deliberately registered outside that group. The last one matters for a reason worth keeping: a participant whose agency inbox has died cannot verify the address they are trying to leave, so gating the fix behind verification would be a locked door with the key inside the room. `EmailChangeService` never touches `users.email` until the link sent to the new address is opened, and always warns the old address — a hijacked session controls the app but not the inbox.

**Roles** (`App\Enums\Role`): participant, field-office, collecting-officer, admin, management, superadmin. `Role::staff()` / `isStaff()` / `financial()` are the predicates; `Role::financial()` is who reaches money screens by job title, with everyone else needing the collecting-officer designation.

**Field-office scoping is the main security invariant.** A `field-office` user sees only participants of their own office; everyone else sees the region. `User::isScopedToFieldOffice()` / `scopedFieldOfficeId()` drive this, and the scoped id resolves to `0` (matching nothing) when unassigned — failing closed. Scoping is applied inside each controller query, and `tests/Feature/ExportScopingTest.php` and `FieldOfficeScopingTest.php` are the guard on it. Any new list, export, or detail view must apply the same filter and grow a test there.

**Undo window.** Staff roster decisions are reversible for `UndoService::WINDOW_SECONDS` (30s). The snapshot lives in the actor's *session* keyed by an opaque token — never in the Inertia payload — and only the fields a decision writes are captured. Participant notifications are `->delay()`ed by the same window so an undone decision is never mailed. Keep those two numbers tied together.

**Attendance has two doors into one service.** `/admin/scanner` (signed-in staff) and `/station/{token}` (a shareable link, unauthenticated, unlocked by a six-digit code) both go through `ScanStationService`, which takes an *actor* and a *scope* rather than a request. That is the whole security argument for the public door: a link can never read or write more than its issuer could. New behaviour belongs in the service so the public door cannot drift more permissive than the staff one — `ScanLinkTest` is the guard.

The station is offline-first: `public/scanner-sw.js` plus `resources/js/scanner/{station,camera,resolve,store,sync}.js` cache the roster and queue scans on the device. Consequences to preserve: the roster response stamps its watermark *before* the query (stamped after, a busy door loses rows forever); sync is idempotent; the device stores a digest, never the raw token; and `station/*/unlock` and `station/*/sync` are CSRF-exempt in `bootstrap/app.php` because a page served hours ago to an offline device has no live token — the encrypted grant is what authorises them.

**Enums back every status column** (`RegistrationStatus`, `PaymentStatus`, `RefundStatus`, `AttendanceStatus`, `TrainingStatus`, `TrainingMode`, `TrainingLevel`, `RequestStatus`, `AgencyRequestStatus`, `PhysicalOrRequestStatus`, `SupervisoryDocumentStatus`, `PaymentMethod`, `ChargeTo`, `Curriculum`, `AgencyDocumentKind`, `EvaluationRating`) and carry their own predicates (`isOpenToParticipants()` etc.). Put transition logic on the enum, not in `match` blocks scattered around.

**SME evaluations are keyed to a training *day*, not a training.** `SubjectMatterExpert` is reference data (like a field office: deactivate, never delete — `sme_evaluations` restricts the delete). Experts are attached to a run through `training_subject_matter_expert`, whose `days` column narrows an expert to particular day numbers, with null meaning the whole run — read it via `Training::daysForExpert()` / `expertsForDay()`, never directly. A session that carries over is rated *once, at its end*: an expert is evaluated on the last day of each unbroken stretch they are present for (`expertsEvaluatedOnDay()`), so a two-day run with one expert throughout collects one form on day 2, not two. `Training::evaluationDays()` is therefore the denominator for response rates — never `duration_days`. A submission is one `TrainingDayEvaluation` per (registration, day), unique in the database, owning one `SmeEvaluation` per expert; `SmeEvaluationService` owns every rule (which days are open, who may be rated, the aggregates) so the list, the form and the POST cannot disagree. Certificates' signature line is `signatory_name` — the old `facilitator_name` renamed — and is deliberately not one of the experts.

**Certificates** are rendered to PDF once at release (dompdf) and stored on the private `local` disk, then served through an authorising controller — a template change must not alter documents already in circulation. Public, throttled verification lives at `/verify/{code}`.

**Private files** (certificates, payment proofs, agency documents, registration outputs) always go through a download controller; never expose them via the `public` disk.

**Audit trail.** `ActivityLogger::record()` is called from the *services*, not from controllers or model events — the services are the choke point every state change already passes through, and they know *why* something changed. Logging failures are swallowed and reported to the app log: an audit row is never allowed to roll back a verified payment.

**Mail is data, not just Blade.** `EmailTemplate` + `EmailTemplateRenderer` let staff edit outgoing copy under `/admin/emails`, `EmailLog` records what went out, and `Notifications\Concerns\BrandsMail` applies the shared branding. Participant-facing mail goes through `ParticipantNotification`. The masthead seal is *embedded* in each message (`MailBranding` + the `EmbedMailLogo` listener on `MessageSending`) rather than linked, because a recipient's mail client cannot fetch an image from a non-public host — `MAIL_LOGO_URL` is now only an opt-in to link it from a CDN instead.

**Maintenance mode is app-level, not `artisan down`.** `EnsureSiteIsAvailable` reads `SiteSetting`, runs before Inertia shares props (so a closed site builds no page payload and counts no visitors), and exempts the named `maintenance` route so the notice can never close itself.

**Exports** stream through `App\Support\Exports\SpreadsheetExport` (openspout), not an Excel package. Reporting scope lives in `ReportScope` + `RevenueService`; the shell's badge counts come from `PendingActionCounter`.

## Frontend

- Inertia pages resolve from `resources/js/Pages/**/*.vue`; title template is `"{title} - CSC TIMS"` (`resources/js/app.js`). `@` aliases `resources/js`.
- `HandleInertiaRequests::share()` provides `auth.user` (incl. `role`, `role_label`, `profile_completed`), `unreadNotifications`, `visitors`, and `flash.{success,error,undo}`. The `undo` flash is what renders the Undo button in `AppToast`. That shape is written down once in `resources/js/types/inertia.ts`, which declares it through Inertia's `InertiaConfig` hook so `usePage().props` is typed in every page without importing anything. **Change that file in the same commit as `share()`, never afterwards** — nothing validates Inertia props at runtime, so a type there that disagrees with the PHP is a lie the compiler will defend.
- Layouts: `PublicLayout` (marketing/auth-adjacent), `AuthenticatedLayout` (app shell), `LegalLayout`.
- Shared primitives live in `resources/js/Components/App*.vue`. Reuse them (`AppButton`, `AppInput`, `AppSelect`, `AppCard`, `AppBadge`, `AppModal`, `AppPromptModal`, `AppToast`, `AppStat`, `AppEmptyState`, `AppSkeleton`, `AppIcon`) instead of hand-rolling markup. Icons come from `resources/js/icons.js` via `AppIcon`.
- Not everything is an Inertia page: `resources/js/scanner/` (the offline station), `analytics.js`, and `authSplash.js` are standalone. `vite.config.js` has exactly two inputs (`resources/css/app.css`, `resources/js/app.js`), so a new one of these is an import from `app.js`, never a new build target.
- **Every server-side filtered list goes through `useFilters.js`.** It owns the debounce, the pending flag and the partial reload for the twelve index screens that narrow a list against the server. Two rules it exists to keep: the `filtering` flag goes true on the *keystroke*, not when the request leaves — the debounce is the longest part of the wait, and the old per-page copies left the table looking finished throughout it — and every visit passes `only:` so filtering re-renders the paginator instead of the whole page payload. Getting `only` wrong is a silent staleness bug rather than an error, so the test is whether the controller computes that prop from the request's filters: a register's `stats` do not (they count the whole office), its `exportUrl` does (the download has to match the rows). Pages bind `filteringClass(filtering)` and `aria-busy` to the results region — never to the controls, which stay live. `useFilters.test.js` covers the debounce, the overlapping-visit counter and the `only` passthrough.
- **The roster renders every participant twice, and the two must not diverge.** `Roster.vue` draws a table above `md` and stacked cards below it, and for a while those were different *feature sets*, not just different layouts: Record Payment, Cancel, the OR number, "payment awaiting review" and the evaluation count existed only in the table, so a collecting officer holding a phone at a venue could not take a payment. Everything the two layouts must agree on now lives in `Components/Roster{Actions,Document,Evaluation}.vue`, each taking a `layout: 'row' | 'card'` prop that changes presentation only. Add a roster affordance there, never in one of the two loops. `RosterActions.test.js` asserts the two layouts expose an identical action set across every registration status — that parity check is the guard, and it fails if the old drift is reintroduced. `AppCard`'s `collapsible` + `remember-as` fold the roster's reference panels (stations, revenue, by-office) per viewer in `localStorage`; they default open, because a panel that starts folded hides something from the one reader who came for it.
- Logic several pages share lives in plain modules beside `app.js` rather than in a component: `dateRange.ts` (every training date range), `statusTone.ts` (badge tone per status — the first two modules converted to TypeScript), `icons.js` (the `AppIcon` registry), and `charts.js` — chart colour *slots* resolved to `@theme` tokens, because inline SVG `fill`/`stroke` is the one place in the app that cannot reach a token through a utility class. Slot order is what keeps the palette readable under colour blindness, so `categorical()` hands them out in sequence and never cycles. `charts.js` is deliberately framework-free — a Vue composable in it would drag the lifecycle into every module that only wanted a formatter, which is why `useChartMount.js` sits beside it instead.
- **Every wait the user can see has something showing it, and each kind has one owner.** `AppProgressBar` is the navigation and upload bar (it sits at `--z-progress`, the one token above `--z-modal`, because every multipart upload in this app happens inside a modal and the bar was being painted under the scrim); `AppFileField`'s `progress` prop puts the same percentage beside the file itself, which is where the eye actually is — pass `form.progress` on any form posting with `forceFormData: true`. `useFilters`' `filtering` dims a list being re-queried; `AppSkeleton` and `Analytics/ReportSkeleton.vue` stand in for content that is not there yet. The rule between the last two: dim when the arriving rows are a *narrowing* of what is on screen, skeleton when they are a different thing entirely (another training's report), because leaving last quarter's figures legible under this quarter's label is worse than showing nothing.
- **Exports are the one action that leaves through a plain `<a href>`,** so nothing else can tell the page a download began — no Inertia visit, no XHR, no event. `useDownload.js` mints a token, sends it as `?_dl=`, and watches for `SpreadsheetExport`'s `dl_token` cookie to come back; the button holds a pending state until then and refuses a repeat click, which is what stops a slow register export being run three times by someone who could not tell the first click had landed. The cookie is in `encryptCookies(except:)` because the page has to read the value verbatim, and it is validated before being reflected into a header. It marks time-to-first-byte, not the whole transfer — after that the browser's own download UI is the honest reporter. `useDownload.test.js` covers the token match, the supersede and the timeout backstop.
- **The public site's hero backdrop is one component.** `AppBrandBackdrop` holds the Commission facade and the gradient over it, used by the home hero and both verification screens. Its `wash` prop is a contrast decision, not a style one: the gradient exists so white text survives a sunlit building underneath it. Worst case is a pure-white stone pixel, and against that the middle stop (csc-blue, the lightest of the three) measures 7.43:1 at 87%, 5.75:1 at 78% and 4.86:1 at 72% — so **72% is the hard floor for any backdrop with copy on it**. `full` and `medium` sit above it; `soft` is far below and is only for a band carrying no text at all (the record page, where the card covers the middle). Putting a heading on a `soft` backdrop is an accessibility regression, not a preference. It is `print:hidden` — a full-bleed photograph behind a record someone is filing is unreadable and empties a toner cartridge.
- **Charts draw themselves in.** `useChartMount.js` flips a flag one frame after mount so bars grow from the baseline and donut arcs trace out, instead of appearing at full length as if pasted on. The nested `requestAnimationFrame` is load-bearing: set in `onMounted` alone, Vue applies the zero and the real value inside one frame and the browser collapses them, so nothing transitions. `.donut-segment` in `app.css` names its transitions per property because the draw (600ms) and the hover response (150ms) share an element and a single `transition-all` cannot serve both.

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

One trap worth knowing before it costs an hour: the analytics `overview` prop is **deferred**, so a plain `GET /admin/analytics` does not contain it and an assertion against `overview.*` would quietly be asserting about an absent key. `ExportScopingTest::analyticsOverview()` is the helper that asks for it the way the browser does — and note two things it had to solve. The version header must come from `HandleInertiaRequests::version()`, not `Inertia::getVersion()`, which is empty until a request has passed through the middleware and otherwise yields a bare 409. And a partial visit answers in JSON, so those tests read `json('props.overview.…')`: `assertInertia` only works against the view data an initial page render leaves behind, and fails a partial response with "Not a valid Inertia response."

Client-side logic that has no server round-trip to exercise it lives outside PHPUnit's reach entirely, so those pieces (`resources/js/dateRange.ts`, `resources/js/statusTone.ts`, `resources/js/useDownload.js`, and shared components like `AppFileField`) carry their own `*.test.js` beside the file they test, run with `npm run test` (Vitest + `@vue/test-utils`, config in `vitest.config.js`). This is deliberately narrow — Inertia pages are not unit-tested; the workflow they drive is already covered end-to-end by the matching PHPUnit feature test, and mounting a full page component under jsdom would just be a slower, weaker copy of that.

**TypeScript is opt-in, one file at a time.** `tsconfig.json` sets `allowJs` with `checkJs: false`, so every existing `.js` and every plain `<script setup>` is parsed but not checked — adding it changed nothing and broke nothing. A file joins by becoming `.ts` or carrying `<script setup lang="ts">`, and `strict` applies once it does.

What it exists for is the Inertia boundary, which is the one seam in this app with no checking at all: controller props cross into Vue untyped, so renaming a prop server-side surfaces as a blank cell in production rather than an error. `php artisan tims:types` is the first piece of that — it generates `resources/js/types/enums.ts` from all 17 backed enums in `app/Enums`, replacing the bare string literals the frontend has been repeating (`['approved', 'completed'].includes(…)`, `statusChips`, `status === 'pending'`) with a union derived from the PHP itself. It emits a real module rather than a `.d.ts` deliberately: components need the arrays at runtime to build a filter row, and a declaration file would promise values that do not exist — an import that type-checks and then reads `undefined` in the browser. Declaring the array `as const` and deriving the union from it also means the two can never list different cases. Never hand-edit the generated file; CI runs `tims:types --check` and fails on any drift from the enums.

One version constraint worth knowing before an upgrade: `typescript` is pinned to `^5.9`. TypeScript 7's Go-based compiler no longer exports the `./lib/tsc` subpath `vue-tsc` requires, so bumping it breaks `npm run typecheck` with `ERR_PACKAGE_PATH_NOT_EXPORTED` — an error that reads like a corrupt `node_modules` rather than a version conflict.

## Notes

- `docs/prompts/` holds the original build prompts for the UI; useful for design intent, not current state. `docs/prime-hrm-discount.md` documents the one pricing rule that is not derivable from code.
- `README.md` is still stock Laravel — it is not a source of truth for this app.
- Comments in this codebase explain *why*, often at length, and that convention is worth matching. `routes/web.php` in particular is ~800 lines that are as much documentation as routing.
