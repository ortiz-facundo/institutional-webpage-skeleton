# symfony-backoffice-skeleton — cPanel deployment walkthrough

This zip is a self-contained bundle of the symfony-backoffice-skeleton backoffice. It is
designed for cPanel shared hosting where:

- The document root is **fixed** at the subdomain's directory (typically
  `/home/<user>/CHANGE_ME_DOMAIN/` or `/home/<user>/public_html/`
  if the subdomain isn't pointed at its own folder) — you cannot point it
  at `<docroot>/public/`.
- You only have **FTP + the cPanel File Manager / Archive Manager** — no SSH.

If you have SSH access, the canonical Symfony workflow is much simpler; this
guide is the FTP-only fallback.

---

## 0. Prerequisites — verify these on the server BEFORE you upload

### Notifications and cron

Notifications use the existing MariaDB database; no Redis or persistent worker
is required. Push is disabled by default and the inbox remains available.

1. Generate VAPID keys once locally:
   `docker compose exec php bin/console app:notifications:vapid-keys`.
2. Put those keys in production `.env.local`, set `NOTIFICATION_PUSH_ENABLED=1`,
   and set `NOTIFICATION_VAPID_SUBJECT=mailto:<real-contact-email>`.
   Preserve the same keys across updates. Never include secrets in source control.
3. Verify HTTPS, outgoing HTTPS to `fcm.googleapis.com`, and PHP CLI 8.4 with
   `curl`, `mbstring`, and `openssl` (elliptic-curve support), plus `bcmath` or
   `gmp`. The library emits a notice without the math accelerator, which Symfony
   may convert to an exception. Docker includes BCMath for parity.
   Apache's `.htaccess` PHP handler does not select the CLI version.
4. Verify `/sw.js` returns JavaScript, not HTML. The ZIP aliases this URL to
   `public/sw.js`; it must retain root scope and revalidation headers.
5. In cPanel → Cron Jobs, use `*` in all five schedule fields, provided the
   hosting plan permits every-minute jobs. Replace `APP_DIRECTORY` with the
   actual deployed project root:

   ```text
   /usr/local/bin/ea-php84 /home/scachico/APP_DIRECTORY/bin/console app:notifications:process --env=prod --no-debug
   ```

   Confirm the executable with a temporary `/usr/local/bin/ea-php84 -v` cron.
   The application root contains `bin/console` and `vendor/`, not just public assets.
   CLI can execute `bin/console` even when the host WAF blocks `/bin/` HTTP URLs.
6. Configure cron email for errors initially. Successful runs are silent; `-v`
   prints aggregate outcomes. The worker uses a file lock, at most 50 deliveries,
   a 45-second budget, and bounded requests. An overlapping invocation skips.
   This design assumes one application host sharing its `var/` lock file.

Each Chrome desktop/Android device opts in under Account → Notifications. The
title and short description may appear on the lock screen. Explicit logout
disables the current device on the server; re-enable it after signing back in.
Remote revocation disables future sends but cannot retract accepted pushes.
Subscription changes are blocked during impersonation. The sender rechecks
active accounts, subscription ownership, and an optional required permission.

Transient failures retry after 1, 2, 4, and 8 minutes; permanent failures and
exhausted retries remain in `notification_delivery` for inspection. Re-running
the processor does not resend accepted rows. Inbox and delivery history are
retained until the recipient is deleted; retention pruning can be introduced
when a real downstream policy is chosen. Do not truncate the queue during deploys.

`app:dev:test-notification` refuses production. Validate the initial flow locally
and then through the downstream's real application event after synchronization.

### 0.1 PHP 8.4 is available

1. Log into cPanel.
2. Open **Software → MultiPHP Manager**.
3. Confirm the dropdown at the top shows **PHP 8.4** under "Available PHP
   versions". If not, cPanel's MultiPHP cannot run this app — Doctrine 3.2
   and Migrations 4.0 require PHP 8.4 minimum.

If 8.4 is not listed, contact your host. **Do not** try to downgrade Doctrine
to make this work; the source code targets 8.4 throughout.

### 0.2 Required PHP extensions

In MultiPHP Manager → PHP Info (or via a one-line phpinfo file), verify
these extensions are loaded. cPanel usually has all of them.

