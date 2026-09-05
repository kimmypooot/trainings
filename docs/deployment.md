# Deploying CSC TIMS

This is the runbook. Before it existed, deployment guidance was four lines in
the README about `APP_ENV`, `APP_DEBUG` and `SESSION_SECURE_COOKIE` — which
omitted both of the background processes the application depends on, and every
step of recovery.

Two omissions in particular are worth naming up front, because neither announces
itself. **Without a queue worker, no participant ever receives an email** — not
a registration decision, not a certificate, not a password reset from the admin
screen — and nothing errors; the `jobs` table simply grows. **Without one cron
entry, none of the three scheduled jobs run**, including the nightly backup, and
that is discovered on the day it is needed.

`php artisan tims:doctor` checks most of what follows. Run it at the end of
every deploy and read what it says.

---

## 1. What the server needs

- **PHP 8.3+** with `gd` (avatar and photo downscaling), `zip` (`tims:backup`,
  and it must be a build with AES support or encrypted backups are refused),
  `dom` and `mbstring` (dompdf), `pdo_mysql`, `bcmath`, `fileinfo`, `openssl`,
  `curl`.
- **MySQL 8** (or MariaDB 10.6+). `mysqldump` and `mysql` on `PATH`, or their
  paths in `MYSQLDUMP_PATH` / `MYSQL_PATH`.
- **Node 22** on the build machine only. Nothing in `node_modules` is needed at
  runtime — only the compiled output in `public/build`.
- A web server whose document root is **`public/`** and nothing above it.
- A writable location **outside the application directory** for backups.

### The web server

Point the document root at `public/`. Two things matter beyond that:

**Refuse unknown `Host` headers.** The application pins its own URL generation
(`URL::forceRootUrl`) and registers `trustHosts()`, so a forged `Host` cannot
move a password-reset link any more — but a default-deny virtual host is the
outer wall, and it costs one line. In nginx, a catch-all `server` block
returning `444`; in Apache, a default vhost that is not this site.

**Terminate TLS and describe the request.** If anything sits in front of PHP —
a load balancer, nginx, Cloudflare — it must send `X-Forwarded-For` and
`X-Forwarded-Proto`, and you must name it in `TRUSTED_PROXIES` (see §2).
Without that, Laravel sees every request as coming from the proxy: **every
per-IP rate limit in the application collapses into a single shared bucket**, so
one abusive client can lock everyone out of certificate verification and out of
unlocking a scanning station, and HTTPS becomes invisible so `asset()` emits
`http://` URLs the browser blocks as mixed content.

---

## 2. Environment

Copy `.env.example` and change at minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false                       # a debug page hands out stack traces, SQL and .env values
APP_URL=https://tims.example.gov.ph   # the root of EVERY generated URL; see below
APP_KEY=                              # php artisan key:generate

SESSION_SECURE_COOKIE=true            # once TLS is actually in front of this

TRUSTED_PROXIES=10.0.0.4,10.0.0.5     # the actual proxy addresses, or omit entirely

MAIL_MAILER=smtp                      # 'log' silently delivers nothing
QUEUE_CONNECTION=database

