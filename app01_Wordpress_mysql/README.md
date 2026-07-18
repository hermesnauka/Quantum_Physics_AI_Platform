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
install wizard.

## Post-install hardening checklist

These are infrastructure-level defaults only; the following still need to be done
in the WordPress admin per [REQUIREMENTS.md](./REQUIREMENTS.md) and
[USER_STORIES.md](./USER_STORIES.md):

* Install an MFA plugin and enforce it for Author/Editor/Admin roles (SR-01).
* Rename/hide the default admin login path (AS-01).
* Remove the default `admin` username if it was ever created.
* Set the Editor approval workflow so Authors cannot publish directly (SR-02).
* Install a CAPTCHA/anti-bot plugin on login, registration, and comment forms (SR-05).
* Install an audit-log plugin (SR-06).
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
