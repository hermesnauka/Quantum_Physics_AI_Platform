# User Stories & Abuser Stories (SSDLC)

In a Secure SDLC, we define both Functional User Stories (what the software should do) and Abuser Stories (how attackers might misuse the software, and how we mitigate it).

## 1. Functional User Stories
* **US-01:** As a *Reader*, I want to filter articles by "Large Reasoning Models (LRM)" so that I can focus purely on advanced AI reasoning topics.
* **US-02:** As an *Author (Quantum Physicist)*, I want to write articles using LaTeX syntax so that I can easily display complex quantum state equations.
* **US-03:** As an *Editor*, I want a dashboard showing all pending draft articles so that I can review, edit, and publish them quickly.
* **US-04:** As an *IT Professional*, I want to subscribe to a newsletter specifically for "Post-Quantum Cryptography" so I can prepare my infrastructure for future quantum threats.

## 2. Abuser Stories (Security/Threat Modeling)

* **AS-01: Admin Brute Force**
  * **Attacker Goal:** As an *Attacker*, I want to brute-force the WordPress admin login page using default usernames (e.g., `admin`) and common passwords to gain full control of the educational platform.
  * **Mitigation:** Remove default `admin` user. Hide `/wp-admin` URL. Implement Rate Limiting, Fail2Ban, and require MFA for all backend access.

* **AS-02: Cross-Site Scripting (XSS) via Comments**
  * **Attacker Goal:** As an *Attacker*, I want to inject malicious JavaScript into the comment section of an article about LLMs, so that I can steal the session cookies of the site Admin when they review the comment.
  * **Mitigation:** Use strict sanitization (`wp_kses`) on all comment fields. Implement a strong Content Security Policy (CSP) header. Require admin approval for first-time commenters.

* **AS-03: Vulnerable Plugin Exploitation**
  * **Attacker Goal:** As an *Attacker*, I want to scan the site for outdated WordPress plugins with known CVEs to execute Remote Code Execution (RCE) and deface the Quantum Physics pages.
  * **Mitigation:** Enforce a strict plugin whitelisting policy. Automate weekly DAST scans (WPScan). Implement a Web Application Firewall (WAF) to block common exploit payloads.

* **AS-04: SQL Injection via Search/Filtering**
  * **Attacker Goal:** As an *Attacker*, I want to inject SQL commands into the URL parameters used for filtering articles to dump the MySQL database containing user email addresses.
  * **Mitigation:** Utilize WordPress core functions for database queries (`WP_Query`). Any custom queries must strictly use `$wpdb->prepare()`. Ensure the MySQL database user does not have file read/write permissions.
