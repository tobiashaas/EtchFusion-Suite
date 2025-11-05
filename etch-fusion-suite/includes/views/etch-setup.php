<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$etch_fusion_suite_nonce    = isset( $nonce ) ? $nonce : '';
$etch_fusion_suite_is_https = isset( $is_https ) ? (bool) $is_https : is_ssl();
$etch_fusion_suite_site_url = isset( $site_url ) ? $site_url : home_url();
?>
<section class="efs-card efs-card--target">
	<header class="efs-card__header">
		<h2><?php esc_html_e( 'Etch Target Site Setup', 'etch-fusion-suite' ); ?></h2>
		<p><?php esc_html_e( 'Prepare this site to receive content from your Bricks source site.', 'etch-fusion-suite' ); ?></p>
	</header>

	<div class="efs-card__section">
		<h3><?php esc_html_e( 'Share This Site URL', 'etch-fusion-suite' ); ?></h3>
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

	<div class="efs-card__section">
		<h3><?php esc_html_e( 'Generate Migration Key', 'etch-fusion-suite' ); ?></h3>
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
	</div>

</section>
