# app01_Wordpress_mysql

Docker Compose scaffold for the QuantumAI & Physics Educational Platform: WordPress
(PHP 8.3 + Apache) behind nginx, backed by MySQL 8.0. See [PLAN.md](./PLAN.md) for
the full Secure SDLC rationale behind the choices below.

## Layout

* `docker-compose.yml` — `db` (MySQL 8.0), `wordpress` (WP + PHP-Apache), `nginx`
  (`owasp/modsecurity-crs:nginx` — reverse proxy, TLS termination, WAF,
  security headers, XML-RPC block), `wpscan` (opt-in DAST scan profile).
* `db/init/01-create-app-user.sh` — creates a least-privilege MySQL app user
  (no `DROP TABLE`, no `GRANT OPTION`) instead of relying on the MySQL image's
  default all-privileges user.
* `nginx/waf/default.conf.template` — CSP/security headers, HTTP→HTTPS
  redirect and TLS 1.3 termination (SR-03), ModSecurity/CRS (AS-03), blocks
  `xmlrpc.php`, denies dotfiles and PHP execution inside `wp-content/uploads`.
  See "Web Application Firewall (AS-03)" below.
* `nginx/waf/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf` /
  `RESPONSE-999-EXCLUSION-RULES-AFTER-CRS.conf` — CRS's own supported
  extension points for scoped false-positive exclusions (empty; see below).
* `nginx/certs/` — self-signed TLS cert/key, auto-generated on first start,
  gitignored.
* `security/run-wpscan.sh` — the `wpscan` profile's scan script.
* `wp-php/uploads.ini` — upload size/memory PHP overrides.
* `mu-plugins/` — must-use plugins, bind-mounted into `wp-content/mu-plugins`.
* `wp-content/themes/quantumai-theme/` — the platform theme (see below).
* `.env.example` — template for secrets; copy to `.env` (gitignored).

## First run

```bash
cp .env.example .env
# edit .env: set real passwords and generate WordPress salts from
# https://api.wordpress.org/secret-key/1.1/salt/

docker compose up -d
docker compose logs -f wordpress   # wait for it to come up healthy
```

Then visit `https://localhost:8443` (or your `HTTPS_PORT` — plain
`http://localhost:8080` also works but immediately 301-redirects there; see
"TLS / HTTPS" below for why the browser will warn about the certificate) to
run the WordPress install wizard, then activate **QuantumAI Educational
Theme** under Appearance → Themes.

## Theme: QuantumAI Educational Theme

`wp-content/themes/quantumai-theme/` is a from-scratch classic theme
(bind-mounted into the container, so edits on the host show up immediately —
no rebuild needed) covering FR-02 (LaTeX) and FR-03 (topic taxonomy):

* **LaTeX rendering (FR-02)** — MathJax is vendored locally at
  `assets/vendor/mathjax/tex-svg.js` (see `SOURCE.md` next to it for
  provenance/checksum) rather than pulled from a CDN, so it works under the
  site's CSP (`script-src 'self'`) with no external requests at render time.
  It's only enqueued on posts/pages whose content actually contains math
  (`inc/mathjax.php`), to keep the ~700KB payload off pages that don't need
  it (NFR-01).

  Authors write equations directly in post content using:
  * `\( E = mc^2 \)` for inline math
  * `\[ ... \]` or `$$ ... $$` for display/block equations

  Plain single `$` is intentionally *not* treated as a math delimiter —
  this is a funding/pricing-heavy news site, so bare `$` shows up in prose
  far more often than as math. A `[latex]...[/latex]` shortcode is also
  available as an alternative to the raw delimiters; see the doc comment on
  `quantumai_latex_shortcode()` in `inc/mathjax.php` for its one caveat
  (`wpautop` mangles blank lines inside a shortcode — keep multi-line
  equations to a single line, using `\\` for TeX line breaks).

* **Topic taxonomy (FR-03)** — a `topic` taxonomy is registered
  (`inc/taxonomies.php`) and seeded on theme activation with the four
  required terms: Quantum Computing, AI/LLMs/LRMs, Quantum Physics,
  Post-Quantum Cryptography. `taxonomy.php` provides the archive template
  (`/topic/quantum-physics/`, etc.).

* **Theme-level hardening (`inc/security.php`)** — hides the WP version
  string and RSD/WLW discovery links (AS-03 recon reduction), and blocks
  `?author=<id>` username-enumeration probes (AS-01) at a priority earlier
  than core's own canonical-redirect handling, which would otherwise resolve
  the probe to `/author/<username>/` and leak the username before a
  same-priority hook gets a chance to run.

