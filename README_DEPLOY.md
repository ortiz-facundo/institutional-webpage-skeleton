# Institutional website deployment

Deploy as a normal Symfony application on PHP **8.4+**, with Apache's document
root set to the application's **public/** directory. Required PHP extensions:
ctype, iconv, intl and the normal Composer platform requirements. OPcache is
recommended. There is no database, migration, seed account or HTTP installer.

## Build a self-contained release

```bash
python3 scripts/build-release.py
```

This builds Vite assets, copies runtime source into a fresh `dist/release`, and
uses the Docker Composer service to install production dependencies **in that
staging tree**, leaving development vendor intact. It produces
`dist/<package-name>-release.zip`. It excludes local secrets, dev/test env files,
caches, Git, tests and Node dependencies. It does not warm a machine-specific
cache or include real production credentials.

Upload/extract the archive into a private application directory and point the
domain at `public/`. On cPanel, use the domain document-root setting to point to
that directory. If hosting fixes the document root and cannot point at public/,
adapt its public front-controller/private-app layout before deploying; this
archive must not expose the project root.

## Configure the server

Create `.env.local` on the server (not in Git or the release archive):

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<random-production-secret>
PUBLIC_URL=https://your-company.example
CONTACT_MAIL_DSN=smtps://user:password@smtp.example:465
CONTACT_MAIL_FROM=webform@your-company.example
CONTACT_MAIL_FROM_NAME='Your company'
CONTACT_MAIL_TO=contact@your-company.example
CONTACT_MAIL_TO_NAME='Your company'
```

URL-encode SMTP username/password characters as required by Symfony DSNs. Use
an address approved by the SMTP provider as From. The contact transport is
synchronous; no Messenger worker is needed. Without a configured real transport,
submissions report failure rather than claiming delivery.

The PHP user must be able to read configuration and write `var/` (cache, sessions,
logs and rate-limit state). Use the hosting account's owner/group permissions.
For a shell-enabled server:

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
```

For FTP-only hosting, Symfony creates its cache on the first request; ensure
`var/` is writable. Deployment replaces source/vendor/build while preserving
server `.env.local` and the runtime `var/` directory. Confirm `/` returns 200,
static assets load and `/_test/contact` returns 404. Once the company homepage
mounts the form, send a real inquiry and verify delivery and Reply-To. Configure
trusted proxies in Symfony if the site runs behind a reverse proxy.
