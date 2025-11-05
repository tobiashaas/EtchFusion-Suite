<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etch_fusion_suite_settings = is_array( $settings ) ? $settings : array();
$etch_fusion_suite_nonce    = is_string( $nonce ) ? $nonce : '';
?>
<section class="efs-card efs-card--source">
	<header class="efs-card__header">
		<h2><?php esc_html_e( 'EFS Site Migration Setup', 'etch-fusion-suite' ); ?></h2>
		<p><?php esc_html_e( 'Configure the connection to your Etch target site and start the migration process.', 'etch-fusion-suite' ); ?></p>
	</header>

	<div class="efs-card__section" id="efs-migration-key-section">
		<h3><?php esc_html_e( 'Migration Key', 'etch-fusion-suite' ); ?></h3>
		<div class="efs-field" data-efs-field>
			<label for="efs-migration-key"><?php esc_html_e( 'Paste Migration Key from Etch', 'etch-fusion-suite' ); ?></label>
			<textarea id="efs-migration-key" rows="4" data-efs-migration-key><?php echo isset( $etch_fusion_suite_settings['migration_key'] ) ? esc_textarea( $etch_fusion_suite_settings['migration_key'] ) : ''; ?></textarea>
		</div>
	</div>

	<div class="efs-card__section" id="efs-start-migration">
		<h3><?php esc_html_e( 'Start Migration', 'etch-fusion-suite' ); ?></h3>
		<form method="post" class="efs-form" data-efs-migration-form>
			<input type="hidden" name="nonce" value="<?php echo esc_attr( $etch_fusion_suite_nonce ); ?>" />
			<input type="hidden" id="efs-migration-token" name="migration_token" data-efs-migration-key-target value="<?php echo isset( $etch_fusion_suite_settings['migration_token'] ) ? esc_attr( $etch_fusion_suite_settings['migration_token'] ) : ''; ?>" />
			<div class="efs-field" data-efs-field>
				<label for="efs-migration-batch-size"><?php esc_html_e( 'Batch Size', 'etch-fusion-suite' ); ?></label>
				<input type="number" id="efs-migration-batch-size" name="batch_size" value="50" min="1" />
			</div>
			<div class="efs-actions efs-actions--inline">
				<button type="submit" class="button button-primary" data-efs-start-migration>
					<?php esc_html_e( 'Start Migration', 'etch-fusion-suite' ); ?>
				</button>
				<button type="button" class="button" data-efs-cancel-migration>
					<?php esc_html_e( 'Cancel', 'etch-fusion-suite' ); ?>
				</button>
			</div>
		</form>
	</div>
</section>
