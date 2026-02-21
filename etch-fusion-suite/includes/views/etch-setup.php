<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$etch_fusion_suite_nonce       = isset( $nonce ) ? $nonce : '';
$etch_fusion_suite_is_https    = isset( $is_https ) ? (bool) $is_https : is_ssl();
$etch_fusion_suite_site_url    = isset( $site_url ) ? $site_url : home_url();
$etch_fusion_suite_url_parts   = wp_parse_url( $etch_fusion_suite_site_url );
$etch_fusion_suite_host        = isset( $etch_fusion_suite_url_parts['host'] ) ? strtolower( (string) $etch_fusion_suite_url_parts['host'] ) : '';
$etch_fusion_suite_local_hosts = array( 'localhost', '127.0.0.1', '::1', '[::1]' );
$etch_fusion_suite_is_local    = in_array( $etch_fusion_suite_host, $etch_fusion_suite_local_hosts, true );
$etch_fusion_suite_show_https_warning = ! $etch_fusion_suite_is_https && ! $etch_fusion_suite_is_local;
$etch_fusion_suite_security_note      = __( 'Treat this key like a password.', 'etch-fusion-suite' );
$etch_fusion_suite_https_warning      = __( 'Warning: This site is using HTTP. Use HTTPS in production to protect migration credentials in transit.', 'etch-fusion-suite' );
$etch_fusion_suite_generated_url       = '';
$etch_fusion_suite_generation_message  = '';
$etch_fusion_suite_generation_error    = '';
$etch_fusion_suite_expires_label       = '';

if (
	'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) )
	&& isset( $_POST['efs_etch_generate_url'] )
) {
	$etch_fusion_suite_submitted_nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $etch_fusion_suite_submitted_nonce, 'efs_nonce' ) ) {
		$etch_fusion_suite_generation_error = __( 'Security check failed. Refresh the page and try again.', 'etch-fusion-suite' );
	} elseif ( ! function_exists( 'etch_fusion_suite_container' ) || ! etch_fusion_suite_container()->has( 'settings_controller' ) ) {
		$etch_fusion_suite_generation_error = __( 'Settings service unavailable. Please reload and try again.', 'etch-fusion-suite' );
	} else {
		$etch_fusion_suite_settings_controller = etch_fusion_suite_container()->get( 'settings_controller' );
		$etch_fusion_suite_target_url          = isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : $etch_fusion_suite_site_url;
		$etch_fusion_suite_generation_result   = $etch_fusion_suite_settings_controller->generate_migration_key(
			array(
				'target_url' => $etch_fusion_suite_target_url,
			)
		);

		if ( is_wp_error( $etch_fusion_suite_generation_result ) ) {
			$etch_fusion_suite_generation_error = $etch_fusion_suite_generation_result->get_error_message();
		} else {
			$etch_fusion_suite_generated_url      = isset( $etch_fusion_suite_generation_result['migration_url'] ) ? (string) $etch_fusion_suite_generation_result['migration_url'] : '';
			$etch_fusion_suite_generation_message = isset( $etch_fusion_suite_generation_result['message'] ) ? (string) $etch_fusion_suite_generation_result['message'] : __( 'Migration key generated.', 'etch-fusion-suite' );

			if ( isset( $etch_fusion_suite_generation_result['treat_as_password_note'] ) && is_string( $etch_fusion_suite_generation_result['treat_as_password_note'] ) && '' !== $etch_fusion_suite_generation_result['treat_as_password_note'] ) {
				$etch_fusion_suite_security_note = $etch_fusion_suite_generation_result['treat_as_password_note'];
			}

			if ( isset( $etch_fusion_suite_generation_result['security_warning'] ) && is_string( $etch_fusion_suite_generation_result['security_warning'] ) && '' !== $etch_fusion_suite_generation_result['security_warning'] ) {
				$etch_fusion_suite_show_https_warning = true;
				$etch_fusion_suite_https_warning      = $etch_fusion_suite_generation_result['security_warning'];
			}

			$etch_fusion_suite_expires_at = isset( $etch_fusion_suite_generation_result['expires_at'] ) ? (string) $etch_fusion_suite_generation_result['expires_at'] : '';
			$etch_fusion_suite_expiration_seconds = isset( $etch_fusion_suite_generation_result['expiration_seconds'] ) ? (int) $etch_fusion_suite_generation_result['expiration_seconds'] : 8 * 60 * 60;
			$etch_fusion_suite_hours              = max( 1, (int) round( $etch_fusion_suite_expiration_seconds / 3600 ) );
			$etch_fusion_suite_relative_expiry    = sprintf(
				/* translators: %d: hour count */
				_n( 'Expires in %d hour.', 'Expires in %d hours.', $etch_fusion_suite_hours, 'etch-fusion-suite' ),
				$etch_fusion_suite_hours
			);

			if ( '' !== $etch_fusion_suite_expires_at ) {
				$etch_fusion_suite_expires_label = sprintf(
					/* translators: 1: relative expiry label, 2: expiration date/time text from server */
					__( '%1$s (at %2$s).', 'etch-fusion-suite' ),
					$etch_fusion_suite_relative_expiry,
					$etch_fusion_suite_expires_at
				);
			} else {
				$etch_fusion_suite_expires_label = $etch_fusion_suite_relative_expiry;
			}
		}
	}

}

