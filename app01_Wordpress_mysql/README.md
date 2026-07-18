# app01_Wordpress_mysql

Docker Compose scaffold for the QuantumAI & Physics Educational Platform: WordPress
(PHP 8.3 + Apache) behind nginx, backed by MySQL 8.0. See [PLAN.md](./PLAN.md) for
the full Secure SDLC rationale behind the choices below.

## Layout

* `docker-compose.yml` — `db` (MySQL 8.0), `wordpress` (WP + PHP-Apache), `nginx`
  (reverse proxy, security headers, XML-RPC block).
* `db/init/01-create-app-user.sh` — creates a least-privilege MySQL app user
  (no `DROP TABLE`, no `GRANT OPTION`) instead of relying on the MySQL image's
  default all-privileges user.
* `nginx/templates/wordpress.conf.template` — CSP/security headers, HTTP→HTTPS
  redirect and TLS 1.3 termination (SR-03), blocks `xmlrpc.php`, denies
  dotfiles and PHP execution inside `wp-content/uploads`. Rendered to
  `/etc/nginx/conf.d/wordpress.conf` at container start by nginx's own
  envsubst-on-templates entrypoint step.
* `nginx/docker-entrypoint.d/40-quantumai-tls-setup.sh` — removes the stock
  image's `default.conf` and generates a self-signed TLS cert/key into
  `nginx/certs/` (gitignored) on first start, if one isn't already there.
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
* nginx generates a self-signed certificate on first `docker compose up`
  (`nginx/docker-entrypoint.d/40-quantumai-tls-setup.sh`) and persists it in
  `nginx/certs/` (gitignored) so it survives restarts. Your browser will warn
  about this cert — that's expected for local dev; replace the two files in
  `nginx/certs/` with a CA-issued cert/key (or front the stack with
  Cloudflare/another TLS-terminating proxy instead and drop the `443 ssl`
  server block) before this is reachable from the public internet.
* `ssl_protocols` is pinned to `TLSv1.3` only, per SR-03's literal wording —
  see the comment in the template for the compatibility trade-off.
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

## Security mu-plugins (SR-01, SR-02, SR-05, SR-06, AS-01)

All six are self-contained (no third-party plugin dependency, same
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

## Post-install hardening checklist

These are infrastructure-level defaults and mu-plugins only; the following
still need to be done in the WordPress admin per
[REQUIREMENTS.md](./REQUIREMENTS.md) and [USER_STORIES.md](./USER_STORIES.md):

* Remove the default `admin` username if one exists — `hide-login.php`
  will flag it in the dashboard, but won't delete it for you.
* Replace the self-signed cert in `nginx/certs/` with a CA-issued one (Let's
  Encrypt, your org's CA, etc.) — or put the stack behind Cloudflare (or
  similar) for WAF/DDoS protection and TLS termination instead, dropping the
  `443 ssl` server block from `nginx/templates/wordpress.conf.template` so
  nginx isn't also doing it — in any environment reachable from the public
  internet. As shipped, this compose file terminates TLS itself with a
  cert nobody's browser will trust by default, which is fine for local
  development but not for direct internet exposure.

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