This theme was built and smoke-tested end-to-end against this compose stack
(installed WordPress, activated the theme, published math and non-math
posts, and confirmed MathJax loads only where needed) before being checked
in; it has not been visually reviewed in a browser.

## TLS / HTTPS (SR-03)

All data in transit is encrypted with TLS 1.3, terminated at nginx (not a
mu-plugin — this is infrastructure config, not something a WordPress plugin
can do):

* Every plain-HTTP request on `HTTP_PORT` gets a 301 to the same host on
  `HTTPS_PORT` — nothing is ever served over the unencrypted port.
* nginx (`owasp/modsecurity-crs:nginx` — see "Web Application Firewall"
  below) generates a self-signed certificate on first `docker compose up`
  and persists it in `nginx/certs/` (gitignored) so it survives restarts.
  Your browser will warn about this cert — that's expected for local dev;
  replace the two files in `nginx/certs/` with a CA-issued cert/key (or
  front the stack with Cloudflare/another TLS-terminating proxy instead and
  drop the `443 ssl` server block from `nginx/waf/default.conf.template`)
  before this is reachable from the public internet.
* `ssl_protocols` (`SSL_PROTOCOLS` env var) is pinned to `TLSv1.3` only, per
  SR-03's literal wording — see the comment in the template for the
  compatibility trade-off.
* WordPress is told to trust nginx's `X-Forwarded-Proto` header (only safe
  because nginx is this stack's sole entry point — same reasoning as
  `get_client_ip()` in the audit-log/brute-force-protection mu-plugins) and
  `FORCE_SSL_ADMIN` is set, so wp-admin/login always redirect to HTTPS even
  if something upstream ever forwards a plain-HTTP request directly.
* HSTS (`Strict-Transport-Security`) is sent on every HTTPS response.

## Input/output validation (SR-04)

Also not a mu-plugin — covered by existing conventions rather than new code:

* **Comments** — WordPress core sanitizes comment content server-side
  (`wp_kses` via the `pre_comment_content`/`comment_text` filters), and the
  theme's `comments.php` renders everything through `wp_list_comments()`'s
  own escaping (see the doc comment at the top of that file). Combined with
  the CSP header and `anti-bot.php`'s honeypot on the comment form, that's
  AS-02/SR-04 covered by defaults, not custom code.
* **Contact forms** — SR-04 names these explicitly, but no contact-form
  feature exists yet (it's not in REQUIREMENTS.md's Functional
  Requirements). If one is ever added: server-side sanitization
  (`sanitize_text_field()`/`sanitize_textarea_field()`/`sanitize_email()`),
  late-escaping on any admin-facing display, and the honeypot pattern from
  `mu-plugins/anti-bot.php` are all required before it ships, not optional
  hardening to add later.
* **Everywhere else** — the theme has no raw `$_GET`/`$_POST`/`$_REQUEST`
  output anywhere (checked by grep across `wp-content/themes/quantumai-theme/`);
  the one place a request param is read (`inc/security.php`'s
  author-enumeration block) only calls `isset()` on it, never echoes it.

## SQL injection (AS-04)

No custom search/filter query code exists — article filtering (search,
topic taxonomy archives) is all native `WP_Query`, which core is
responsible for parameterizing safely. Audited every direct `$wpdb` call in
this codebase (`grep -rn '\$wpdb->' mu-plugins wp-content`, checked each
result): the only ones outside `$wpdb->prepare()`/`$wpdb->insert()` are
`$wpdb->prefix` (a constant, not user input) and one
`$wpdb->get_col("... FROM {$table} ...")` in the audit log, where `{$table}`
is that same prefix constant, never a request value. Confirmed live against
the running stack too: a `UNION SELECT`-style payload on the search endpoint
gets a `403` from the WAF (see above) before it would ever reach a query.

## Security mu-plugins (SR-01, SR-02, SR-05, SR-06, AS-01, AS-03)

All seven are self-contained (no third-party plugin dependency, same
rationale as the vendored MathJax build) and bind-mount into
`wp-content/mu-plugins`, so they run on every request and can't be
deactivated from the dashboard:

* **`mfa.php` + `mfa/`** (SR-01) — mandatory TOTP (RFC 6238) two-factor
  login for Author/Editor/Administrator, with hashed one-time recovery
  codes and a self-service profile-page reset. No session is established
  until the second factor is confirmed.
* **`editorial-workflow.php`** (SR-02) — strips `publish_posts` from the
  Author role (core's own capability checks then downgrade "Publish" to
  "Submit for Review" in both the block editor and the REST API) and
  emails Editors/Admins when a draft is submitted for review.
