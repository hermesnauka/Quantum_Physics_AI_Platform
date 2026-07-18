<?php
/**
 * SR-01: mandatory Multi-Factor Authentication for Authors, Editors, and
 * Administrators (everyone above Subscriber).
 *
 * Self-contained TOTP (RFC 6238) implementation rather than a third-party
 * plugin, matching the vendoring approach already used for MathJax
 * (../wp-content/themes/quantumai-theme/assets/vendor/mathjax/SOURCE.md):
 * no supply-chain dependency, and the algorithm is verifiable against the
 * public RFC 6238 Appendix B test vectors.
 *
 * See mu-plugins/mfa/class-quantumai-mfa.php for the actual login-flow
 * integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/mfa/class-quantumai-totp.php';
require_once __DIR__ . '/mfa/class-quantumai-mfa.php';

QuantumAI_MFA::init();
