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
* `nginx/conf.d/wordpress.conf` — CSP/security headers, blocks `xmlrpc.php`,
  denies dotfiles and PHP execution inside `wp-content/uploads`.
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

Then visit `http://localhost:8080` (or your `HTTP_PORT`) to run the WordPress
install wizard, then activate **QuantumAI Educational Theme** under
Appearance → Themes.

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
* Put the stack behind Cloudflare (or similar) for WAF/TLS termination/DDoS
  protection in any environment reachable from the public internet — this compose
  file serves plain HTTP on `HTTP_PORT` and is meant for local development /
  behind a TLS-terminating proxy, not direct internet exposure.

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
