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
