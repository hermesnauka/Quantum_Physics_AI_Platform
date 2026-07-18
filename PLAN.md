# Secure SDLC Plan: QuantumAI Platform

This document outlines the Secure Software Development Life Cycle phases tailored for a WordPress + MySQL architecture.

## Phase 1: Planning & Risk Assessment
* **Asset Identification:** User data, published educational content, administrative credentials, database integrity.
* **Risk Assessment:** High risk of brute-force attacks on `wp-login.php`, plugin vulnerabilities (XSS/SQLi), and DDoS attacks given the high-profile nature of AI/Quantum topics.
* **Mitigation Strategy:** Implement Cloudflare WAF, strict plugin whitelisting, and mandatory Multi-Factor Authentication (MFA).

## Phase 2: Secure Architecture & Design
* **Core:** WordPress (always latest version) + MySQL 8.0+ (Hardened).
* **Database Security:**
  * MySQL user with restricted privileges (no `DROP TABLE` or `GRANT` privileges for the web app user).
  * Encrypted database connections.
* **Application Security:**
  * Custom admin URL (hide standard wp-admin).
  * Content Security Policy (CSP) headers.
  * Disabling XML-RPC to prevent amplification attacks.

## Phase 3: Secure Implementation (Coding)
* **Theme/Plugin Guidelines:**
  * Use WordPress built-in sanitization (`sanitize_text_field`, `sanitize_textarea_field`).
  * Use Late Escaping for outputs (`esc_html`, `esc_attr`).
  * Nonces required for all form submissions and AJAX requests.
  * No direct database queries unless absolutely necessary; use `WP_Query`. If direct queries are used, `wpdb->prepare` is mandatory.

## Phase 4: Testing (Security Verification)
* **SAST:** Automated code scanning (e.g., PHP_CodeSniffer with WordPress Security standard) on CI/CD pipelines.
* **DAST:** Weekly automated scans using WPScan to detect vulnerable plugins/themes.
* **Dependency Check:** Continuous monitoring of WordPress plugin repositories for reported CVEs.

## Phase 5: Deployment & Hardening
* **Server Hardening:** Linux environment with SELinux/AppArmor, Fail2Ban.
* **File Permissions:** `755` for directories, `644` for files, and `wp-config.php` secured at `400` or `440` owned by the web user.
* **Secrets Management:** Database credentials stored securely outside the web root or injected via environment variables.

## Phase 6: Maintenance & Incident Response
* **Patch Management:** Auto-update enabled for minor WP Core and trusted security plugins. Sandbox testing required for major updates.
* **Backups:** Daily incremental and weekly full database/file backups securely stored in an off-site, immutable storage (S3 with object lock).
