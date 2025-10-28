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

$etch_fusion_suite_logs = $etch_fusion_suite_audit_logger ? $etch_fusion_suite_audit_logger->get_security_logs() : array();
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

	<div class="efs-logs" data-efs-logs>
		<?php if ( empty( $etch_fusion_suite_logs ) ) : ?>
			<p class="efs-empty-state"><?php esc_html_e( 'No logs yet. Migration activity will appear here.', 'etch-fusion-suite' ); ?></p>
		<?php else : ?>
			<?php
			foreach ( $etch_fusion_suite_logs as $etch_fusion_suite_log_entry ) :
				$etch_fusion_suite_entry_level     = isset( $etch_fusion_suite_log_entry['severity'] ) ? $etch_fusion_suite_log_entry['severity'] : 'info';
				$etch_fusion_suite_entry_timestamp = isset( $etch_fusion_suite_log_entry['timestamp'] ) ? $etch_fusion_suite_log_entry['timestamp'] : '';
				$etch_fusion_suite_entry_code      = isset( $etch_fusion_suite_log_entry['event_type'] ) ? $etch_fusion_suite_log_entry['event_type'] : '';
				$etch_fusion_suite_entry_message   = isset( $etch_fusion_suite_log_entry['message'] ) ? $etch_fusion_suite_log_entry['message'] : '';
				$etch_fusion_suite_entry_context   = isset( $etch_fusion_suite_log_entry['context'] ) && is_array( $etch_fusion_suite_log_entry['context'] ) ? $etch_fusion_suite_log_entry['context'] : array();
				?>
				<article class="efs-log-entry efs-log-entry--<?php echo esc_attr( $etch_fusion_suite_entry_level ); ?>">
					<header class="efs-log-entry__header">
						<div class="efs-log-entry__meta">
							<?php if ( $etch_fusion_suite_entry_timestamp ) : ?>
								<time datetime="<?php echo esc_attr( $etch_fusion_suite_entry_timestamp ); ?>">
									<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $etch_fusion_suite_entry_timestamp ) ) ); ?>
								</time>
							<?php endif; ?>
							<?php if ( $etch_fusion_suite_entry_code ) : ?>
								<span class="efs-log-entry__code"><?php echo esc_html( $etch_fusion_suite_entry_code ); ?></span>
							<?php endif; ?>
						</div>
						<span class="efs-log-entry__badge efs-log-entry__badge--<?php echo esc_attr( $etch_fusion_suite_entry_level ); ?>">
							<?php echo esc_html( ucfirst( $etch_fusion_suite_entry_level ) ); ?>
						</span>
					</header>
					<p class="efs-log-entry__message"><?php echo esc_html( $etch_fusion_suite_entry_message ); ?></p>
					<?php if ( ! empty( $etch_fusion_suite_entry_context ) ) : ?>
						<dl class="efs-log-entry__context">
							<?php foreach ( $etch_fusion_suite_entry_context as $etch_fusion_suite_context_key => $etch_fusion_suite_context_value ) : ?>
								<div class="efs-log-entry__context-item">
									<dt><?php echo esc_html( $etch_fusion_suite_context_key ); ?></dt>
									<dd><?php echo esc_html( is_scalar( $etch_fusion_suite_context_value ) ? (string) $etch_fusion_suite_context_value : wp_json_encode( $etch_fusion_suite_context_value ) ); ?></dd>
								</div>
							<?php endforeach; ?>
						</dl>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</section>