BACKUP_PATH=/mnt/backups/tims         # NOT inside the application directory
BACKUP_PASSWORD=                      # long and random; store it somewhere other than this server
```

**`APP_URL` is load-bearing beyond links.** It is the root of every URL the
application generates, and it is rendered into certificates *at the moment they
are issued*. Certificates are never re-rendered — that is deliberate, so a
template change cannot alter a document already in circulation — which means a
wrong `APP_URL` is permanent in every certificate issued while it was wrong.
Get it right before the first release, not after.

`TRUSTED_PROXIES` is read by `config/trustedproxy.php`. It cannot be read in
`bootstrap/app.php`, and the reason is worth knowing: that file's middleware
closure runs before `.env` is parsed, so `env()` there always returns its
default. The setting used to live in exactly that spot and had never once taken
effect.

### Which office this is

This codebase is deployed one copy per regional office, so the operating office
is configuration. Everything reads it from `config('office.*')` — the site
footer, the sidebar wordmark, outgoing mail, the maintenance notice, the
structured data a search engine indexes, and the certificate itself. Anything
left blank is omitted rather than guessed, so an unset telephone number prints
no telephone row instead of somebody else's number.

**The office edits this itself, at `/admin/office`** (superadmin only, beside
the maintenance switch). That screen writes a single row which overlays the
values below, so changing the office telephone number no longer needs a shell
and a `config:cache` clear. The region is chosen from the PSA's own list rather
than typed, and the certificate prefix stops being offered once any certificate
has been issued under it.

The env block below is the **fallback**. It is what the site shows on its very
first page load, before anyone has signed in to save anything, and each field
falls back independently — so it is still worth setting, even though the screen
supersedes it.

```dotenv
OFFICE_NAME="Civil Service Commission Regional Office V"
OFFICE_SHORT_NAME="CSC RO V"
OFFICE_REGION="Bicol"
OFFICE_ADDRESS="..."
OFFICE_PHONE=
OFFICE_EMAIL=ro05.hrd@csc.gov.ph
OFFICE_CERTIFICATE_PREFIX=CSC5        # the printed number: CSC5-2026-000042
```

**Set these before releasing a single certificate**, for the same reason
`APP_URL` has to be right first: a certificate is rendered once at issue and
stored, so the office named on it cannot be corrected afterwards. Every other
string here re-renders on the next page load and is fixable at any time; that
one is not. `OFFICE_CERTIFICATE_PREFIX` is the same story — numbers already
assigned stay as they were printed, which is correct, so changing it later
leaves a permanent seam in the series.

`tests/Feature/OfficeIdentityTest.php` guards the code against picking up a
hard-coded office again. It does not and cannot check that *your* values are
right — that is this step.

`tims:doctor` reads the effective values, so it checks whatever is actually in
force: the saved row where one exists, the env fallback where it does not.

**Upgrading an existing install:** `/admin/office` needs its table, so run
`php artisan migrate` before opening it — until then that one screen errors
while the rest of the site carries on using the env values. That is the
intended degradation rather than an oversight: the overlay is deliberately
guarded so a missing settings table can never take the public site down, and
only the screen that edits the table actually requires it.

### The field offices, before the first migrate

`database/data/field-offices.json` lists the offices this deployment serves —
their codes, names, provinces, jurisdictions and heads. It ships with Regional
Office VIII's nine, which is the wrong org chart for anyone else, so **replace
it before running `migrate` for the first time**.

It has to be right at that moment rather than afterwards because the migration
that creates the table seeds these rows, and the migration after it links
existing profiles to them. `FieldOfficeSeeder` re-applies the file later
(matching on `code`), so corrections and new offices are a file edit plus
`php artisan db:seed --class=FieldOfficeSeeder` — but rows already pointed at by
profiles should be deactivated rather than deleted, the same rule as subject
matter experts.

A missing, empty or malformed file stops the migration with a message naming
it. That is deliberate: an empty office list is not an empty office, it is a
broken install, and every symptom downstream of it is silent — profiles link to
nothing, field-office scoping resolves to 0 and fails closed, and every
field-office account sees an empty system with nothing to explain why.

Each row wants `code` (short, unique), `name`, `type` (`field_office`,
`satellite_office`, `regional_office` or `division`), `province`, and
`jurisdiction` — the provinces the office covers, which is what a participant
picks against. `email`, `head_name` and `head_position` are optional and may be
null.

**Keep one catch-all row.** The shipped list ends with a `division` row covering
every province plus an "outside the region" option, and it is what a participant
who belongs to no field office selects. Without one, those participants have
nothing to choose. Its `code` is not load-bearing — only a backfill migration
for v1 data mentions `hrd`, guarded, and a fresh install has nothing to backfill
— but the row itself is.

**Why the file rather than the screen.** Offices are also managed at
`/admin/field-offices` (admin and superadmin) — create, edit, and activate or
deactivate. What that screen deliberately cannot do is **delete**, because
profiles point at these rows and removing one would orphan them; the same rule
applies as to subject matter experts. So an office that migrates with the shipped
file and fixes it afterwards is left with nine Regional Office VIII rows it can
only deactivate, permanently. Editing the file first is the only path that
leaves no residue. After that first migrate, the screen is the natural place for
everything: a new satellite office, a head who has moved on, a jurisdiction that
has been redrawn.

### The photographs are files, not code

Four screens show a photograph of the office building: the landing hero, the
sign-in and legal layouts, and both certificate-verification pages. They load
`public/images/cscbg_facade.{webp,jpeg}` by fixed path, so an office replaces
those two files and changes nothing else. The landing page's rotating hero
photos (`training-0*.jpg`) work the same way, and `HeroPhotoStack` drops the
whole column if none of them load — an absent photograph is never drawn as an
empty space.

One constraint when swapping the facade: white text sits on top of it, and
`AppBrandBackdrop`'s gradient is what keeps that readable. The `wash` levels
were measured against the worst case — a pure-white stone pixel — and **72% is
the floor for any backdrop carrying text**. A markedly brighter building than
the current one should be re-checked rather than assumed.

---

## 3. Deploy

```bash
git pull --ff-only

composer install --no-dev --optimize-autoloader
npm ci && npm run build            # or build elsewhere and ship public/build

php artisan migrate --force
php artisan optimize               # config + routes + views
```

`php artisan optimize` replaces the individual `*:cache` commands. Run
`php artisan optimize:clear` first if a previous cache may be stale.

**Do not copy `public/hot` or `public/fonts-manifest.dev.json` to the server.**
Both are development artifacts; `public/hot` makes the application load every
asset from `localhost:5173`, so the site renders with no CSS and no JavaScript.
They are gitignored, so `git pull` will not bring them — but a file-copy or FTP
deploy will.

### Permissions

`storage/` and `bootstrap/cache/` must be writable by the web user, and nothing
else needs to be:

```bash
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

There is no `php artisan storage:link` step. Nothing in this application is
served from the public disk — certificates, payment proofs, agency documents and
supervisory documents all go through an authorising controller, and a symlink
would be a second path to them that applies none of those checks.

---

