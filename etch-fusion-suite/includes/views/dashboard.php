<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="efs-typography efs-admin-wrap">
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
		<div class="efs-dashboard">
			<?php if ( $is_bricks_site ) : ?>
				<?php require __DIR__ . '/bricks-setup.php'; ?>
			<?php endif; ?>

			<?php if ( $is_etch_site ) : ?>
				<?php require __DIR__ . '/etch-setup.php'; ?>
			<?php endif; ?>

			<?php require __DIR__ . '/logs.php'; ?>
		</div>
	<?php endif; ?>
</div>