* **`anti-bot.php`** (SR-05) — honeypot + minimum-fill-time check on the
  login, registration, and comment forms. Chosen over an image/puzzle
  CAPTCHA to avoid a third-party script-src CSP exception; see the
  doc comment at the top of the file for the trade-off (stops generic
  bots, not a targeted attacker).
  <br>Note re: AS-02 ("admin approval for first-time commenters") — no
  mu-plugin needed for this part. WordPress's own
  `comment_previously_approved` option defaults to enabled (verified in
  this stack's DB), which already holds a commenter's *first* comment for
  moderation and auto-approves later ones. Combined with `wp_kses`
  sanitization and the CSP header, that's AS-02 covered by defaults +
  infra, not custom code.
* **`audit-log.php` + `audit-log/`** (SR-06) — logs login attempts,
  role/password changes, post publish/status transitions, and
  plugin/theme/option changes to a dedicated DB table (400-day retention
  by default). Reviewable and CSV-exportable under **Tools → Audit Log**
  (`manage_options` only).
* **`brute-force-protection.php`** (AS-01 rate limiting) — per-IP and
  per-username failed-login lockout (20/15min and 5/15min respectively).
  Complements Fail2Ban (PLAN.md Phase 5), which this doesn't replace —
  it's the in-app backstop that works even without log-based tooling
  in front of it.
* **`hide-login.php`** (AS-01 hidden admin URL) — moves the login form to
  a secret path (`WP_LOGIN_SLUG` in `.env`, must be overridden per
  environment) so an attacker can't discover wp-login.php to brute-force
  it. Direct hits to the real `/wp-login.php` or to `/wp-admin/*` while
  logged out both get a plain 404. Also surfaces an admin-dashboard
  warning if a user named "admin" exists (removal itself is left manual —
  deleting a user isn't something to automate silently).
* **`plugin-whitelist.php`** (AS-03 plugin whitelisting) — strips
  `install_plugins`/`activate_plugins` from every role, Administrator
  included, so nobody can add a *regular* plugin through wp-admin (the
  actual whitelist is "none" — this platform's whole design already avoids
  third-party plugins). Escape hatch is a `wp-config.php` constant, not an
  env var — see the doc comment at the top of the file for why, and for the
  honest scope limit (doesn't stop WP-CLI run inside the container).

## Web Application Firewall (AS-03)

`nginx` is now `owasp/modsecurity-crs:nginx` instead of plain `nginx:alpine` —
ModSecurity + the OWASP Core Rule Set in front of everything, not a
mu-plugin (a WAF operates below the application entirely). Only one file is
overridden from the image's own defaults:
`nginx/waf/default.conf.template` → mounted to
`/etc/nginx/templates/conf.d/default.conf.template`, a close port of the
image's own template with this project's TLS/HSTS (SR-03), XML-RPC/config/
dotfile denies (SR-05/AS-01/AS-03), and NFR-01/02 microcache layered in.
Everything else — the image's own ModSecurity wiring, container healthcheck,
CORS handling, real-IP handling — is untouched upstream default.

* **Tuning**: `BLOCKING_PARANOIA=1` (CRS's own recommended lowest-false-
  positive starting point), `MODSEC_RULE_ENGINE=On` (actually blocks, not
  just logs — this is what makes AS-03's "block common exploit payloads"
  true rather than aspirational).
* **Verified against the running stack**, not just assumed: a real SQLi
  payload, a `<script>` XSS payload, and a `sqlmap` User-Agent each got a
  403; the homepage, `wp-json/` + `wp-json/wp/v2/posts`, a real
  wp-login.php-shaped POST, and — the case this specific platform actually
  worried about — bra-ket notation (`|0> + |1>`, `<bra|ket>`) and inline
  LaTeX (`\(E=mc^2\)`) in a query string all passed through untouched. See
  `nginx/waf/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf` for the exact
  tests and how to add a scoped exclusion if a real workflow ever does get
  blocked (empty for now — don't add exclusions pre-emptively).
* **Cert persistence**: this image generates its own self-signed cert on
  first boot (same idea our old hand-rolled SR-03 script used, now built
  into the image) into `nginx/certs/` (bind-mounted to `/etc/nginx/conf`,
  gitignored). If that host directory doesn't already exist with
  write-by-anyone permissions, Docker auto-creates it owned by `root`, and
  this image's *unprivileged* nginx user can't write into it — first run
  needs `mkdir -p nginx/certs && chmod 777 nginx/certs` (same class of issue
  as the `security-reports/` one below, same fix).
* **Ports changed**: the container itself now listens on `8080`/`8443`
  *inside* the container (unprivileged-user constraint of this image) — only
  the host-side mapping (`HTTP_PORT`/`HTTPS_PORT`) is unchanged, so
  `https://localhost:8443` still works exactly as before.

## Vulnerability scanning (AS-03)

`docker compose --profile scan run --rm wpscan` runs a WPScan DAST pass
against the stack (`security/run-wpscan.sh`; not started by a plain
`docker compose up` — it's gated behind the `scan` compose profile) and
writes a timestamped JSON report to `security-reports/` (gitignored).

* **Requires `WPSCAN_API_TOKEN`** in `.env` — wpscan 4.x's CLI calls the
  WPScan API to even start a scan, not just for extra CVE detail; without a
  token it aborts immediately with a 401. Free tier:
  <https://wpscan.com/api/>.
* First run needs `mkdir -p security-reports && chmod 777 security-reports`
  once — Docker auto-creates the bind-mount target as `root` otherwise,
  which the (non-root) wpscan container can't write into.
* **Weekly automation**: `.github/workflows/wpscan-weekly.yml` spins up a
  throwaway instance of this whole stack inside a GitHub Actions runner
  every Monday, scans it, uploads the report as a build artifact, then
  tears it down — nothing here is ever reachable from outside the runner.
  Needs a `WPSCAN_API_TOKEN` repo secret. This workflow was authored and
  reasoned through carefully but not run against a live GitHub Actions
  runner — check the first scheduled/dispatched run's logs before trusting
  it unattended.

## Post-install hardening checklist

These are infrastructure-level defaults and mu-plugins only; the following
still need to be done in the WordPress admin per
[REQUIREMENTS.md](./REQUIREMENTS.md) and [USER_STORIES.md](./USER_STORIES.md):

* Remove the default `admin` username if one exists — `hide-login.php`
  will flag it in the dashboard, but won't delete it for you.
* Replace the self-signed cert in `nginx/certs/` with a CA-issued one (Let's
  Encrypt, your org's CA, etc.) — or put the stack behind Cloudflare (or
  similar) for DDoS protection and TLS termination instead, dropping the
  `443 ssl` server block from `nginx/waf/default.conf.template` so nginx
  isn't also doing it (Cloudflare's WAF and this stack's ModSecurity/CRS one
  are complementary, not redundant — keeping both is fine too) — in any
  environment reachable from the public internet. As shipped, this compose
  file terminates TLS itself with a cert nobody's browser will trust by
  default, which is fine for local development but not for direct internet
  exposure.
* Get a WPScan API token (`WPSCAN_API_TOKEN` in `.env` and as a GitHub
  Actions repo secret) so the AS-03 scanning described below actually runs.

## Notes on the security choices baked into this scaffold

* **DB network isolation** — `db` only sits on the `internal` Docker network,
  which has no route out and isn't published to the host. Only `wordpress` can
  reach it.
* **Restricted DB user** — see `db/init/01-create-app-user.sh`. Trade-off: a
  plugin's "delete my tables on uninstall" routine will fail silently since the
  app user can't `DROP TABLE`; treat that as a manual DBA cleanup step.
* **Encrypted DB connections** — MySQL runs with `--require-secure-transport=ON`
  and auto-generates a self-signed cert on first boot; WordPress connects with
  `MYSQLI_CLIENT_SSL` set via `MYSQL_CLIENT_FLAGS`.
* **XML-RPC** disabled both at nginx (403) and at the WordPress layer
  (`xmlrpc_enabled` filter).
* **No in-dashboard file editing** — `DISALLOW_FILE_EDIT` is set, closing off
  one of the more common post-compromise persistence paths.
* **Client-facing TLS (SR-03)** — nginx terminates TLS 1.3 with a
  self-signed cert generated on first start, same "generate one if none
  exists" pattern as MySQL's own cert above. Chosen over requiring a real
  cert up front so the stack still works out of the box for local dev; the
  trade-off is the browser cert warning until that self-signed cert is
  swapped out per the hardening checklist.
* **WAF (AS-03)** — `owasp/modsecurity-crs:nginx` in place of plain
  `nginx:alpine`, at `BLOCKING_PARANOIA=1`/`MODSEC_RULE_ENGINE=On`. See "Web
  Application Firewall (AS-03)" above for what was actually verified against
  this stack (real attack payloads blocked, real content/traffic patterns
  for this specific platform — bra-ket notation, LaTeX — not blocked).
