---
name: wp-security-mu-plugin
description: Implement (or verify) a WordPress security requirement from REQUIREMENTS.md (SR-xx) or USER_STORIES.md (AS-xx) as a self-contained mu-plugin in app01_Wordpress_mysql, following this repo's existing SSDLC conventions. Use when asked to add, harden, or review a security control for this WordPress app — e.g. "implement SR-03", "add rate limiting for X", "cover AS-04", or "review the mu-plugins for gaps".
---

# WordPress security mu-plugin workflow

This app already implements SR-01, SR-02, SR-05, SR-06, and AS-01 as mu-plugins
(`mu-plugins/mfa.php`, `editorial-workflow.php`, `anti-bot.php`, `audit-log.php`,
`brute-force-protection.php`, `hide-login.php`, `disable-xmlrpc.php`). Use this
same recipe for any new SR-xx/AS-xx work in this app rather than reaching for a
third-party plugin.

## 1. Find the requirement and check for existing coverage

* SR-xx items: `REQUIREMENTS.md` § "Security Requirements".
* AS-xx items (abuser story + its `Mitigation:` line): `USER_STORIES.md` § "Abuser Stories".
* Before writing anything, `grep -rn "SR-xx\|AS-xx" mu-plugins/` and check the
  README's "Security mu-plugins" section — the requirement may already be
  fully or partially covered (e.g. AS-02 is covered by WordPress core defaults,
  not custom code — see that section for the reasoning).

## 2. Decide: custom mu-plugin, or core/config default?

Don't write code for something WordPress or the existing stack already does.
Only build a mu-plugin when there's a real gap. If the requirement turns out
to be satisfied by a core capability check, a default option value, or an
existing nginx/docker-compose setting, document that in the README instead
(see the AS-02 note there as the template for this).

## 3. If a plugin is needed, follow these conventions

* Self-contained, no third-party plugin or Composer dependency — same
  rationale as the vendored MathJax build in the theme: no supply-chain
  dependency, fully auditable.
* One file per requirement in `mu-plugins/`, `QuantumAI_*` class prefix. If
  the logic is more than ~30 lines, split into a `mu-plugins/<name>/` folder
  with the loader `mu-plugins/<name>.php` doing only the `require_once` +
  `::init()` (see `mfa.php` vs `mfa/class-quantumai-mfa.php` for the pattern).
* Top-of-file doc comment covering: which SR/AS this satisfies, *why*
  self-contained instead of a plugin (usually copy the standard rationale
  above), and any non-obvious hook-priority ordering.
* **Hook-priority gotcha to check every time:** anything that needs to
  override a successful WordPress action (login, password check) must hook
  `authenticate` at a priority *greater than 20*, not less. Hooking earlier
  looks like it should short-circuit, but `wp_authenticate_username_password()`
  ignores a `WP_Error` an earlier filter already set (as long as posted
  fields aren't empty) and re-validates anyway — silently overwriting your
  error with a valid `WP_User` if the password happens to be correct. This
  bit `brute-force-protection.php` and `anti-bot.php` during development;
  both now hook at priority 21/22, after core's own check at 20. Test this
  specifically, don't just trust the hook order looking right on paper.
* If the mu-plugin needs environment-specific config (a secret slug, a
  retention period, etc.), wire it through `wp-config` `define()` in
  `docker-compose.yml` + a `getenv()` call, and add the variable to
  `.env.example` with a comment on what the safe default is for local dev
  vs. why production must override it (see `WP_LOGIN_SLUG` for the pattern).

## 4. Update the docs that describe current coverage

* `README.md` → "Security mu-plugins" section: add a bullet for the new
  plugin (what it does, which SR/AS, one line on the trade-off if any).
* `README.md` → "Post-install hardening checklist": remove any manual step
  the new plugin now automates.

## 5. Verify before calling it done

There's no `php` binary on the host — use the running container:

```bash
docker exec quantumai-wp-wordpress-1 php -l <file>
```

Lint only catches syntax errors, not logic. For anything touching the login/
auth flow, also exercise it for real against `http://localhost:8080` (or
`$HTTP_PORT`): trigger the actual attack scenario from the abuser story (e.g.
for a lockout, actually fail login enough times and confirm the *next*
correct-password attempt is still blocked — the exact bug class described in
step 3 above only shows up this way, not in a code read).
