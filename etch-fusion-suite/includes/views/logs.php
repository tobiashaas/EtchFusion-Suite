<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etch_fusion_suite_container    = function_exists( 'etch_fusion_suite_container' ) ? etch_fusion_suite_container() : null;
$etch_fusion_suite_audit_logger = null;

if ( $etch_fusion_suite_container && $etch_fusion_suite_container->has( 'audit_logger' ) ) {
	$etch_fusion_suite_audit_logger = $etch_fusion_suite_container->get( 'audit_logger' );
} elseif ( class_exists( '\Bricks2Etch\Security\EFS_Audit_Logger' ) ) {
	$etch_fusion_suite_audit_logger = \Bricks2Etch\Security\EFS_Audit_Logger::get_instance();
}
?>
<section class="efs-card efs-card--logs" data-efs-log-panel>
	<header class="efs-card__header">
		<div class="efs-card__title">
			<h2><?php esc_html_e( 'Recent Logs', 'etch-fusion-suite' ); ?></h2>
			<p class="efs-card__subtitle"><?php esc_html_e( 'Security and migration activity from Etch Fusion Suite.', 'etch-fusion-suite' ); ?></p>
		</div>
		<div class="efs-card__actions">
			<button type="button" class="button button-secondary" data-efs-clear-logs>
				<?php esc_html_e( 'Clear Logs', 'etch-fusion-suite' ); ?>
			</button>
		</div>
	</header>

	<div class="efs-logs-filter" data-efs-logs-filter>
		<button type="button" class="efs-logs-filter__btn is-active" data-efs-filter="all">
			<?php esc_html_e( 'All', 'etch-fusion-suite' ); ?>
		</button>
		<button type="button" class="efs-logs-filter__btn" data-efs-filter="migration">
			<?php esc_html_e( 'Migration', 'etch-fusion-suite' ); ?>
		</button>
		<button type="button" class="efs-logs-filter__btn" data-efs-filter="security">
			<?php esc_html_e( 'Security', 'etch-fusion-suite' ); ?>
		</button>
	</div>

	<div class="efs-logs" data-efs-logs-list>
		<p class="efs-empty-state"><?php esc_html_e( 'No logs yet. Migration activity will appear here.', 'etch-fusion-suite' ); ?></p>
	</div>
</section>
