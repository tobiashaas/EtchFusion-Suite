<?php
/**
 * Security Headers
 *
 * Adds basic security headers to EFS admin pages only.
 * CSP is intentionally omitted — a migration plugin cannot predict which
 * external resources other plugins, themes, or page builders will load.
 * Site-wide CSP should be managed at the server/hosting level or by a
 * dedicated security plugin.
 *
 * @package    Bricks2Etch
 * @subpackage Security
 * @since      0.5.0
 */

namespace Bricks2Etch\Security;

/**
 * Security Headers Class
 *
 * Sends hardening headers for EFS-owned admin screens.
 */
class EFS_Security_Headers {

	/**
	 * EFS admin page slugs that receive security headers.
	 *
	 * @var string[]
	 */
	private const EFS_PAGE_SLUGS = array(
		'etch-fusion-suite',
		'efs-migration',
		'efs-settings',
		'efs-templates',
	);

	/**
	 * Add security headers to response.
	 *
	 * Only fires on EFS admin pages. Never touches the frontend or
	 * other admin screens to avoid breaking third-party plugins/builders.
	 *
	 * @return void
	 */
	public function add_security_headers() {
		if ( ! $this->is_efs_admin_page() ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		// Prevent clickjacking of our admin UI.
		header( 'X-Frame-Options: SAMEORIGIN' );

		// Prevent MIME-type sniffing.
		header( 'X-Content-Type-Options: nosniff' );

		// Control referrer information.
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	/**
	 * Check whether the current request is an EFS admin page.
	 *
	 * @return bool
	 */
	private function is_efs_admin_page() {
		if ( ! is_admin() ) {
			return false;
		}

		$page = '';
		if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return in_array( $page, self::EFS_PAGE_SLUGS, true );
	}
}
