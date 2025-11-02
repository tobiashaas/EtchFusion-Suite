<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$etch_fusion_suite_nonce    = isset( $nonce ) ? $nonce : '';
$etch_fusion_suite_is_https = isset( $is_https ) ? (bool) $is_https : is_ssl();
$etch_fusion_suite_site_url = isset( $site_url ) ? $site_url : home_url();
$etch_fusion_suite_sections = array(
	'application_password' => array(
		'id'      => 'efs-accordion-application-password',
		'title'   => __( 'Create an Application Password', 'etch-fusion-suite' ),
		'content' => 'application_password',
		'open'    => true,
	),
	'site_url'             => array(
		'id'      => 'efs-accordion-site-url',
		'title'   => __( 'Share This Site URL', 'etch-fusion-suite' ),
		'content' => 'site_url',
	),
	'migration_key'        => array(
		'id'      => 'efs-accordion-generate-key',
		'title'   => __( 'Generate Migration Key', 'etch-fusion-suite' ),
		'content' => 'migration_key',
	),
	'feature_flags'        => array(
		'id'      => 'efs-accordion-feature-flags',
		'title'   => __( 'Feature Flags', 'etch-fusion-suite' ),
		'content' => 'feature_flags',
	),
);
?>
<section class="efs-card efs-card--target efs-accordion" data-efs-accordion>
	<header class="efs-card__header">
		<h2><?php esc_html_e( 'Etch Target Site Setup', 'etch-fusion-suite' ); ?></h2>
		<p><?php esc_html_e( 'Prepare this site to receive content from your Bricks source site.', 'etch-fusion-suite' ); ?></p>
	</header>

	<?php foreach ( $etch_fusion_suite_sections as $etch_fusion_suite_section_key => $etch_fusion_suite_section_config ) : ?>
		<?php
		$etch_fusion_suite_section_id       = $etch_fusion_suite_section_config['id'];
		$etch_fusion_suite_section_open     = isset( $etch_fusion_suite_section_config['open'] ) ? (bool) $etch_fusion_suite_section_config['open'] : false;
		$etch_fusion_suite_section_controls = $etch_fusion_suite_section_id . '-region';
		?>
		<section class="efs-accordion__section<?php echo $etch_fusion_suite_section_open ? ' is-expanded' : ''; ?>" data-efs-accordion-section data-section="<?php echo esc_attr( $etch_fusion_suite_section_key ); ?>">
			<button
				class="efs-accordion__header"
				type="button"
				id="<?php echo esc_attr( $etch_fusion_suite_section_id ); ?>"
				data-efs-accordion-header
				aria-expanded="<?php echo $etch_fusion_suite_section_open ? 'true' : 'false'; ?>"
				aria-controls="<?php echo esc_attr( $etch_fusion_suite_section_controls ); ?>"
			>
				<span class="efs-accordion__title"><?php echo esc_html( $etch_fusion_suite_section_config['title'] ); ?></span>
			</button>
			<div
				class="efs-accordion__content"
				id="<?php echo esc_attr( $etch_fusion_suite_section_controls ); ?>"
				data-efs-accordion-content
				role="region"
				aria-labelledby="<?php echo esc_attr( $etch_fusion_suite_section_id ); ?>"
				<?php echo $etch_fusion_suite_section_open ? '' : ' hidden'; ?>
			>
				<?php if ( 'application_password' === $etch_fusion_suite_section_config['content'] ) : ?>
					<div class="efs-card__section efs-card__section--borderless">
						<?php if ( ! $etch_fusion_suite_is_https ) : ?>
							<div class="notice notice-warning efs-notice">
								<h3><?php esc_html_e( 'HTTPS Recommended', 'etch-fusion-suite' ); ?></h3>
								<p><?php esc_html_e( 'Application Passwords work best over HTTPS. For production environments, ensure HTTPS is enabled.', 'etch-fusion-suite' ); ?></p>
							</div>
						<?php endif; ?>
						<ol class="efs-steps">
							<li><?php esc_html_e( 'Navigate to Users → Profile in this WordPress dashboard.', 'etch-fusion-suite' ); ?></li>
							<li><?php esc_html_e( 'Scroll to Application Passwords and add a new password.', 'etch-fusion-suite' ); ?></li>
							<li><?php esc_html_e( 'Name the password “Etch Fusion Suite” for easy identification.', 'etch-fusion-suite' ); ?></li>
							<li><?php esc_html_e( 'Copy the generated password and use it on your Bricks site.', 'etch-fusion-suite' ); ?></li>
						</ol>
						<p>
							<a class="button" href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">
								<?php esc_html_e( 'Open Application Passwords', 'etch-fusion-suite' ); ?>
							</a>
						</p>
					</div>
				<?php elseif ( 'site_url' === $etch_fusion_suite_section_config['content'] ) : ?>
					<div class="efs-card__section efs-card__section--borderless">
						<p><?php esc_html_e( 'Provide this URL to the Bricks site during migration setup.', 'etch-fusion-suite' ); ?></p>
						<div class="efs-field" data-efs-field>
							<label for="efs-site-url"><?php esc_html_e( 'Site URL', 'etch-fusion-suite' ); ?></label>
							<input id="efs-site-url" type="text" readonly value="<?php echo esc_attr( $etch_fusion_suite_site_url ); ?>" />
						</div>
						<div class="efs-actions efs-actions--inline">
							<button type="button" class="button" data-efs-copy="#efs-site-url" data-toast-success="<?php echo esc_attr__( 'Site URL copied to clipboard.', 'etch-fusion-suite' ); ?>">
								<?php esc_html_e( 'Copy URL', 'etch-fusion-suite' ); ?>
							</button>
						</div>
					</div>
				<?php elseif ( 'migration_key' === $etch_fusion_suite_section_config['content'] ) : ?>
					<?php
					$etch_fusion_suite_component_defaults = array(
						'nonce'    => $etch_fusion_suite_nonce,
						'context'  => 'etch',
						'settings' => array(
							'target_url' => $etch_fusion_suite_site_url,
						),
					);
					$etch_fusion_suite_component_config   = isset( $migration_key_args ) && is_array( $migration_key_args )
						? wp_parse_args( $migration_key_args, $etch_fusion_suite_component_defaults )
						: $etch_fusion_suite_component_defaults;
					( function ( $etch_fusion_suite_component_args ) {
						$component_args = wp_parse_args(
							$etch_fusion_suite_component_args,
							array(
								'nonce'    => '',
								'context'  => '',
								'settings' => array(),
							)
						);
						$nonce          = $component_args['nonce'];
						$context        = $component_args['context'];
						$settings       = is_array( $component_args['settings'] ) ? $component_args['settings'] : array();
						require __DIR__ . '/migration-key-component.php';
					} )( $etch_fusion_suite_component_config );
					unset( $etch_fusion_suite_component_defaults, $etch_fusion_suite_component_config );
					?>
					<div class="efs-field" data-efs-field>
						<label for="efs-generated-key"><?php esc_html_e( 'Latest Generated Key', 'etch-fusion-suite' ); ?></label>
						<textarea id="efs-generated-key" rows="3" readonly data-efs-migration-key></textarea>
					</div>
					<div class="efs-actions efs-actions--inline">
						<button type="button" class="button" data-efs-copy="#efs-generated-key" data-toast-success="<?php echo esc_attr__( 'Migration key copied to clipboard.', 'etch-fusion-suite' ); ?>">
							<?php esc_html_e( 'Copy Key', 'etch-fusion-suite' ); ?>
						</button>
					</div>
				<?php elseif ( 'feature_flags' === $etch_fusion_suite_section_config['content'] ) : ?>
					<form method="post" class="efs-feature-flags-form" data-efs-feature-flags>
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $etch_fusion_suite_nonce ); ?>" />
						<div class="efs-field efs-field--checkbox">
							<input
								id="efs-feature-template-extractor"
								type="checkbox"
								name="feature_flags[template_extractor]"
								value="1"
								<?php checked( efs_feature_enabled( 'template_extractor' ), true ); ?>
							/>
							<label for="efs-feature-template-extractor">
								<?php esc_html_e( 'Enable Template Extractor (Framer → Etch conversion)', 'etch-fusion-suite' ); ?>
							</label>
						</div>
						<p class="description">
							<?php esc_html_e( 'Disabling a feature hides it from the dashboard without deleting any associated data.', 'etch-fusion-suite' ); ?>
						</p>
						<div class="efs-actions efs-actions--inline">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Feature Flags', 'etch-fusion-suite' ); ?></button>
						</div>
					</form>
				<?php endif; ?>
			</div>
		</section>
	<?php endforeach; ?>
</section>
