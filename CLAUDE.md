# QuantumAI & Physics Educational Platform

Multi-app repository for the QuantumAI & Physics Educational Platform. Currently
contains one application, [`app01_Wordpress_mysql/`](./app01_Wordpress_mysql/)
(WordPress + MySQL); further apps are expected to follow the same per-app layout.

This project strictly follows the **Secure Software Development Life Cycle
(SSDLC)** for every app in this repo. See
[AGENTS.md](./AGENTS.md) for the roles/process index, and
[PLAN.md](./PLAN.md), [REQUIREMENTS.md](./REQUIREMENTS.md), and
[USER_STORIES.md](./USER_STORIES.md) for the platform-wide SSDLC plan and
requirements.

Each app directory has its own `CLAUDE.md`/`AGENTS.md` with stack-specific
conventions and dev-environment notes (build/lint commands, container names,
coding conventions) — read the nearest one before working inside an app
folder. This file only covers what's true repo-wide.
