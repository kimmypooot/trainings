# CSC TIMS

A Training Information Management System for a Civil Service Commission regional
office. It runs the whole life of a training: the public catalogue an agency
reads before nominating anyone, registration and the fee that follows, the door
on the morning of day one, the evaluation on the evening of the last day, and
the certificate that outlives all of it.

The deployment this repository was built for is **CSC Regional Office VIII
(Eastern Visayas)**, but the office identity is configuration rather than
markup — see [Office identity](#office-identity).

It is a rewrite of an older application ("csc-tms", referred to throughout as
v1) on a current stack.

## Stack

| | |
|---|---|
| Backend | PHP 8.3, Laravel 13 |
| Frontend | Inertia 3 + Vue 3, Tailwind v4, Vite 8 |
| Database | MySQL |
| PDF | dompdf (`barryvdh/laravel-dompdf`) |
| Spreadsheets | openspout (streamed, not an Excel package) |
| QR | `endroid/qr-code` server-side, `jsqr` in the browser |
| Sign-in | Password, plus Google via Socialite |
| Tests | PHPUnit (no Pest) and Vitest |
| Quality | Pint, PHPStan (larastan) at level 5, `vue-tsc` |

## Getting it running

You need PHP 8.3+, Composer, Node, and a running MySQL. Development on this
project is done under XAMPP; nothing depends on that beyond MySQL being up.

```bash
composer setup     # install, .env, key, migrate, npm install, build
```

Then either run everything Laravel-side together:

```bash
composer dev       # php artisan dev — serve + vite + queue worker + pail
```

or, if Apache/XAMPP is already serving the app, just the asset server:

```bash
npm run dev
```

Demo data:

```bash
php artisan migrate:fresh --seed
```

The seeders run in an order that matters — offices, then the fixed demo
logins, then randomised users, then activity. Every seeded account uses the
password `Password123`:

| Login | Role |
|---|---|
| `superadmin@csc.gov.ph` | Super Administrator |
| `admin@csc.gov.ph` | Administrator (HRD) |
| `fieldoffice@csc.gov.ph` | Field Office |
| `management@csc.gov.ph` | Management |
| `participant@example.com` | Participant |

## What it does

### Public, no account needed

The Commission's programs are public information, and an agency deciding whom
to nominate should not have to create an account to read what is on offer.

- The landing page and its filterable calendar of upcoming runs.
- **Certificate verification** at `/verify` and `/verify/{code}` — the point of
  a certificate is that anyone holding the printed document can confirm it is
  genuine, so this is unauthenticated. It is throttled, harder on the search
  form than on the code URL: a form answering "does this code exist" is the one
  place someone could sit and guess.
- **The attendance station** at `/station/{token}` — a shareable link for the
  volunteer on the door who has no account. See
  [Attendance](#attendance-two-doors-one-service).
- `robots.txt`, `sitemap.xml`, and the privacy, terms and accessibility pages.

### For a participant

Registering for a run, uploading a supervisory-course supporting document,
paying (and requesting a refund, or a physical copy of an official receipt),
carrying a QR code to the venue, evaluating the experts who taught each day,
downloading certificates, filing agency requests and training suggestions, and
keeping their own profile — including moving the account to a new email
address.

### For staff

Six roles, in `App\Enums\Role`: participant, field-office, collecting-officer,
admin, management, superadmin.

- **Trainings** — creating, editing, rescheduling (a rescheduled run is a new
  record), transfers between runs, and the roster.
- **The roster** — attendance per day, payments, supervisory-document review,
  bulk actions, and a 30-second undo window on staff decisions.
- **Attendance** — the signed-in scanner at `/admin/scanner`, walk-in
  admission, and issuing or revoking station links.
- **Payments** — verification, refunds, promissory notes, physical-OR requests.
- **Request queues** — training requests, cancellations, agency requests and
  their document exchange, supervisory documents.
- **Certificates** — release (one training or one participant) and re-send.
- **Reporting** — analytics, revenue, period and per-training reports, and
  streamed spreadsheet exports.
- **Administration** — users, field offices, subject-matter experts, editable
  email templates and their delivery log, maintenance mode, and a read-only
  audit trail.

## How it is put together

Enough to orient a reader. `CLAUDE.md` carries the working detail, and
`routes/web.php` is ~800 lines that are as much documentation as routing.

**Controllers stay thin; the domain lives in `app/Support/*Service.php`.**
`RegistrationService`, `PaymentService`, `CertificateService`,
`AttendanceService`, `SmeEvaluationService` and the rest hold the rules —
capacity locking, idempotent issuance, status transitions, notification
dispatch. They are static-method classes wrapping `DB::transaction` with
`lockForUpdate` where a race is possible. New behaviour goes in the service, not
the controller.

**Authorization is route-middleware, not policies.** `EnsureUserIsStaff` takes a
pipe-separated role list as a parameter, so `routes/web.php` is the
authoritative map of who can do what — and its comments explain the *why* of
each grouping. Money screens go through `EnsureUserCollectsPayments`, which
checks a designation rather than a bare role.

**Field-office scoping is the main security invariant.** A field-office user
sees only participants of their own office; everyone else sees the region. The
scoped id resolves to `0` — matching nothing — when unassigned, so it fails
closed. `ExportScopingTest` and `FieldOfficeScopingTest` are the guard, and any
new list, export or detail view has to grow a case there.

**Enums back every status column** and carry their own predicates. Transition
logic belongs on the enum, not in `match` blocks scattered around.

**Private files** — certificates, payment proofs, agency documents,
registration outputs — live on the private `local` disk and are served through
an authorising controller. They are never on the `public` disk.

**The audit trail** is written from the services, not from controllers or model
events: the services are the choke point every state change already passes
through, and they know *why* something changed. A logging failure is swallowed
and reported to the app log — an audit row never rolls back a verified payment.

### Attendance: two doors, one service

`/admin/scanner` (signed-in staff) and `/station/{token}` (a shareable link,
unauthenticated, unlocked with a six-digit code) both go through
`ScanStationService`, which takes an *actor* and a *scope* rather than a
request. That is the entire security argument for the public door: a link can
never read or write more than its issuer could. `ScanLinkTest` is the guard.
Walk-in admission deliberately does not exist on the public door — it enrols a
person and can issue a promissory note in their name, and a financial
obligation must not be created by an unauthenticated link.

The station is offline-first (`public/scanner-sw.js` plus
`resources/js/scanner/`): it caches the roster and queues scans on the device.
The roster response stamps its watermark *before* the query, sync is idempotent,
and the device stores a digest rather than the raw token.

### SME evaluations are keyed to a training *day*

An expert teaching an unbroken stretch of days is rated once, at the end of it —
so a two-day run with one expert throughout collects one form on day 2, not two.
`Training::evaluationDays()` is therefore the denominator for response rates,
never `duration_days`.

### Maintenance mode is app-level

`EnsureSiteIsAvailable` reads a `SiteSetting` and runs before Inertia shares
props, so a closed site builds no page payload and counts no visitors. It
exempts the named `maintenance` route, so the notice can never close itself.
`artisan down` is not what this uses.

## Frontend

Inertia pages resolve from `resources/js/Pages/**/*.vue`; `@` aliases
`resources/js`. Layouts are `PublicLayout`, `AuthenticatedLayout` and
`LegalLayout`.

Shared primitives are the `App*.vue` components — `AppButton`, `AppInput`,
`AppCard`, `AppBadge`, `AppModal`, `AppToast`, `AppStat`, `AppEmptyState`,
`AppSkeleton`, `AppIcon` and the rest. Reuse them rather than hand-rolling
markup. Logic several pages share lives in plain modules beside `app.js`
(`dateRange.js`, `statusTone.js`, `charts.js`, `useFilters.js`,
`useDownload.js`), not in a component.

Not everything is an Inertia page: the offline scanner, `analytics.js` and
`authSplash.js` are standalone. `vite.config.js` has exactly two inputs, so a
new one of those is an import from `app.js`, never a new build target.

### Design rules

Enforced by `resources/css/app.css`, and worth knowing before touching a
template:

- **Never hardcode a hex value.** Everything is a Tailwind v4 `@theme` token.
- `--color-csc-blue` carries the layout, white carries content, and
  `--color-csc-red` is an accent used once or twice per viewport — never as a
  large fill. White text on it fails AA; use `--color-csc-red-ink` where text
  is involved.
- Secondary text is `--color-csc-ink-muted` or `--color-csc-ink-subtle`, never
  an opacity on `--color-csc-ink`.
- Inside the signed-in app the brand red is retired in favour of the semantic
  set, so a red badge never reads as branding. Status is never colour-alone —
  badges carry an icon and a label.
- Stacking uses the named `--z-*` tokens.
- Print styles matter: rosters and attendance sheets get printed, so new shell
  chrome belongs in the existing `@media print` block.

## Configuration

`.env.example` is the local-development template and is commented at length;
what follows is the short version of what bites.

**`APP_URL`** is the root of *every* URL this application generates — not only
queued mail and certificates, but links built inside a request too.
`AppServiceProvider` calls `URL::forceRootUrl()` so that the `Host` header
cannot decide where a generated link points; without it, a forged `Host` on
`POST /forgot-password` put a working reset token on the attacker's domain in a
genuine email from this office. `TrustHosts` refuses such a request outright as
well, but it is inert in `local` and under tests, so the pin is what actually
holds. Consequences worth knowing: certificates are rendered once and never
re-rendered, so a wrong value is permanent in documents already issued — and
when tunnelling, `APP_URL` **must** be the tunnel's URL or every link in the app
points at localhost. Only the scheme still follows the request, which is what
lets a proxy's `X-Forwarded-Proto` produce `https://` links. `TrustedHostTest`
is the guard.

**`TRUSTED_PROXIES`** defaults to trusting nothing, on purpose.
`X-Forwarded-For` is what every IP-keyed `throttle:` counts against, so trusting
it blindly hands out a fresh rate-limit bucket per forged header. Set it to `*`
only behind a tunnel on a machine nothing else can reach; in production, name
the actual proxy addresses. It is read by `config/trustedproxy.php`, and it has
to be — the `withMiddleware()` closure in `bootstrap/app.php` runs before `.env`
is parsed, so the `env()` call that used to live there always returned its
default and the setting had never once taken effect. `TrustProxiesTest` now
guards the configured branch as well as the default, and asserts that
`bootstrap/app.php` reads no environment variables at all.

**`VITE_DEV_ORIGIN`** exists for tunnelled development (VS Code port forwarding,
ngrok), which also needs `TRUSTED_PROXIES` and a matching `APP_URL`.

**Google sign-in** needs `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and
`GOOGLE_REDIRECT_URI`, and the callback host must match the host the flow
started on. `SERVER_HOST=localhost` is in the template for exactly this reason:
`artisan serve` otherwise announces `127.0.0.1`, a different origin as far as
cookies go, and the OAuth state check fails.

**Security headers** — `SecurityHeaders` (appended to the `web` group) sends
`X-Frame-Options: DENY`, `nosniff`, a referrer policy and a Permissions-Policy
that denies the camera everywhere except the two scanning doors, on every
response. Two parts are staged in `config/security.php`: the
Content-Security-Policy ships **report-only** until a deployment has watched the
reports and set `CSP_ENFORCE=true` — enforcing an incomplete policy fails
silently and totally — and HSTS is sent only on requests that actually arrived
over HTTPS. `SecurityHeaderTest` is the guard, including the two cases that
would otherwise break a venue: the camera must stay allowed on `/admin/scanner`
and `/station/{token}`.

**Backups** — `BACKUP_PATH` defaults to `storage/backups`, which survives a bad
migration but not a dead disk. Point it at a mapped or synced folder to get the
archive off the machine.

### Office identity

`config/office.php` reads the `OFFICE_*` keys — name, short name, region,
address, phone, email. They live in config rather than in a Vue template
because they are a deployment fact, not a design one: the same codebase serving
a different regional office should not need a front-end edit to stop lying about
where it is. Anything left blank is omitted rather than guessed — no telephone
number beats the wrong telephone number.

### Deploying

**See [docs/deployment.md](docs/deployment.md)** — server requirements, the two
background processes the app depends on, permissions, the post-deploy check, and
backup and recovery.

The short version: `.env.example` is a *development* template, so a production
deploy must at minimum set `APP_ENV=production`, `APP_DEBUG=false` (a debug page
hands stack traces, SQL and `.env` values to anyone who can trigger a 500) and
`SESSION_SECURE_COOKIE=true`. Then two things that are easy to miss entirely and
announce themselves in no way at all: **a supervised `queue:work`**, without
which no participant ever receives an email, and **one cron entry running
`schedule:run` every minute**, without which none of the three scheduled jobs
run — including the nightly backup.

`php artisan tims:doctor` checks all of that and exits non-zero on failure. Run
it at the end of every deploy.

## Scheduled jobs

Three commands, registered in `routes/console.php`, every one of them
`withoutOverlapping` — a slow run must never be started underneath itself.

```bash
php artisan tims:send-reminders --days=1      # 08:00 — the day before, so leave can still be arranged
php artisan tims:invite-evaluations            # 17:30 — after the sessions end, while the room is fresh
php artisan tims:backup                        # 02:00 — db + storage/app/private into one zip
```

Two more that are run by hand:

```bash
php artisan tims:import-google-avatar user@example.com --force
php artisan tims:doctor                        # post-deploy: is this configured for production?
php artisan tims:restore <archive>              # put a backup back — see docs/deployment.md
php artisan tims:types                         # regenerate resources/js/types/enums.ts from App\Enums
```

`tims:invite-evaluations` also takes `--date=` (defaulting to today) and
`--dry-run`, which lists who would be written to without sending. `tims:backup`
takes `--path=` and `--keep=` to override the configured destination and
retention.

## Testing

```bash
composer test                                  # config:clear + php artisan test
php artisan test --filter=ExportScopingTest
php artisan test tests/Feature/UndoTest.php

npm run test                                   # Vitest
npm run typecheck                              # vue-tsc --noEmit
vendor/bin/pint                                # formatting (Laravel preset)
vendor/bin/pint --dirty                        # only what you touched
composer stan                                  # PHPStan level 5
```

**PHP.** 48 feature test files under `tests/Feature`, organised by workflow —
registration, attendance, certificates, payments, refunds, request workflows,
agency requests, scan links, roster bulk actions, undo, scoping, analytics,
seeders. Plain PHPUnit with Laravel's `TestCase`; there is no Pest. New domain
behaviour belongs in the matching workflow test, new admin surface in
`AdminAreaTest`.

Tests run on MySQL (`csc_tims_test`); `tests/TestCase.php` creates the database
itself, so `composer test` needs no manual SQL — but MySQL has to be running.
`phpunit.xml` pins the queue to `sync` and mail to `array`, so queued
notifications execute inline.

One trap worth knowing before it costs an hour: the analytics `overview` prop is
**deferred**, so a plain `GET /admin/analytics` does not contain it, and an
assertion against `overview.*` would quietly be asserting about an absent key.
`ExportScopingTest::analyticsOverview()` is the helper that asks for it the way
the browser does.

**JavaScript.** Deliberately narrow: client-side logic with no server round-trip
to exercise it carries a `*.test.js` beside the file it tests — `dateRange.js`,
`statusTone.js`, `useDownload.js`, `useFilters.js`, and shared components like
`AppFileField` and `RosterActions`. Inertia pages are not unit-tested; the
workflow they drive is already covered end to end by the matching feature test,
and mounting a full page under jsdom would be a slower, weaker copy of it.

**CI** (`.github/workflows/ci.yml`) runs Pint, the TypeScript-generation check,
PHPStan and PHPUnit on one job, and `vue-tsc`, Vitest and `npm run build` on
another — on every push to `main` or `v1-parity-port` and on every pull request.
Be clear about the build step's limit: it catches syntax, not semantics. A green
build means "it compiled", never "it works".

## Conventions

Comments in this codebase explain *why*, often at length, and matching that is
worth the keystrokes. The `routes/web.php` comments in particular are the
reasoning behind the authorization model and should be read before adding a
route to it.

## Repository map

```
app/
  Console/Commands/     tims:* commands
  Enums/                every status column's vocabulary
  Http/Controllers/     thin; Admin/ and Auth/ are the two large groups
  Http/Middleware/      the authorization and availability gates
  Models/               29 models
  Notifications/        participant-facing mail, branded through a shared concern
  Support/              the domain services, plus read models and asset builders
docs/
  prime-hrm-discount.md the one pricing rule not derivable from code
  prompts/              original UI build prompts — design intent, not current state
resources/js/
  Pages/                Inertia pages
  Components/           App*.vue primitives and the shared Roster* parts
  scanner/              the offline station (not an Inertia page)
  types/                generated from App\Enums by tims:types
routes/
  web.php               the authoritative map of who can do what
  console.php           the three scheduled jobs
tests/Feature/          48 files, organised by workflow
```

## Further reading

- `CLAUDE.md` — the working guide to this codebase, in more depth than this file.
- `docs/prime-hrm-discount.md` — the pricing rule that is not derivable from
  code.
