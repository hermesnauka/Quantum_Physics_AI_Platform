# Project Agents & SSDLC Orchestration

This document serves as the master index for the QuantumAI Educational Platform project. 

## 1. Project Mission
To create a fast, secure, and highly available WordPress-based platform dedicated to publishing the latest discoveries in:
* Quantum Computing & Quantum Physics
* Artificial Intelligence (AI)
* Large Language Models (LLMs) & Large Reasoning Models (LRMs)
* Post-Quantum Cryptography (PQC)

## 2. SSDLC Documentation Links
The following documents define the Secure SDLC process for this WordPress/MySQL stack:
* [PLAN.md](./PLAN.md) - The overarching Secure SDLC Plan and Architecture.
* [REQUIREMENTS.md](./REQUIREMENTS.md) - Functional, Non-Functional, and Security Requirements.
* [USER_STORIES.md](./USER_STORIES.md) - Standard User Stories and Security-focused "Abuser Stories".

## 3. Project Agents & Roles
To maintain SSDLC compliance, the following roles (Agents) are defined. This
is the platform-wide definition of each role; an app directory may have its
own `AGENTS.md` scoping these roles to that app's concrete files and
commands (e.g. [app01_Wordpress_mysql/AGENTS.md](./app01_Wordpress_mysql/AGENTS.md)).

### 🕵️ Security Architect Agent
* **Responsibilities:** Threat modeling, defining security boundaries, configuring Web Application Firewall (WAF), and enforcing Post-Quantum Cryptography standards for data-in-transit (TLS 1.3+).

### 👨‍💻 Lead WordPress Developer Agent
* **Responsibilities:** Secure theme development, implementing strict input validation/sanitization (`esc_html`, `esc_url`, `wpdb->prepare`), and ensuring no sensitive data is exposed.

### 🧪 QA / Security Tester Agent
* **Responsibilities:** Running Static Application Security Testing (SAST) on custom plugins/themes, Dynamic Application Security Testing (DAST) using tools like WPScan, and verifying Role-Based Access Control (RBAC).

### ⚙️ DevOps & Database Admin Agent
* **Responsibilities:** MySQL database hardening, server-side security, automated encrypted backups, patch management for WordPress core and plugins, and disabling XML-RPC.