- `ctype`, `iconv` (mandatory)
- `pdo_mysql` (mandatory — MariaDB driver)
- `mbstring`, `openssl`, `tokenizer`, `xml`, `filter`, `session`
- `intl` (used by the Validator component)
- `zip` (used by Composer at install time, optional at runtime)

### 0.3 Database is ready

1. Open **Databases → MySQL® Databases**.
2. You should already have a database, a database user, and the user added
   to the database. Note:
   - Database name (often prefixed with your cPanel username, e.g.
     `acct_yourproject`)
   - Database username (often also prefixed)
   - Database password
3. Open **phpMyAdmin** for that database and confirm the **Server version**
   in the right-hand panel — it's used in the `DATABASE_URL` so Doctrine
   picks the right dialect. cPanel typically shows something like
   `10.11.18-MariaDB-cll-lve-log`. If your version differs, update
   `serverVersion=` in `.env.local`.

### 0.4 Email account for the backoffice sender

symfony-backoffice-skeleton's only email is the account-confirmation link sent to
`PROD_ADMIN_EMAIL` after the first deploy. The account must exist
before the deploy runs (so Exim can deliver the confirmation link).

1. Open **Email → Email Accounts**.
2. Create an account, e.g. `admin@CHANGE_ME_DOMAIN` (or whichever
   address you intend to set `PROD_ADMIN_EMAIL` to), with a strong
   password. The `MAILER_FROM` (`noreply@CHANGE_ME_DOMAIN`) and
   `PROD_ADMIN_EMAIL` may use the same mailbox or different ones.
3. Note the full address and password — these go into `MAILER_DSN`.

---

## 1. Upload the zip

### Option A — cPanel File Manager

1. Open **Files → File Manager**.
2. Navigate into the subdomain's document root. For
   `CHANGE_ME_DOMAIN`, cPanel typically creates
   `/home/<user>/CHANGE_ME_DOMAIN/`. If that directory does not
   exist (because the subdomain was added to `public_html/` instead), see
   "Domain root gotcha" below.
3. Click **Upload** and select `symfony-backoffice-skeleton-cpanel.zip`.
4. After upload, **right-click** the zip → **Extract**.
   - **Critical:** extract into the current directory (the subdomain
     document root), not into a sub-folder. The zip's top-level entries
     are meant to land at `<docroot>/index.php`, `<docroot>/vendor/`, etc.

### Option B — FTP

1. Upload the zip to the subdomain's document root using any FTP client
   (FileZilla, Transmit, Cyberduck…).
2. After upload, use the cPanel File Manager to extract in place
   (FTP itself cannot extract).

### Domain root gotcha

The `CHANGE_ME_DOMAIN` subdomain's document root depends on how
it was created in cPanel. The two common cases:

- **Per-subdomain directory** (cleaner): cPanel creates
  `/home/<user>/CHANGE_ME_DOMAIN/`. The steps above use this path.
