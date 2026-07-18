# app01_Wordpress_mysql

WordPress + MySQL implementation of the QuantumAI & Physics Educational
Platform. See the repo root [CLAUDE.md](../CLAUDE.md) for the repo-wide
overview — this file only covers what's specific to this app.

See [AGENTS.md](./AGENTS.md) for scoped agent responsibilities and
[README.md](./README.md) for the full stack layout, mu-plugin inventory, and
first-run setup.

## Dev environment notes

* No `php` binary on the host — lint/check PHP via the running container
  instead: `docker exec quantumai-wp-wordpress-1 php -l <file>`.
* Containers: `quantumai-wp-wordpress-1`, `quantumai-wp-nginx-1`,
  `quantumai-wp-db-1` (compose project name is `quantumai-wp`, set explicitly
  in `docker-compose.yml` to avoid network/volume name collisions with other
  checkouts on the same host).
* Site: `http://localhost:8080` (or `$HTTP_PORT` from `.env`).

## Conventions

* Security mu-plugins in `mu-plugins/` are self-contained — no third-party
  plugin or Composer dependency — same rationale as the vendored MathJax
  build in the theme (`wp-content/themes/quantumai-theme/assets/vendor/mathjax/`).
  One file per SR/AS requirement, `QuantumAI_*` class prefix, and a doc
  comment at the top of each explaining the *why* and any non-obvious
  hook-priority ordering (several of these plugins only work correctly
  because they run after a specific WordPress core hook — see the comments
  before changing priorities).
* mu-plugins can't be deactivated from the wp-admin dashboard by design —
  that's the point of using them for security controls rather than regular
  plugins.
