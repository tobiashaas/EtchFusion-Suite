<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etch_fusion_suite_progress_context = array(
	'status'     => isset( $progress_data['status'] ) ? (string) $progress_data['status'] : esc_html__( 'Awaiting migration start.', 'etch-fusion-suite' ),
	'percentage' => isset( $progress_data['percentage'] ) ? (float) $progress_data['percentage'] : 0,
	'steps'      => isset( $progress_data['steps'] ) && is_array( $progress_data['steps'] ) ? $progress_data['steps'] : array(),
);

( static function ( array $context ) {
	$status     = $context['status'];
	$percentage = max( 0, min( 100, $context['percentage'] ) );
	$steps      = array_map(
		static function ( $step ) {
			$label = '';
			if ( is_array( $step ) ) {
				if ( isset( $step['label'] ) ) {
					$label = (string) $step['label'];
				} elseif ( isset( $step['slug'] ) ) {
					$label = (string) $step['slug'];
				}
				return array(
					'label'     => $label,
					'active'    => ! empty( $step['active'] ),
					'completed' => ! empty( $step['completed'] ),
				);
			}

			return array(
				'label'     => $label,
				'active'    => false,
				'completed' => false,
			);
		},
		$context['steps']
	);
	?>
	<section class="efs-card efs-card--progress">
		<header class="efs-card__header">
			<h2><?php esc_html_e( 'Migration Progress', 'etch-fusion-suite' ); ?></h2>
			<p data-efs-current-step><?php echo esc_html( $status ); ?></p>
		</header>

		<div class="efs-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $percentage ); ?>" data-efs-progress data-efs-progress-value="<?php echo esc_attr( $percentage ); ?>">
			<span class="efs-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></span>
		</div>

		<?php if ( ! empty( $steps ) ) : ?>
			<ol class="efs-steps" data-efs-steps>
				<?php foreach ( $steps as $step ) : ?>
					<li class="efs-migration-step<?php echo $step['active'] ? ' is-active' : ''; ?><?php echo $step['completed'] ? ' is-complete' : ''; ?>">
						<?php echo esc_html( $step['label'] ); ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php else : ?>
			<ol class="efs-steps" data-efs-steps></ol>
		<?php endif; ?>

		<footer class="efs-card__footer">
			<button type="button" class="button" data-efs-cancel-migration><?php esc_html_e( 'Cancel Migration', 'etch-fusion-suite' ); ?></button>
		</footer>
	</section>
	<?php
} )( $etch_fusion_suite_progress_context );
