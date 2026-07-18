# Agents & Roles — app01_Wordpress_mysql

Scoped agent responsibilities for this app. See the repo root
[AGENTS.md](../AGENTS.md) for the platform-wide mission, SSDLC document
index, and the abstract definition of each role below.

### 🕵️ Security Architect Agent
* WAF/CSP headers, XML-RPC blocking: `nginx/conf.d/wordpress.conf`.
* TLS 1.3+ / PQC for data-in-transit: terminated at whatever reverse proxy
  fronts this stack in production (Cloudflare or equivalent) — this
  compose file itself serves plain HTTP on `HTTP_PORT` for local dev only;
  see the README's "Post-install hardening checklist".

### 👨‍💻 Lead WordPress Developer Agent
* Theme: `wp-content/themes/quantumai-theme/`.
* Security mu-plugins: `mu-plugins/` — self-contained (no third-party
  plugin dependency), `QuantumAI_*` class prefix, one file per SR/AS
  requirement. See README's "Security mu-plugins" section for the current
  inventory and rationale.
* No `php` binary on the host — lint with
  `docker exec quantumai-wp-wordpress-1 php -l <file>`.

### 🧪 QA / Security Tester Agent
* Requirement checklist to verify against: [REQUIREMENTS.md](./REQUIREMENTS.md),
  [USER_STORIES.md](./USER_STORIES.md).
* Quick verification loop for a mu-plugin change:
  `docker exec quantumai-wp-wordpress-1 php -l <file>`, then exercise the
  actual flow at `http://localhost:8080` (or `$HTTP_PORT`).
* WPScan/SAST automation is not yet wired into CI for this app — see
  PLAN.md Phase 4 for the intended process.

### ⚙️ DevOps & Database Admin Agent
* Compose stack: `docker-compose.yml` — services `db`, `wordpress`,
  `nginx`; project name pinned to `quantumai-wp`.
* DB hardening: `db/init/01-create-app-user.sh` (least-privilege app user,
  no `DROP TABLE`/`GRANT OPTION`; encrypted connections enforced via
  `MYSQL_CLIENT_FLAGS`/`--require-secure-transport`).
* XML-RPC disabled at both nginx (`nginx/conf.d/wordpress.conf`) and
  WordPress (`xmlrpc_enabled` filter in `mu-plugins/disable-xmlrpc.php`).
