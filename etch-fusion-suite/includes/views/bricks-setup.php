<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etch_fusion_suite_settings = is_array( $settings ) ? $settings : array();
$etch_fusion_suite_nonce    = is_string( $nonce ) ? $nonce : '';
$etch_fusion_suite_url      = isset( $etch_fusion_suite_settings['target_url'] ) ? (string) $etch_fusion_suite_settings['target_url'] : '';
$etch_fusion_suite_key      = isset( $etch_fusion_suite_settings['migration_key'] ) ? (string) $etch_fusion_suite_settings['migration_key'] : '';
?>
<section
	class="efs-card efs-card--source efs-bricks-wizard"
	data-efs-bricks-wizard
	data-efs-state-nonce="<?php echo esc_attr( $etch_fusion_suite_nonce ); ?>"
>
	<header class="efs-card__header">
		<h2><?php esc_html_e( 'Bricks Migration Wizard', 'etch-fusion-suite' ); ?></h2>
		<p><?php esc_html_e( 'Connect to your Etch site, choose what to migrate, preview the plan, and run migration.', 'etch-fusion-suite' ); ?></p>
	</header>

	<nav class="efs-wizard-steps" aria-label="<?php esc_attr_e( 'Migration steps', 'etch-fusion-suite' ); ?>">
		<button type="button" class="efs-wizard-step is-active" data-efs-step-nav="1" aria-current="step">
			<span class="efs-wizard-step__number">1</span>
			<span class="efs-wizard-step__label"><?php esc_html_e( 'Connect', 'etch-fusion-suite' ); ?></span>
		</button>
		<button type="button" class="efs-wizard-step" data-efs-step-nav="2">
			<span class="efs-wizard-step__number">2</span>
			<span class="efs-wizard-step__label"><?php esc_html_e( 'Select & Map', 'etch-fusion-suite' ); ?></span>
		</button>
		<button type="button" class="efs-wizard-step" data-efs-step-nav="3">
			<span class="efs-wizard-step__number">3</span>
			<span class="efs-wizard-step__label"><?php esc_html_e( 'Preview', 'etch-fusion-suite' ); ?></span>
		</button>
		<button type="button" class="efs-wizard-step" data-efs-step-nav="4">
			<span class="efs-wizard-step__number">4</span>
			<span class="efs-wizard-step__label"><?php esc_html_e( 'Migrate', 'etch-fusion-suite' ); ?></span>
		</button>
	</nav>

	<div class="efs-wizard-panels">
		<section class="efs-wizard-panel is-active" data-efs-step-panel="1">
			<h3><?php esc_html_e( 'Connect to Etch Site', 'etch-fusion-suite' ); ?></h3>
			<p><?php esc_html_e( 'Paste the migration URL from your Etch site. We will validate the URL and token before you continue.', 'etch-fusion-suite' ); ?></p>
			<div class="efs-wizard-migration-url-block efs-field">
				<h4 class="efs-wizard-migration-url-block__title"><?php esc_html_e( 'Migration URL', 'etch-fusion-suite' ); ?></h4>
				<label for="efs-wizard-migration-url" class="efs-wizard-migration-url-block__desc"><?php esc_html_e( 'Paste the URL from your Etch target site here.', 'etch-fusion-suite' ); ?></label>
				<textarea
					id="efs-wizard-migration-url"
					rows="8"
					data-efs-wizard-url
					aria-label="<?php esc_attr_e( 'Migration URL from Etch site', 'etch-fusion-suite' ); ?>"
				><?php echo esc_textarea( $etch_fusion_suite_url ); ?></textarea>
			</div>
			<input type="hidden" data-efs-wizard-migration-key value="<?php echo esc_attr( $etch_fusion_suite_key ); ?>" />
			<p class="efs-wizard-message" data-efs-connect-message hidden></p>
		</section>

		<section class="efs-wizard-panel" data-efs-step-panel="2" hidden>
			<h3><?php esc_html_e( 'Select & Map Content', 'etch-fusion-suite' ); ?></h3>
			<p><?php esc_html_e( 'Discovery runs automatically when this step opens. Choose post types and map each one to a target post type.', 'etch-fusion-suite' ); ?></p>

			<div class="efs-wizard-loading" data-efs-discovery-loading hidden>
				<span class="status-indicator running" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Discovering content...', 'etch-fusion-suite' ); ?></span>
			</div>

			<div class="efs-wizard-summary" data-efs-discovery-summary hidden>
				<div class="efs-wizard-summary__header">
					<h4><?php esc_html_e( 'Dynamic Data Summary', 'etch-fusion-suite' ); ?></h4>
					<button type="button" class="button button-secondary" data-efs-run-full-analysis>
						<?php esc_html_e( 'Run Full Analysis', 'etch-fusion-suite' ); ?>
					</button>
				</div>
				<p class="efs-wizard-summary__grade" data-efs-summary-grade></p>
				<details>
					<summary><?php esc_html_e( 'View Breakdown', 'etch-fusion-suite' ); ?></summary>
					<ul data-efs-summary-breakdown></ul>
				</details>
			</div>

			<div class="efs-actions efs-actions--inline">
				<button type="button" class="button" data-efs-preset="all"><?php esc_html_e( 'Select All', 'etch-fusion-suite' ); ?></button>
				<button type="button" class="button" data-efs-preset="posts"><?php esc_html_e( 'Posts Only', 'etch-fusion-suite' ); ?></button>
				<button type="button" class="button" data-efs-preset="cpts"><?php esc_html_e( 'CPTs Only', 'etch-fusion-suite' ); ?></button>
				<button type="button" class="button" data-efs-preset="none"><?php esc_html_e( 'Clear All', 'etch-fusion-suite' ); ?></button>
			</div>

			<div class="efs-wizard-table-wrap">
				<table class="widefat striped efs-wizard-table">
					<thead>
						<tr>
							<th class="efs-col-check"><?php esc_html_e( 'Select', 'etch-fusion-suite' ); ?></th>
							<th><?php esc_html_e( 'Bricks Post Type', 'etch-fusion-suite' ); ?></th>
							<th><?php esc_html_e( 'Count', 'etch-fusion-suite' ); ?></th>
							<th><?php esc_html_e( 'Custom Fields', 'etch-fusion-suite' ); ?></th>
							<th><?php esc_html_e( 'Map to Etch Post Type', 'etch-fusion-suite' ); ?></th>
						</tr>
					</thead>
					<tbody data-efs-post-type-rows>
						<tr>
							<td colspan="5"><?php esc_html_e( 'Discovery has not started yet.', 'etch-fusion-suite' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<label class="efs-media-toggle">
				<input type="checkbox" data-efs-include-media checked />
				<span><?php esc_html_e( 'Include media migration', 'etch-fusion-suite' ); ?></span>
			</label>
			<p class="efs-wizard-message" data-efs-select-message hidden></p>
		</section>

		<section class="efs-wizard-panel" data-efs-step-panel="3" hidden>
			<h3><?php esc_html_e( 'Preview Migration', 'etch-fusion-suite' ); ?></h3>
			<p><?php esc_html_e( 'Review your migration breakdown and warnings before starting.', 'etch-fusion-suite' ); ?></p>
			<div class="efs-wizard-preview" data-efs-preview-breakdown></div>
			<section class="efs-wizard-warnings" data-efs-preview-warnings hidden>
				<h4><?php esc_html_e( 'Conversion Warnings', 'etch-fusion-suite' ); ?></h4>
				<ul data-efs-warning-list></ul>
			</section>
		</section>

		<section class="efs-wizard-panel" data-efs-step-panel="4" hidden>
			<h3><?php esc_html_e( 'Migration', 'etch-fusion-suite' ); ?></h3>
			<p><?php esc_html_e( 'Migration is running. Keep this screen open or use minimize to monitor in compact mode.', 'etch-fusion-suite' ); ?></p>
		</section>
	</div>

	<footer class="efs-wizard-footer">
		<div class="efs-actions efs-actions--inline">
			<button type="button" class="button" data-efs-wizard-back><?php esc_html_e( 'Back', 'etch-fusion-suite' ); ?></button>
			<button type="button" class="button button-primary" data-efs-wizard-next><?php esc_html_e( 'Next', 'etch-fusion-suite' ); ?></button>
			<button type="button" class="button" data-efs-wizard-cancel><?php esc_html_e( 'Cancel', 'etch-fusion-suite' ); ?></button>
		</div>
	</footer>

	<div class="efs-wizard-progress" data-efs-progress-takeover hidden>
		<button type="button" class="button efs-wizard-progress__minimize" data-efs-minimize-progress>
			<?php esc_html_e( 'Minimize', 'etch-fusion-suite' ); ?>
		</button>
		<div class="efs-wizard-progress__panel">
			<h3><?php esc_html_e( 'Migration in Progress', 'etch-fusion-suite' ); ?></h3>
			<div class="efs-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="efs-progress-fill" data-efs-wizard-progress-fill style="width:0%"></div>
			</div>
			<p class="efs-wizard-progress__percent" data-efs-wizard-progress-percent>0%</p>
			<p class="efs-wizard-progress__status" data-efs-wizard-progress-status><?php esc_html_e( 'Preparing migration...', 'etch-fusion-suite' ); ?></p>
			<p class="efs-wizard-progress__items" data-efs-wizard-items></p>
			<ol class="efs-steps" data-efs-wizard-step-status></ol>
			<div class="efs-actions efs-actions--inline">
				<button type="button" class="button" data-efs-retry-migration hidden><?php esc_html_e( 'Retry Migration', 'etch-fusion-suite' ); ?></button>
				<button type="button" class="button" data-efs-progress-cancel><?php esc_html_e( 'Cancel', 'etch-fusion-suite' ); ?></button>
			</div>
		</div>
	</div>

	<div class="efs-wizard-banner" data-efs-progress-banner hidden>
		<span data-efs-banner-text><?php esc_html_e( 'Migration in progress: 0%', 'etch-fusion-suite' ); ?></span>
		<button type="button" class="button button-secondary" data-efs-expand-progress><?php esc_html_e( 'Expand', 'etch-fusion-suite' ); ?></button>
	</div>
</section>
