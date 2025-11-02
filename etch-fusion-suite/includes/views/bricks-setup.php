<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$etch_fusion_suite_settings = isset( $settings ) && is_array( $settings ) ? $settings : array();
$etch_fusion_suite_nonce    = isset( $nonce ) ? $nonce : '';
$etch_fusion_suite_key_args = isset( $migration_key_args ) && is_array( $migration_key_args ) ? $migration_key_args : array();
$efs_sections               = array(
	'connection' => array(
		'id'      => 'efs-accordion-connection',
		'title'   => __( 'Connection Settings', 'etch-fusion-suite' ),
		'content' => 'connection',
		'open'    => true,
	),
	'migration_key' => array(
		'id'      => 'efs-accordion-migration-key',
		'title'   => __( 'Migration Key', 'etch-fusion-suite' ),
		'content' => 'migration_key',
	),
	'migration' => array(
		'id'      => 'efs-accordion-start-migration',
		'title'   => __( 'Start Migration', 'etch-fusion-suite' ),
		'content' => 'migration',
	),
);
?>
<section class="efs-card efs-card--source efs-accordion" data-efs-accordion>
	<header class="efs-card__header">
		<h2><?php esc_html_e( 'EFS Site Migration Setup', 'etch-fusion-suite' ); ?></h2>
		<p><?php esc_html_e( 'Configure the connection to your Etch target site and start the migration process.', 'etch-fusion-suite' ); ?></p>
	</header>

	<?php foreach ( $efs_sections as $section_key => $section_config ) : ?>
		<?php
		$section_id       = $section_config['id'];
		$section_open     = isset( $section_config['open'] ) ? (bool) $section_config['open'] : false;
		$section_controls = $section_id . '-region';
		?>
		<section class="efs-accordion__section<?php echo $section_open ? ' is-expanded' : ''; ?>" data-efs-accordion-section data-section="<?php echo esc_attr( $section_key ); ?>">
			<button
				class="efs-accordion__header"
				type="button"
				id="<?php echo esc_attr( $section_id ); ?>"
				data-efs-accordion-header
				aria-expanded="<?php echo $section_open ? 'true' : 'false'; ?>"
				aria-controls="<?php echo esc_attr( $section_controls ); ?>"
			>
				<span class="efs-accordion__title"><?php echo esc_html( $section_config['title'] ); ?></span>
			</button>
			<div
				class="efs-accordion__content"
				id="<?php echo esc_attr( $section_controls ); ?>"
				data-efs-accordion-content
				role="region"
				aria-labelledby="<?php echo esc_attr( $section_id ); ?>"
				<?php echo $section_open ? '' : ' hidden'; ?>
			>
				<?php if ( 'connection' === $section_config['content'] ) : ?>
					<form method="post" class="efs-form" data-efs-settings-form>
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $etch_fusion_suite_nonce ); ?>" />
						<div class="efs-field" data-efs-field>
							<label for="efs-target-url"><?php esc_html_e( 'Etch Site URL', 'etch-fusion-suite' ); ?></label>
							<input type="url" id="efs-target-url" name="target_url" value="<?php echo isset( $etch_fusion_suite_settings['target_url'] ) ? esc_url( $etch_fusion_suite_settings['target_url'] ) : ''; ?>" required />
						</div>
						<div class="efs-field" data-efs-field>
							<label id="efs-api-key-label" for="efs-api-key"><?php esc_html_e( 'Application Password', 'etch-fusion-suite' ); ?></label>
							<p id="efs-api-key-description" class="efs-field-description"><?php esc_html_e( 'Enter the 24-character application password. Spaces and separators will be removed automatically.', 'etch-fusion-suite' ); ?></p>
							<div class="efs-pin-input-container" data-efs-pin-input aria-labelledby="efs-api-key-label" aria-describedby="efs-api-key-description">
								<input type="hidden" id="efs-api-key" name="api_key" value="<?php echo isset( $etch_fusion_suite_settings['api_key'] ) ? esc_attr( $etch_fusion_suite_settings['api_key'] ) : ''; ?>" required />
							</div>
							<noscript>
								<input type="password" name="api_key" autocomplete="off" />
							</noscript>
							<button type="button" class="button button-secondary efs-pin-paste-button" data-efs-pin-paste><?php esc_html_e( 'Paste from Clipboard', 'etch-fusion-suite' ); ?></button>
						</div>
						<div class="efs-actions efs-actions--inline">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Connection Settings', 'etch-fusion-suite' ); ?></button>
							<button type="button" class="button" data-efs-test-connection-trigger><?php esc_html_e( 'Test Connection', 'etch-fusion-suite' ); ?></button>
						</div>
					</form>
				<?php elseif ( 'migration_key' === $section_config['content'] ) : ?>
					<?php
					$component_defaults = array(
						'nonce'    => $etch_fusion_suite_nonce,
						'context'  => 'bricks',
						'settings' => array(
							'target_url' => isset( $etch_fusion_suite_settings['target_url'] ) ? $etch_fusion_suite_settings['target_url'] : '',
							'api_key'    => isset( $etch_fusion_suite_settings['api_key'] ) ? $etch_fusion_suite_settings['api_key'] : '',
						),
					);
					$component_config = wp_parse_args( $etch_fusion_suite_key_args, $component_defaults );
					(function ( $component_args ) {
						$component_args = wp_parse_args(
							$component_args,
							array(
								'nonce'    => '',
								'context'  => '',
								'settings' => array(),
							)
						);
						$nonce    = $component_args['nonce'];
						$context  = $component_args['context'];
						$settings = is_array( $component_args['settings'] ) ? $component_args['settings'] : array();
						require __DIR__ . '/migration-key-component.php';
					})( $component_config );
					unset( $component_defaults, $component_config );
					?>
					<div class="efs-field" data-efs-field>
						<label for="efs-migration-key"><?php esc_html_e( 'Paste Migration Key from Etch', 'etch-fusion-suite' ); ?></label>
						<textarea id="efs-migration-key" name="migration_key" rows="4" data-efs-migration-key><?php echo isset( $etch_fusion_suite_settings['migration_key'] ) ? esc_textarea( $etch_fusion_suite_settings['migration_key'] ) : ''; ?></textarea>
					</div>
					<div class="efs-actions efs-actions--inline">
						<button type="button" class="button" data-efs-copy-button data-efs-target="#efs-migration-key" data-toast-success="<?php echo esc_attr__( 'Migration key copied.', 'etch-fusion-suite' ); ?>">
							<?php esc_html_e( 'Copy Key', 'etch-fusion-suite' ); ?>
						</button>
					</div>
				<?php elseif ( 'migration' === $section_config['content'] ) : ?>
					<form method="post" class="efs-form" data-efs-migration-form>
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $etch_fusion_suite_nonce ); ?>" />
						<input type="hidden" name="efs_migration_token_auto" value="1" />
						<div class="efs-field" data-efs-field>
							<label for="efs-migration-token"><?php esc_html_e( 'Migration Token', 'etch-fusion-suite' ); ?></label>
							<input type="text" id="efs-migration-token" name="migration_token" class="efs-input--readonly" readonly />
						</div>
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
				<?php endif; ?>
			</div>
		</section>
	<?php endforeach; ?>
</section>
