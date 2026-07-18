# Vendored dependency: MathJax

* **File:** `tex-svg.js`
* **Package/version:** `mathjax@3.2.2` (`es5/tex-svg.js` build)
* **Fetched from:** `https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js`
* **Fetched on:** 2026-07-18
* **SHA-256:** `d4295dc33744836935c1399feece5159577b34c5c8ffb9f1c6324cd82e03a882`
* **License:** Apache License 2.0 (see `LICENSE` in this directory)

Vendored (not CDN-loaded) so the site's CSP can stay at `script-src 'self'`
(see `../../../../nginx/conf.d/wordpress.conf`) and so front-end rendering
doesn't depend on a third-party host being reachable. The `tex-svg` build
renders equations to inline SVG, so it makes no external font requests
either.

To upgrade: download the new `es5/tex-svg.js` from the same jsDelivr path
with the desired version pinned, replace this file, update the SHA-256 above,
and bump `QUANTUMAI_MATHJAX_VERSION` in `inc/mathjax.php`.
