<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etch_fusion_suite_component_nonce    = isset( $nonce ) ? $nonce : '';
$etch_fusion_suite_component_context  = isset( $context ) ? sanitize_key( $context ) : '';
$etch_fusion_suite_component_api_key  = isset( $settings ) && is_array( $settings ) && isset( $settings['api_key'] ) ? $settings['api_key'] : '';

$etch_fusion_suite_component_heading = 'bricks' === $etch_fusion_suite_component_context
	? __( 'Generate migration key from Etch site', 'etch-fusion-suite' )
	: __( 'Generate migration key for Bricks site', 'etch-fusion-suite' );

$etch_fusion_suite_component_description = 'bricks' === $etch_fusion_suite_component_context
	? __( 'Request a fresh migration key from the connected Etch site. Requires the target URL and application password saved above.', 'etch-fusion-suite' )
	: __( 'Create a migration key that your Bricks site can import. Share the generated key with the Bricks dashboard.', 'etch-fusion-suite' );
?>
<div class="efs-migration-key" data-efs-migration-key-component data-context="<?php echo esc_attr( $etch_fusion_suite_component_context ); ?>">
	<div class="efs-migration-key__intro">
		<h3 class="efs-migration-key__title"><?php echo esc_html( $etch_fusion_suite_component_heading ); ?></h3>
		<p class="efs-migration-key__description"><?php echo esc_html( $etch_fusion_suite_component_description ); ?></p>
	</div>
	<form method="post" class="efs-inline-form" data-efs-generate-key>
		<input type="hidden" name="nonce" value="<?php echo esc_attr( $etch_fusion_suite_component_nonce ); ?>" />
		<input type="hidden" name="context" value="<?php echo esc_attr( $etch_fusion_suite_component_context ); ?>" />
		<?php if ( 'bricks' === $etch_fusion_suite_component_context ) : ?>
			<div class="efs-form-group">
				<label for="efs-etch-url" class="efs-label"><?php esc_html_e( 'Etch Site URL (for Docker/custom hosts)', 'etch-fusion-suite' ); ?></label>
				<p class="efs-help-text"><?php esc_html_e( 'Optional: Override the site URL for Docker setups or custom host mappings. Leave empty to use the default site URL.', 'etch-fusion-suite' ); ?></p>
				<input type="url" id="efs-etch-url" name="target_url" class="efs-input" placeholder="<?php echo esc_attr( home_url() ); ?>" />
			</div>
			<input type="hidden" name="api_key" value="<?php echo esc_attr( $etch_fusion_suite_component_api_key ); ?>" />
		<?php endif; ?>
		<button type="submit" class="button button-secondary">
			<?php esc_html_e( 'Generate Migration Key', 'etch-fusion-suite' ); ?>
		</button>
	</form>
</div>