// Show currently active key (if still valid), including after regular page reload.
if ( '' === $etch_fusion_suite_generated_url
	&& function_exists( 'etch_fusion_suite_container' )
	&& etch_fusion_suite_container()->has( 'token_manager' )
) {
	$etch_fusion_suite_token_manager = etch_fusion_suite_container()->get( 'token_manager' );
	$etch_fusion_suite_current       = $etch_fusion_suite_token_manager->get_current_migration_display_data();
	if ( ! empty( $etch_fusion_suite_current['migration_url'] ) ) {
		$etch_fusion_suite_generated_url = (string) $etch_fusion_suite_current['migration_url'];
		$etch_fusion_suite_expires_at    = isset( $etch_fusion_suite_current['expires_at'] ) ? (string) $etch_fusion_suite_current['expires_at'] : '';
		$etch_fusion_suite_secs          = isset( $etch_fusion_suite_current['expiration_seconds'] ) ? (int) $etch_fusion_suite_current['expiration_seconds'] : 0;
		$etch_fusion_suite_hours         = max( 1, (int) round( $etch_fusion_suite_secs / 3600 ) );
		$etch_fusion_suite_relative_expiry = sprintf(
			/* translators: %d: hour count */
			_n( 'Expires in %d hour.', 'Expires in %d hours.', $etch_fusion_suite_hours, 'etch-fusion-suite' ),
			$etch_fusion_suite_hours
		);
		$etch_fusion_suite_expires_label = '' !== $etch_fusion_suite_expires_at
			? sprintf(
				/* translators: 1: relative expiry label, 2: expiration date/time text from server */
				__( '%1$s (at %2$s).', 'etch-fusion-suite' ),
				$etch_fusion_suite_relative_expiry,
				$etch_fusion_suite_expires_at
			)
			: $etch_fusion_suite_relative_expiry;
	}
}
?>
<section class="efs-card efs-card--target efs-etch-dashboard" data-efs-etch-dashboard>
	<header class="efs-card__header efs-card__header--row">
		<h2 class="efs-card__title"><?php esc_html_e( 'Etch Target Site Setup', 'etch-fusion-suite' ); ?></h2>
		<p class="efs-card__header-desc"><?php esc_html_e( 'Prepare this site to receive content from your Bricks source site.', 'etch-fusion-suite' ); ?></p>
	</header>

	<div class="efs-card__section efs-etch-dashboard__section">
		<?php if ( $etch_fusion_suite_show_https_warning ) : ?>
			<p class="efs-etch-dashboard__warning" data-efs-https-warning>
				<?php echo esc_html( $etch_fusion_suite_https_warning ); ?>
			</p>
		<?php else : ?>
			<p class="efs-etch-dashboard__warning" data-efs-https-warning hidden></p>
		<?php endif; ?>

		<div class="efs-etch-dashboard__block">
			<h3 class="efs-etch-dashboard__block-title"><?php esc_html_e( 'Generate Migration Key', 'etch-fusion-suite' ); ?></h3>
			<p class="efs-etch-dashboard__block-desc"><?php esc_html_e( 'Create a secure migration key to share with the Bricks site administrator.', 'etch-fusion-suite' ); ?></p>
			<form method="post" class="efs-inline-form efs-etch-dashboard__actions" data-efs-etch-generate-url>
				<input type="hidden" name="nonce" value="<?php echo esc_attr( $etch_fusion_suite_nonce ); ?>" />
				<input type="hidden" name="context" value="etch" />
				<input type="hidden" name="efs_etch_generate_url" value="1" />
				<input type="hidden" name="target_url" value="<?php echo esc_attr( $etch_fusion_suite_site_url ); ?>" />
				<button
					type="submit"
					class="button button-primary"
					data-efs-generate-migration-url
					data-efs-generate-label="<?php esc_attr_e( 'Generate', 'etch-fusion-suite' ); ?>"
					data-efs-regenerate-label="<?php esc_attr_e( 'Regenerate', 'etch-fusion-suite' ); ?>"
					aria-label="<?php esc_attr_e( 'Generate migration key', 'etch-fusion-suite' ); ?>"
				>
					<?php echo '' !== $etch_fusion_suite_generated_url ? esc_html__( 'Regenerate', 'etch-fusion-suite' ) : esc_html__( 'Generate', 'etch-fusion-suite' ); ?>
				</button>
			</form>
			<?php if ( '' !== $etch_fusion_suite_generation_error ) : ?>
				<p class="efs-etch-dashboard__warning" role="alert">
					<?php echo esc_html( $etch_fusion_suite_generation_error ); ?>
				</p>
			<?php elseif ( '' !== $etch_fusion_suite_generation_message ) : ?>
				<p class="description" role="status">
					<?php echo esc_html( $etch_fusion_suite_generation_message ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php $etch_fusion_suite_has_key = '' !== trim( (string) $etch_fusion_suite_generated_url ); ?>
		<div class="efs-etch-dashboard__block efs-field efs-etch-dashboard__generated-url" data-efs-generated-url-wrapper<?php echo $etch_fusion_suite_has_key ? '' : ' hidden'; ?>>
			<h3 class="efs-etch-dashboard__block-title"><?php esc_html_e( 'Migration Key', 'etch-fusion-suite' ); ?></h3>
			<label for="efs-generated-migration-url" class="efs-etch-dashboard__block-desc"><?php esc_html_e( 'Share this key with the Bricks site', 'etch-fusion-suite' ); ?></label>
			<textarea
				id="efs-generated-migration-url"
				rows="8"
				readonly
				data-efs-generated-migration-url
				aria-live="polite"
				aria-label="<?php esc_attr_e( 'Generated migration key', 'etch-fusion-suite' ); ?>"
			><?php echo esc_textarea( $etch_fusion_suite_generated_url ); ?></textarea>
			<div class="efs-actions efs-actions--inline efs-etch-dashboard__copy-actions" data-efs-copy-url-actions>
				<button
					type="button"
					class="button"
					data-efs-copy-migration-url
					aria-label="<?php esc_attr_e( 'Copy migration key', 'etch-fusion-suite' ); ?>"
				>
					<?php esc_html_e( 'Copy Key', 'etch-fusion-suite' ); ?>
				</button>
				<button
					type="button"
					class="button efs-revoke-key-btn"
					data-efs-revoke-migration-url
					<?php echo $etch_fusion_suite_has_key ? '' : ' hidden'; ?>
					aria-label="<?php esc_attr_e( 'Revoke migration key', 'etch-fusion-suite' ); ?>"
				>
					<?php esc_html_e( 'Revoke Key', 'etch-fusion-suite' ); ?>
				</button>
			</div>
			<p class="efs-etch-dashboard__url-note" data-efs-security-note>
				<?php
				$etch_fusion_suite_note_highlighted = str_replace( 'password', '<span class="efs-security-highlight">password</span>', $etch_fusion_suite_security_note );
				echo wp_kses( $etch_fusion_suite_note_highlighted, array( 'span' => array( 'class' => true ) ) );
				if ( '.' !== substr( trim( $etch_fusion_suite_security_note ), -1 ) ) {
					echo '. ';
				} else {
					echo ' ';
				}
				echo wp_kses(
					sprintf(
						/* translators: %s: highlighted phrase "until it expires" */
						__( 'Anyone with this key can start migration into this site %s.', 'etch-fusion-suite' ),
						'<span class="efs-security-highlight">until it expires</span>'
					),
					array( 'span' => array( 'class' => true ) )
				);
				?>
			</p>
			<p class="efs-etch-dashboard__expiry" data-efs-expiry-display<?php echo '' === $etch_fusion_suite_expires_label ? ' hidden' : ''; ?>>
				<?php echo '' !== $etch_fusion_suite_expires_label ? esc_html( $etch_fusion_suite_expires_label ) : esc_html__( 'Expires in 8 hours.', 'etch-fusion-suite' ); ?>
			</p>
		</div>
	</div>

	<div class="efs-etch-environment efs-card__section">
		<h3 class="efs-etch-environment__title"><?php esc_html_e( 'System Status', 'etch-fusion-suite' ); ?></h3>
		<dl class="efs-etch-environment__list">
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'Etch', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value">
					<?php
					if ( ! empty( $etch_version ) ) {
						/* translators: %s: Etch version number */
						echo esc_html( sprintf( __( 'Version %s detected', 'etch-fusion-suite' ), $etch_version ) );
					} else {
						esc_html_e( 'Detected', 'etch-fusion-suite' );
					}
					?>
				</dd>
			</div>
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'PHP Version', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value"><?php echo isset( $php_version ) ? esc_html( $php_version ) : ''; ?></dd>
			</div>
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'Site URL', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value"><?php echo esc_html( isset( $site_url ) ? $site_url : '' ); ?></dd>
			</div>
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'HTTPS', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value"><?php echo ! empty( $is_https ) ? esc_html__( 'Enabled', 'etch-fusion-suite' ) : esc_html__( 'Disabled', 'etch-fusion-suite' ); ?></dd>
			</div>
		</dl>
	</div>

	<div class="efs-etch-receiving-banner" data-efs-receiving-banner hidden>
		<p class="efs-etch-receiving-banner__text" data-efs-receiving-banner-text>
			<?php esc_html_e( 'Receiving migration activity.', 'etch-fusion-suite' ); ?>
		</p>
		<button type="button" class="button" data-efs-receiving-expand>
			<?php esc_html_e( 'Expand', 'etch-fusion-suite' ); ?>
		</button>
	</div>

	<div class="efs-etch-receiving-takeover" data-efs-receiving-display hidden>
		<div class="efs-etch-receiving-takeover__inner">
			<header class="efs-etch-receiving-takeover__header">
				<div>
					<p class="efs-etch-receiving-takeover__eyebrow"><?php esc_html_e( 'Etch Receive Mode', 'etch-fusion-suite' ); ?></p>
					<h3 data-efs-receiving-title><?php esc_html_e( 'Receiving Migration', 'etch-fusion-suite' ); ?></h3>
					<p data-efs-receiving-subtitle><?php esc_html_e( 'Incoming data from the source site is being processed.', 'etch-fusion-suite' ); ?></p>
				</div>
				<button type="button" class="button button-secondary" data-efs-receiving-minimize>
					<?php esc_html_e( 'Minimize', 'etch-fusion-suite' ); ?>
				</button>
			</header>

			<dl class="efs-etch-receiving-takeover__meta">
				<div>
					<dt><?php esc_html_e( 'Source Site', 'etch-fusion-suite' ); ?></dt>
					<dd data-efs-receiving-source><?php esc_html_e( 'Waiting for source...', 'etch-fusion-suite' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Phase', 'etch-fusion-suite' ); ?></dt>
					<dd data-efs-receiving-phase>
						<span class="status-badge is-active">
							<span class="status-dot"></span>
							<?php esc_html_e( 'Initializing', 'etch-fusion-suite' ); ?>
						</span>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Items Received', 'etch-fusion-suite' ); ?></dt>
					<dd data-efs-receiving-items>0</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Last Activity', 'etch-fusion-suite' ); ?></dt>
					<dd data-efs-receiving-last-activity><?php esc_html_e( 'Not yet available', 'etch-fusion-suite' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Elapsed', 'etch-fusion-suite' ); ?></dt>
					<dd data-efs-receiving-elapsed hidden>—</dd>
				</div>
			</dl>

			<p class="efs-etch-receiving-takeover__status" data-efs-receiving-status>
				<?php esc_html_e( 'Listening for incoming migration packets.', 'etch-fusion-suite' ); ?>
			</p>

			<div class="efs-etch-receiving-takeover__actions">
				<a
					class="button button-primary"
					href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"
					data-efs-view-received-content
					hidden
				>
					<?php esc_html_e( 'View Received Content', 'etch-fusion-suite' ); ?>
				</a>
				<button type="button" class="button" data-efs-receiving-dismiss hidden>
					<?php esc_html_e( 'Dismiss', 'etch-fusion-suite' ); ?>
				</button>
			</div>
		</div>
	</div>

	<noscript>
		<p class="description">
			<?php esc_html_e( 'JavaScript is disabled. You can still generate a migration key using the button above and copy it manually from the field once generated.', 'etch-fusion-suite' ); ?>
		</p>
	</noscript>
</section>