## 4. The two background processes

### The queue worker

Every participant-facing email goes through it, as do certificate release,
announcements and the avatar import. Supervise it — it must come back after a
crash and after a reboot.

```ini
; /etc/supervisor/conf.d/tims-worker.conf
[program:tims-worker]
command=php /var/www/tims/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/tims
user=www-data
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/tims-worker.log
stopwaitsecs=3600
```

**Restart it on every deploy** (`php artisan queue:restart`, or restart the
supervisor program). A running worker holds the old code in memory and will
happily keep executing it.

### The scheduler

One entry. Without it, `tims:send-reminders`, `tims:invite-evaluations` and
`tims:backup` never run:

```cron
* * * * * cd /var/www/tims && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, a Task Scheduler task running the same command every minute.

---

## 5. After every deploy

```bash
php artisan tims:doctor
```

It checks `APP_DEBUG`, `APP_KEY`, `APP_URL`, the session cookie flags, whether
the caches are warm, whether the queue is actually being drained, whether a
recent backup exists (which is how it infers the scheduler is running), backup
encryption and location, the mail transport, and storage permissions. It exits
non-zero on failure, so a deploy script can gate on it.

Then confirm by hand what a command cannot:

- Sign in as a participant and as a staff member.
- Trigger one email (a password reset) and confirm it arrives.
- Open a training roster and a certificate download.
- Load `/station/{token}` on a phone and confirm the camera opens.

---

## 6. Backup and recovery

The nightly archive (02:00) holds the database and the whole private disk —
certificates, payment proofs, agency documents. Certificates are the only
artifact in the system with **no reproduction path**: they are rendered once and
never regenerated, so losing `storage/app/private` means `/verify/{code}` starts
reporting "not found" for documents real people are holding printed copies of.

`BACKUP_PASSWORD` is mandatory in production — `tims:backup` refuses to run
without it — and the archive is written with AES-256, so any ordinary tool
(7-Zip, WinZip) opens it with the password. That is deliberate: a recovery where
the archive can only be read by the application that is currently broken is not
a recovery. **Keep the password somewhere other than this server.** An archive
encrypted with a key that only exists on the machine it protects against losing
is a locked box with the key inside it.

`BACKUP_PATH` must be outside the application directory; `tims:backup` refuses
otherwise in production. Getting the archive onto a *different machine* is still
an ops step this command cannot do — a mapped network drive, a synced folder, an
object-store push.

### Restoring

```bash
php artisan tims:restore /mnt/backups/tims/tims-backup-2026-09-04_020000.zip
php artisan migrate --force
```

It drops every table in the target database and reloads it, then copies the
archived private files back. The private disk is restored **additively** —
nothing on disk is deleted to match an older archive, because a certificate
present on disk but absent from the archive is a document somebody may be
holding.

`migrate --force` afterwards is not optional: the archive carries the schema it
was taken with, so an archive predating a migration leaves the application
running against columns that do not exist.

**The restored database needs the same `APP_KEY`.** Refund payees' bank account
numbers are encrypted at rest, so they are readable only by an application
holding the key they were written with. Restoring this database into a
deployment with a different `APP_KEY` — a rebuilt server where somebody ran
`key:generate`, a copy stood up for testing — leaves every account number
permanently unreadable, and nothing will say so until an officer tries to pay a
refund. Encrypted session and cookie payloads and every outstanding scan-link
grant have the same dependency, but those expire; the account numbers do not.

So: **back up `APP_KEY` with the archive, and store it separately.** It is the
one secret that must survive alongside the backup and must not live inside it.
Rotating the key is not a routine operation on this application — it is a data
migration, and there is no command for it.

### Rehearsing it — and this is the part that is still outstanding

A backup nobody has restored is not a backup. `tims:restore` exists so that this
can be done on a copy, without touching production:

```bash
mysql -u root -e "CREATE DATABASE tims_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
time php artisan tims:restore <newest archive> --database=tims_rehearsal --skip-files --force
```

Do this **before go-live**, and record two numbers the runbook cannot supply:

- **RPO** — how much data a restore loses. With a 02:00 nightly backup and no
  other copy, the worst case is a little under 24 hours of registrations,
  payments and attendance. Decide whether that is acceptable; if it is not, the
  answer is more frequent backups or binlog shipping, not a longer runbook.
- **RTO** — how long a restore actually takes on the production dataset,
  measured rather than estimated.

The round trip has been exercised on a development dataset (39 users, 163
registrations): backup 5s, restore 4s, every table matching. That proves the
mechanism, and says nothing useful about production volumes.

---

## 7. Rolling back

There is no automated rollback. In order of preference:

1. **Code only** — check out the previous tag, `composer install --no-dev`,
   `php artisan optimize`, restart the worker. Safe whenever the release
   contained no migration.
2. **Code and schema** — only if the migration has a tested `down()`. Most here
   do; none has been rehearsed against production data.
3. **Restore from the archive** — the last resort, and the one that loses
   everything since the last backup. This is why the RPO above is a number
   somebody should have agreed to in advance.

Prefer rolling *forward* with a fix over rolling a migration back on live data.
