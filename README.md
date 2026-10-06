# Institutional Webpage Skeleton

Reusable **public-only** institutional website foundation: Symfony 7.4, PHP 8.4,
Apache, Twig, Vite 5 and Tailwind 3.4. The homepage is intentionally empty.
A ready-to-mount contact form sends HTML/plain-text mail using a dedicated SMTP
transport. No accounts, backoffice, database, notifications or editorial CMS.

Company projects own their homepage design, presentation, brand assets and mail
configuration. Shared behavior belongs here. Source lineage is in `SOURCE.md`;
company customization is in `config/site.yaml`, `assets/brand.js` and `.env`.
For a generated company project, read `PROJECT.md` first.

## Local setup

Requires Docker Compose, Python 3, Git, and Node 18.19+ (Node 22 is supported).
Run all commands from this application's root. PHP and Composer run in Docker;
npm runs on the host.

```bash
python3 scripts/setup-local.py
docker compose --profile tools run --rm composer install --no-interaction
npm ci
npm run build
docker compose up -d --build
```

Defaults: website **http://localhost:8050**, Mailpit **http://localhost:8055**,
Vite **http://localhost:5175**. Company projects override ports in `.env`.
Container/network names are isolated by `COMPOSE_PROJECT_NAME`; SMTP is internal
at `mailer:1025`, with no host SMTP port required.

`.env.local` is generated once with a random secret and is ignored by Git. Apache
must be able to read it. Do not replace an existing override file during setup.
Build output and dependencies are ignored. `docker compose down` stops this
project's services. `npm run dev` starts HMR at the project's configured Vite port.

## Public entry points

- `GET /`: blank Twig homepage, company title, metadata and Vite assets.
- `POST /contact`: validated contact inquiry with redirect to `/#contact`.
- `/_test/contact`: form preview **only in APP_ENV=test**; never a dev/prod route.

To include the form during the design stage, replace the blank body block in
`templates/home/index.html.twig` with your page and:

```twig
{% include 'contact/_form.html.twig' with {contact_form: contact_form} %}
```

`HomeController` already supplies the FormView. The partial includes accessible
success/error feedback, the honeypot, and CSRF field. Keep its `id="contact"` for
the redirect fragment. It uses Symfony's ordinary nested `contact[...]` field
names; custom markup must preserve those names and `contact[_token]`.

## Contact behavior

Fields: name, email, optional phone, message. Company-specific categories can be
added in the company's form/data class and email templates when needed.

The flow follows Nova's mailing approach: rate limit (5 attempts/15 minutes/IP),
CSRF, honeypot, server validation, synchronous SMTP, translated flash feedback.
The site's address is the **From**, the visitor is the **Reply-To**; recipients
come from configuration, never from request fields. Mail uses `X-Transport:
contact` to select the dedicated Symfony transport. Null/unconfigured mail
cannot claim successful delivery. Honeypots appear successful but send nothing.
HTML escapes visitor input; text email preserves plain text. Messages and SMTP
credentials are not written into contact logs.

`contact_form` uses Symfony's stateless SameOrigin CSRF manager: form output may
contain the literal `csrf-token`; browsers must submit from the same origin.
CSRF is also checked by Symfony Form. Success/error feedback uses the session.
Rate-limit state is local filesystem cache; a future multi-instance deployment
must share that pool. Proxy deployments must configure trusted proxies before
using forwarded client IPs.

Development routes mail to this project's Mailpit. Production settings belong
in `.env.local`: `CONTACT_MAIL_DSN`, `CONTACT_MAIL_FROM`, `CONTACT_MAIL_FROM_NAME`,
`CONTACT_MAIL_TO`, `CONTACT_MAIL_TO_NAME`, `PUBLIC_URL`, `APP_ENV=prod`, and a real
`APP_SECRET`. Example `.env` addresses intentionally use `.invalid`; no actual
company contact details are assumed.

## Validation

```bash
docker compose config --quiet
docker compose exec php bin/console lint:container
docker compose exec php bin/console lint:yaml config translations
docker compose exec php bin/console lint:twig templates
docker compose exec php vendor/bin/phpunit
docker compose exec -e APP_ENV=test php php tests/mailpit_smoke.php
python3 -m unittest discover -s tests -p 'test_*.py'
```

The functional suite renders the unmounted partial through its test-only fixture.
It covers blank homepage, validation, email escaping/envelope, CSRF, cross-origin
rejection, honeypots, rate limiting and transport failures. The SMTP smoke check
requires Mailpit running and sends one identifiable test inquiry to it.

## Create another company website

From the institutional skeleton (destination parent must exist):

```bash
python3 scripts/create-website.py /path/to/company/website \
  --name 'Your company' --slug company-webpage \
  --http-port 8060 --mailpit-port 8065 --vite-port 5176
```

Use a reviewed, clean, committed institutional source when creating another
website. The initializer preserves Git ancestry, copies source files, excludes
ignored dependencies/builds/secrets/caches, refuses nonempty destinations, sets
independent ports and creates a random local secret. It never stages, commits
or pushes. Its legacy JSON metadata is not synchronization state; establish a
reviewed `skeleton-sync` Git tag separately as described in `BRANCHING.md`.
Install dependencies and build assets using the same setup commands.

Configure company name/metadata/assets in `config/site.yaml`, palette in
`assets/brand.js`, and mail settings in local overrides. Mount the form only when
designing the homepage.

## Shared-code maintenance

Follow `BRANCHING.md` and `AGENTS.md`: forward updates use the `skeleton` remote,
`skeleton-sync` tag and bounded cherry-picks. Reusable downstream improvements
are saved as structured Engram proposals and reviewed here. Generalized commits
carry `Source: <downstream>`; company branding stays downstream. Git mutations
require explicit authorization.
