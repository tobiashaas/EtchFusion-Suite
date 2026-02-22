<?php
/**
 * Admin Notice Manager
 *
 * Displays persistent admin notices for headless migrations so administrators
 * can monitor progress even after closing the migration wizard tab.
 *
 * @package Bricks2Etch\Admin
 */

namespace Bricks2Etch\Admin;

use Bricks2Etch\Services\EFS_Progress_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Admin_Notice_Manager
 */
class EFS_Admin_Notice_Manager {

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/**
	 * @param EFS_Progress_Manager $progress_manager
	 */
	public function __construct( EFS_Progress_Manager $progress_manager ) {
		$this->progress_manager = $progress_manager;
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'render_migration_notice' ) );
	}

	/**
	 * Render an admin notice when a headless migration is active.
	 */
	public function render_migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active = get_option( 'efs_active_migration', array() );
		if ( ! is_array( $active ) || ( $active['mode'] ?? '' ) !== 'headless' ) {
			return;
		}

		$progress = $this->progress_manager->get_progress_data();
		$status   = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';

		if ( 'completed' === $status || 'idle' === $status ) {
			return;
		}

		$url = admin_url( 'admin.php?page=etch-fusion-suite' );

		if ( 'error' === $status ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>&#9888; %s &ndash; <a href="%s">%s</a></p></div>',
				esc_html__( 'Migration stopped', 'etch-fusion-suite' ),
				esc_url( $url ),
				esc_html__( 'View Details', 'etch-fusion-suite' )
			);
			return;
		}

		if ( in_array( $status, array( 'running', 'queued' ), true ) ) {
			$pct = isset( $progress['percentage'] ) ? (int) $progress['percentage'] : 0;
			printf(
				'<div class="notice notice-info"><p>&#9881; %s</p></div>',
				wp_kses(
					sprintf(
						/* translators: 1: percentage, 2: link to details */
						__( 'Migration in progress (%1$d%%) &ndash; %2$s', 'etch-fusion-suite' ),
						$pct,
						'<a href="' . esc_url( $url ) . '">' . esc_html__( 'View Details', 'etch-fusion-suite' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}
	}
}