- **Subdomain under `public_html/`** (default for many cPanel setups):
  the subdomain points at `/home/<user>/public_html/CHANGE_ME_DOMAIN/`
  (a sub-folder of the parent's doc root). Substitute that path in
  every step below.

To verify, in cPanel → **Domains → Manage Domains**, click **Manage**
next to `CHANGE_ME_DOMAIN` — the **Document Root** column shows
exactly which directory the subdomain is served from.

---

## 2. Configure `.env.local`

The zip ships `.env.local.template`. You need to copy it to `.env.local`
and fill in real values.

In cPanel File Manager:

1. Right-click `.env.local.template` → **Copy** → name it `.env.local`.
2. Right-click `.env.local` → **Edit**.
3. Replace every `CHANGE_ME_*` / `CPANEL_*` placeholder with your real
   values.
4. **Critical:** generate two random secrets:
   - `APP_SECRET` — 64 hex chars:
     ```bash
     php -r "echo bin2hex(random_bytes(32));"
     ```
     or
     ```bash
     openssl rand -hex 32
     ```
   - `DEPLOY_KEY` — 32+ hex chars:
     ```bash
     openssl rand -hex 16
     ```
     Treat this like a password; it gates the deploy installer.
5. Save the file.
6. (Recommended) **Right-click `.env.local` → Permissions → 0600**. cPanel
   runs PHP as the same user as your account, so 0644 would still work,
   but 0600 keeps secrets away from anyone who can read files in the
   account.

### What goes where (cheat sheet)

| Variable | Value |
|---|---|
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | random hex (see above) |
| `APP_PROJECT_DIR` | `/home/<cpanel-user>/CHANGE_ME_DOMAIN` (substitute the actual doc root if cPanel uses `public_html/CHANGE_ME_DOMAIN/`) |
| `DEFAULT_URI` | `https://CHANGE_ME_DOMAIN` |
| `DATABASE_URL` | `mysql://USER:PASSWORD@localhost:3306/DBNAME?serverVersion=10.11.18-MariaDB-cll-lve-log&charset=utf8mb4` |
| `MAILER_DSN` | `smtps://noreply@CHANGE_ME_DOMAIN:PASSWORD@localhost:465?verify_peer=0` |
| `MAILER_FROM` | `noreply@CHANGE_ME_DOMAIN` (must be a real cPanel mailbox) |
| `MAILER_FROM_NAME` | `"CHANGE_ME_APP_NAME"` |
| `APP_URL` | `https://CHANGE_ME_DOMAIN` |
| `DEPLOY_KEY` | random hex (see above) |
| `PROD_ADMIN_EMAIL` | `admin@CHANGE_ME_DOMAIN` (or whoever will own the backoffice) |
| `PROD_ADMIN_NAME` | `"Site Administrator"` |

---

## 3. Set PHP 8.4 for this folder

In **MultiPHP Manager**:

1. Tick the checkbox next to the subdomain's document root (the same
   directory you uploaded the zip into).
2. From the "PHP version" dropdown at the top-right, pick **8.4**.
3. Click **Apply**.

---

## 4. Run the installer

In a browser, visit:

```
https://CHANGE_ME_DOMAIN/deploy.php?key=<your-DEPLOY_KEY>
```

(Replace `<your-DEPLOY_KEY>` with the random hex you pasted into
`.env.local`.)

You should see a plaintext page that prints the progress of:

1. `doctrine:migrations:migrate` — creates the schema.
2. `cache:clear --env=prod --no-debug` — clears any pre-baked cache.
3. **Re-instantiates the kernel** — drops the in-memory container
   reference so `cache:warmup` doesn't trip the stale-autoloader bug
   on shared hosts.
4. `cache:warmup --env=prod --no-debug` — pre-compiles the prod cache.
5. `assets:install` — no-op for Vite, but kept for parity.
6. `app:deploy:seed-prod` — creates or synchronizes the two baseline roles
   (`ROLE_ADMIN` and `ROLE_USER`) and, if `PROD_ADMIN_EMAIL` is set, creates
   the initial backoffice admin user as **Pending** and emails a confirmation link.

When the page ends with `✓ deploy.php has self-deleted.`, the deploy
is done.

### What if the page returns 403?

The `DEPLOY_KEY` value in `.env.local` does not match the `?key=`
query string. Edit `.env.local`, save, and retry — the installer is
idempotent. You can also use `?check=deploy-key` for a non-secret
fingerprint comparison.

### What if the page returns 500?

A command in the chain failed. The installer does **not** self-delete on
failure, so the page stays reachable for re-running after you fix the
issue. Read the error message — usually it's a misconfigured
`DATABASE_URL` or `MAILER_DSN`. Check `var/log/prod.log` (download via
File Manager) for the full stack trace.

---

## 5. Activate the admin account

If `PROD_ADMIN_EMAIL` was set in `.env.local`, an admin user is created
in `USER_STATE_PENDING` and a confirmation link is emailed. The
confirmation URL is written to the audit channel before SMTP delivery,
so it is recorded even if email sending fails. In production, the audit
channel is JSON on PHP stderr; retrieve it through the hosting provider's
PHP/application error log if email delivery fails.

Open the link, pick a password (minimum 5 characters), and the admin
becomes Active. Then log in at `https://CHANGE_ME_DOMAIN/login` —
you'll land on `/dashboard`.

### First login checklist

- Log in as the seeded admin.
- Change the admin's display name (and email, if needed) under
  **Account → Profile Settings**.
