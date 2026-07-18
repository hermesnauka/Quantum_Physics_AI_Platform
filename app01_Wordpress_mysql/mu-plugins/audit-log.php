<?php
/**
 * SR-06: audit log covering login attempts, user/role changes, and content
 * changes.
 *
 * Self-contained (own DB table via dbDelta) rather than a third-party
 * plugin, matching the approach already used for MFA
 * (mfa.php) and MathJax (../wp-content/themes/quantumai-theme/assets/vendor/mathjax/SOURCE.md).
 *
 * See mu-plugins/audit-log/class-quantumai-audit-log.php for the logging
 * hooks, retention, and the Tools -> Audit Log admin screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/audit-log/class-quantumai-audit-log.php';

QuantumAI_Audit_Log::init();
