<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap efs-typography efs-admin-wrap">
	<h1 class="efs-page-title"><?php esc_html_e( 'Etch Fusion Suite', 'etch-fusion-suite' ); ?></h1>

	<?php if ( ! $is_bricks_site && ! $is_etch_site ) : ?>
		<div class="efs-card efs-card--warning">
			<h2><?php esc_html_e( 'No Compatible Builder Detected', 'etch-fusion-suite' ); ?></h2>
			<p><?php esc_html_e( 'Etch Fusion Suite requires either Bricks Builder or Etch PageBuilder running on the source site.', 'etch-fusion-suite' ); ?></p>
			<ul class="efs-list">
				<li><?php esc_html_e( 'Install and activate Bricks Builder on the source WordPress site.', 'etch-fusion-suite' ); ?></li>
				<li><?php esc_html_e( 'Install and activate Etch PageBuilder on the target WordPress site.', 'etch-fusion-suite' ); ?></li>
			</ul>
			<p>
				<a class="button button-primary efs-button" href="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>">
					<?php esc_html_e( 'Go to Themes', 'etch-fusion-suite' ); ?>
				</a>
			</p>
		</div>
	<?php else : ?>
		<?php if ( $is_bricks_site && ! $is_etch_site ) : ?>
		<section class="efs-environment efs-environment--standalone">
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
					<span class="efs-status-label"><?php esc_html_e( 'WordPress Version', 'etch-fusion-suite' ); ?></span>
					<span class="efs-status-value"><?php echo isset( $wp_version ) ? esc_html( $wp_version ) : ''; ?></span>
				</li>
				<li>
					<span class="efs-status-label"><?php esc_html_e( 'PHP Version', 'etch-fusion-suite' ); ?></span>
					<span class="efs-status-value"><?php echo isset( $php_version ) ? esc_html( $php_version ) : ''; ?></span>
				</li>
			</ul>
		</section>
		<?php endif; ?>

		<div class="efs-dashboard">
			<?php if ( $is_bricks_site ) : ?>
				<?php require __DIR__ . '/bricks-setup.php'; ?>
			<?php endif; ?>

			<?php if ( $is_etch_site ) : ?>
				<?php require __DIR__ . '/etch-setup.php'; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