- Create additional admins from **Administration → Users → New User**.
- The root URL `https://CHANGE_ME_DOMAIN/` redirects to `/login`
  by design — symfony-backoffice-skeleton is a backoffice-only application. There is
  no public homepage; this is intentional. The marketing site lives at
  `https://CHANGE_ME_DOMAIN/`.
- If you skipped the seed step (left `PROD_ADMIN_EMAIL` blank), the
  database has roles but no users. You have two options:
  1. **Create a user manually** by inserting into the `app_user` table
     via phpMyAdmin, then use the same confirmation flow.
  2. **Re-run the seed command** by hitting `deploy.php?key=…` again.
     Existing pending admin users get a fresh confirmation token and email;
     existing active users are left unchanged.

---

## 6. Enable HTTPS

1. In cPanel → **SSL/TLS Status**, click **Run AutoSSL** for
   `CHANGE_ME_DOMAIN`. AutoSSL issues a free Let's Encrypt
   certificate. (Subdomain AutoSSL on cPanel requires the parent
   `CHANGE_ME_DOMAIN` to have AutoSSL in good standing — if the parent
   doesn't, AutoSSL will refuse the subdomain. Re-enable AutoSSL on the
   parent first if that's the case.)
2. Wait 1–2 minutes, refresh the page, and confirm
   `CHANGE_ME_DOMAIN` is covered (subdomains do not get the
   `www.` alias by default — only the bare subdomain).
3. Edit the **shipped `.htaccess`** (download via FTP, edit, re-upload):
   uncomment the line:
   ```
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
   ```
   This enables HSTS. **Do this only after HTTPS is verified working** —
   otherwise browsers cache the policy and refuse to load the HTTP site.

---

## 7. Clean up

- `deploy.php` has self-deleted. Confirm via FTP.
- Delete `DEPLOY_KEY=…` from `.env.local` — the installer is gone, the
  key has no further purpose.
- Delete `prod_admin_email=…` / `prod_admin_name=…` from `.env.local`
  for the same reason.
- Keep `.env.local` at mode `0600` (File Manager → Permissions).

---

## 8. Updating to a new release

When the developers ship a new zip:

1. Upload the new zip to the subdomain's document root. It will not
   overwrite your `.env.local`, but it will overwrite everything else.
2. Extract it via File Manager → right-click → **Extract**.
3. (Optional but recommended) Edit `.htaccess` to re-enable HSTS if you
   disabled it during testing.
4. (Optional) Run the installer again to refresh the cache. **Re-running
   requires you to put `deploy.php` back** — either rebuild the zip
   and re-upload, or copy `deploy.php` from a local clone of the
   repo into the server's doc root, then visit:
   https://CHANGE_ME_DOMAIN/deploy.php?key=<DEPLOY_KEY>&seed=no
   ```
   The `seed=no` skips the admin user creation (so you don't overwrite
   the existing one) but still re-warms the cache.

---

## Troubleshooting

### "Failed to connect to database"

- Check the `DATABASE_URL` host. On cPanel it is almost always
  `localhost`, not `127.0.0.1`. Using `127.0.0.1` works but routes the
  connection through TCP rather than the local socket, which can be
  slower and sometimes blocked.
- Check the user is added to the database. The MySQL Databases UI has
  a separate "Add User to Database" step — adding the user isn't
  enough; you must also click **Add** and pick the privileges (ALL
  PRIVILEGES is fine).

### "Migrations failed"

- Usually a credential issue. Re-check `DATABASE_URL`.
- If the error mentions a specific table or constraint, the schema might
  already exist. Drop the database via phpMyAdmin and re-run the
  installer.

### "Confirmation email never arrives"

- cPanel's outbound SMTP is sometimes rate-limited. Try a different
  sender domain if the issue persists.
- If `MAILER_DSN=smtp://...@localhost:587` fails, try the implicit TLS
  variant: `smtps://...@localhost:465`.
- **Always-available fallback:** the confirmation URL is logged to
  `var/log/audit.log`. Download it via FTP and visit the URL manually.

### SMTP gotchas on cPanel-class hosts

These three issues surface on most cPanel/Managed-hosting setups. Read all of them once before changing `MAILER_DSN` — guessing wastes hours.

**1. `sendmail://` will fail with `Call to undefined function proc_open()`.**

cPanel compiles PHP with `proc_open` in `disable_functions` for security
(it lets PHP spawn arbitrary processes). Symfony's `SendmailTransport`
uses `proc_open` to run `/usr/sbin/sendmail -bs`. Result: even
`sendmail://default` fails. **No `sendmail://…` DSN works on cPanel.**

**Fix:** use SMTP. `smtps://user:pass@localhost:465` (implicit TLS) or
`smtp://user:pass@localhost:587` (STARTTLS).

**2. `smtps://` fails with `Peer certificate CN='cpanelNNN.wnpservers.net' did not match expected CN='localhost'`.**

cPanel's local Exim has a self-signed cert whose CN is the server
hostname (e.g. `cpanel177.wnpservers.net`), not `localhost`. PHP's
TLS validation rejects it.

**Fix:** add `?verify_peer=0` to the DSN. Cert verification is
disabled, TLS encryption stays on:
`smtps://user:pass@localhost:465?verify_peer=0`

**3. The shipped `.env.local.template` example is already right.**

The current default (`smtps://CPANEL_EMAIL_USER:CPANEL_EMAIL_PASSWORD@localhost:465?verify_peer=0`)
handles both issues. If you ever copy-paste an older example from the
internet, double-check it uses `?verify_peer=0`.

### Where the deploy installer logs go in prod

In prod, Monolog writes both channels as JSON to PHP stderr. The hosting
provider determines where those logs are available (often a PHP or
application error-log view in cPanel):

| Channel | Path |
|---|---|
| Symfony errors (the actual exception that caused a 500) | JSON on PHP stderr |
| Admin actions (login, state change, confirmation URL) | JSON on PHP stderr (hosting provider's PHP/application error log) |

If something fails and the only thing you see is a generic "Oops! An
Error Occurred", check the hosting provider's PHP/application error log
for the full stack trace. The confirmation URL is recorded in the audit
channel before SMTP delivery, including when email sending fails.

### "The MAILER_DSN is invalid" error from the seed installer

The DSN parser rejects values that contain unescaped spaces or
non-URL-safe characters. Common shapes that look right but parse wrong:
- `sendmail:///usr/sbin/sendmail -t -i` — Symfony reads the
  command from `?command=` only, not the path; this DSN parses but
  the command option is null and the path is silently dropped.
- `smtps://user:pass@host:587 STARTTLS` — spaces in the body make
  the DSN parser throw.

If you must include spaces (e.g. `?command=...-t -i...`), use `+`:
`sendmail://default?command=/usr/sbin/sendmail+-t+-i`

### "deploy.php says Forbidden"

- `DEPLOY_KEY` mismatch. Edit `.env.local`, save, retry.

### "I cannot navigate into the subdomain's document root from File Manager"

- The subdomain is probably under `public_html/`. Either move the
  subdomain's document root (cPanel → Domains → Manage → Document
  Root), or substitute the actual path (e.g.
  `public_html/CHANGE_ME_DOMAIN/`) for
  `/home/<user>/CHANGE_ME_DOMAIN/` everywhere in this guide.

---

## File map (what's inside the zip)

```
CHANGE_ME_DOMAIN/             ← zip root (or whatever your subdomain's doc root is)
├── .env                              ← prod-defaults (no real secrets)
├── .env.local.template               ← copy this to .env.local
├── .env.local                        ← YOU create this (gitignored)
├── .htaccess                         ← production rules; aliases /uploads/ to public/uploads/
├── README_DEPLOY.md                  ← this file
├── deploy.php                        ← one-shot installer (self-deletes on success)
├── index.php                         ← front controller (vendor/ is a sibling)
├── favicon.svg                       ← moved up from public/
├── build/                            ← Vite bundles (moved up from public/build/)
├── bin/
│   └── console                       ← Symfony console entry
├── composer.json / composer.lock     ← required by the autoloader
├── config/                           ← Symfony configs
├── migrations/                       ← Doctrine migrations
├── src/                              ← app code
├── templates/                        ← Twig templates
├── translations/                     ← i18n YAML
├── var/
│   ├── cache/                        ← rebuilt by the installer
│   └── log/                          ← writable by PHP (audit log lives here)
└── vendor/                           ← production-only deps
```
