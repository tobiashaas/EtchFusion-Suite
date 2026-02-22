<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$etch_fusion_suite_is_https           = isset( $is_https ) ? (bool) $is_https : is_ssl();
$etch_fusion_suite_site_url           = isset( $site_url ) ? $site_url : home_url();
$etch_fusion_suite_url_parts          = wp_parse_url( $etch_fusion_suite_site_url );
$etch_fusion_suite_host               = isset( $etch_fusion_suite_url_parts['host'] ) ? strtolower( (string) $etch_fusion_suite_url_parts['host'] ) : '';
$etch_fusion_suite_local_hosts        = array( 'localhost', '127.0.0.1', '::1', '[::1]' );
$etch_fusion_suite_is_local           = in_array( $etch_fusion_suite_host, $etch_fusion_suite_local_hosts, true );
$etch_fusion_suite_show_https_warning = ! $etch_fusion_suite_is_https && ! $etch_fusion_suite_is_local;
$etch_fusion_suite_https_warning      = __( 'Warning: This site is using HTTP. Use HTTPS in production to protect migration credentials in transit.', 'etch-fusion-suite' );
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

		<div class="efs-etch-dashboard__block" data-efs-pairing-code-block>
			<h3 class="efs-etch-dashboard__block-title">
				<?php esc_html_e( 'Connect Bricks Source Site', 'etch-fusion-suite' ); ?>
			</h3>
			<p class="efs-etch-dashboard__block-desc">
				<?php esc_html_e( 'Generate a one-time connection URL and paste it into the Bricks migration wizard. The URL expires after 15 minutes and can only be used once — the migration key is generated automatically in the background.', 'etch-fusion-suite' ); ?>
			</p>
			<button type="button" class="button button-primary" data-efs-generate-pairing-code>
				<?php esc_html_e( 'Generate Connection URL', 'etch-fusion-suite' ); ?>
			</button>
			<div data-efs-pairing-code-result hidden>
				<code class="efs-pairing-code" data-efs-pairing-code-display></code>
				<button type="button" class="button" data-efs-copy-pairing-code>
					<?php esc_html_e( 'Copy URL', 'etch-fusion-suite' ); ?>
				</button>
				<span data-efs-pairing-code-expiry></span>
			</div>
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
			<?php esc_html_e( 'JavaScript is required to generate a connection URL.', 'etch-fusion-suite' ); ?>
		</p>
	</noscript>
</section>
