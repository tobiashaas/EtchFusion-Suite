<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etch_fusion_suite_extractor_nonce = isset( $nonce ) ? $nonce : wp_create_nonce( 'efs_nonce' );
$etch_fusion_suite_saved_templates = isset( $saved_templates ) && is_array( $saved_templates ) ? $saved_templates : array();
?>
<div class="wrap efs-typography efs-admin-wrap">
	<h1><?php esc_html_e( 'Etch Fusion Suite', 'etch-fusion-suite' ); ?></h1>

	<?php if ( ! $is_bricks_site && ! $is_etch_site ) : ?>
		<div class="efs-card efs-card--warning">
			<h2><?php esc_html_e( 'No Compatible Builder Detected', 'etch-fusion-suite' ); ?></h2>
			<p><?php esc_html_e( 'Etch Fusion Suite requires either Bricks Builder or Etch PageBuilder running on the source site.', 'etch-fusion-suite' ); ?></p>
			<ul class="efs-list">
				<li><?php esc_html_e( 'Install and activate Bricks Builder on the source WordPress site.', 'etch-fusion-suite' ); ?></li>
				<li><?php esc_html_e( 'Install and activate Etch PageBuilder on the target WordPress site.', 'etch-fusion-suite' ); ?></li>
			</ul>
			<p>
				<a class="button button-primary efs-button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
					<?php esc_html_e( 'Go to Plugins', 'etch-fusion-suite' ); ?>
				</a>
			</p>
		</div>
	<?php else : ?>
		<section class="efs-environment">
			<h2><?php esc_html_e( 'Environment Summary', 'etch-fusion-suite' ); ?></h2>
			<ul class="efs-status-list">
				<li class="<?php echo $is_bricks_site ? 'is-active' : 'is-inactive'; ?>">
					<span class="efs-status-label"><?php esc_html_e( 'Bricks Builder', 'etch-fusion-suite' ); ?></span>
					<span class="efs-status-value"><?php echo $is_bricks_site ? esc_html__( 'Detected', 'etch-fusion-suite' ) : esc_html__( 'Not detected', 'etch-fusion-suite' ); ?></span>
				</li>
				<li class="<?php echo $is_etch_site ? 'is-active' : 'is-inactive'; ?>">
					<span class="efs-status-label"><?php esc_html_e( 'Etch PageBuilder', 'etch-fusion-suite' ); ?></span>
					<span class="efs-status-value"><?php echo $is_etch_site ? esc_html__( 'Detected', 'etch-fusion-suite' ) : esc_html__( 'Not detected', 'etch-fusion-suite' ); ?></span>
				</li>
				<li>
					<span class="efs-status-label"><?php esc_html_e( 'Site URL', 'etch-fusion-suite' ); ?></span>
					<span class="efs-status-value"><?php echo esc_html( $site_url ); ?></span>
				</li>
			</ul>
		</section>

		<div class="efs-dashboard">
			<?php if ( $is_bricks_site ) : ?>
				<?php require __DIR__ . '/bricks-setup.php'; ?>
			<?php endif; ?>

			<?php if ( $is_etch_site ) : ?>
				<?php require __DIR__ . '/etch-setup.php'; ?>
			<?php endif; ?>
		</div>

		<section class="efs-dashboard-tabs" data-efs-tabs>
			<nav class="efs-tabs__nav" role="tablist">
				<button class="efs-tab is-active" data-efs-tab="progress" role="tab" aria-selected="true" aria-controls="efs-tab-progress">
					<?php esc_html_e( 'Migration Progress', 'etch-fusion-suite' ); ?>
				</button>
				<button class="efs-tab" data-efs-tab="logs" role="tab" aria-selected="false" aria-controls="efs-tab-logs">
					<?php esc_html_e( 'Recent Logs', 'etch-fusion-suite' ); ?>
				</button>
				<?php if ( $is_etch_site ) : ?>
					<button class="efs-tab" data-efs-tab="templates" role="tab" aria-selected="false" aria-controls="efs-tab-templates">
						<?php esc_html_e( 'Template Extractor', 'etch-fusion-suite' ); ?>
					</button>
				<?php endif; ?>
			</nav>

			<div class="efs-tabs__panels">
				<div id="efs-tab-progress" class="efs-tab__panel is-active" role="tabpanel">
					<?php require __DIR__ . '/migration-progress.php'; ?>
				</div>
				<div id="efs-tab-logs" class="efs-tab__panel" role="tabpanel" hidden>
					<?php require __DIR__ . '/logs.php'; ?>
				</div>
				<?php if ( $is_etch_site ) : ?>
					<div id="efs-tab-templates" class="efs-tab__panel" role="tabpanel" hidden data-efs-tab-panel="templates">
						<?php
						$etch_fusion_suite_extractor_nonce = isset( $etch_fusion_suite_extractor_nonce ) ? $etch_fusion_suite_extractor_nonce : wp_create_nonce( 'efs_nonce' );
						$etch_fusion_suite_saved_templates = isset( $etch_fusion_suite_saved_templates ) ? $etch_fusion_suite_saved_templates : array();
						require __DIR__ . '/template-extractor.php';
						?>
					</div>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>
</div>
