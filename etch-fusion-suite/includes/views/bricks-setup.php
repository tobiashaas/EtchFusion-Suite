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
		<div class="efs-wizard-progress-chip-container" data-efs-progress-chip-container></div>
	</header>

	<div class="efs-preflight" data-efs-preflight aria-live="polite">
		<div class="efs-preflight__loading" data-efs-preflight-loading hidden>
			<span class="efs-wizard-loading__spinner" aria-hidden="true"></span>
			<span><?php esc_html_e( 'Running environment checks…', 'etch-fusion-suite' ); ?></span>
		</div>
		<div class="efs-preflight__results" data-efs-preflight-results hidden></div>
		<div class="efs-preflight__actions" data-efs-preflight-actions hidden>
			<label class="efs-preflight__override" data-efs-preflight-override hidden>
				<input type="checkbox" data-efs-preflight-confirm />
				<span><?php esc_html_e( 'I understand the risks and want to proceed anyway', 'etch-fusion-suite' ); ?></span>
			</label>
			<button type="button" class="button button-secondary efs-preflight__recheck" data-efs-preflight-recheck>
				<?php esc_html_e( '↺ Re-check', 'etch-fusion-suite' ); ?>
			</button>
		</div>
	</div>

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
		<section class="is-active" data-efs-step-panel="1">
			<h3><?php esc_html_e( 'Connect to Etch Site', 'etch-fusion-suite' ); ?></h3>
			<p class="efs-wizard-panel__desc"><?php esc_html_e( 'Paste the connection URL from your Etch target site. You can generate it there via "Generate Connection URL" — the migration key will be created automatically in the background.', 'etch-fusion-suite' ); ?></p>
			<div class="efs-wizard-connect-key">
				<textarea
					id="efs-wizard-migration-url"
					rows="3"
					data-efs-wizard-url
					aria-label="<?php esc_attr_e( 'Connection URL from Etch site', 'etch-fusion-suite' ); ?>"
					placeholder="<?php esc_attr_e( 'https://your-etch-site.com/?_efs_pair=...', 'etch-fusion-suite' ); ?>"
				><?php echo esc_textarea( $etch_fusion_suite_url ); ?></textarea>
				<div class="efs-actions efs-actions--inline efs-wizard-connect-key__actions">
					<button type="button" class="button" data-efs-paste-migration-url aria-label="<?php esc_attr_e( 'Paste connection URL from clipboard', 'etch-fusion-suite' ); ?>">
						<?php esc_html_e( 'Paste URL', 'etch-fusion-suite' ); ?>
					</button>
				</div>
			</div>
			<input type="hidden" data-efs-wizard-migration-key value="<?php echo esc_attr( $etch_fusion_suite_key ); ?>" />
			<p class="efs-wizard-message" data-efs-connect-message hidden></p>
		</section>

		<section class="efs-wizard-panel" data-efs-step-panel="2" hidden>
			<h3><?php esc_html_e( 'Select & Map Content', 'etch-fusion-suite' ); ?></h3>
			<p><?php esc_html_e( 'Discovery runs automatically when this step opens. Choose post types and map each one to a target post type.', 'etch-fusion-suite' ); ?></p>

			<div class="efs-wizard-loading" data-efs-discovery-loading hidden>
				<span class="efs-wizard-loading__spinner" aria-hidden="true"></span>
				<span class="efs-wizard-loading__text"><?php esc_html_e( 'Discovering content...', 'etch-fusion-suite' ); ?></span>
			</div>

			<div class="efs-wizard-summary" data-efs-discovery-summary hidden>
				<div class="efs-wizard-summary__content">
					<h4><?php esc_html_e( 'Dynamic Data Summary', 'etch-fusion-suite' ); ?></h4>
					<p class="efs-wizard-summary__grade" data-efs-summary-grade></p>
					<div class="efs-wizard-summary__breakdown" data-efs-summary-breakdown></div>
				</div>
				<div class="efs-wizard-summary__actions">
					<button type="button" class="button button-secondary" data-efs-run-full-analysis>
						<?php esc_html_e( 'Run Full Analysis', 'etch-fusion-suite' ); ?>
					</button>
				</div>
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
			<label class="efs-media-toggle">
				<input type="checkbox" data-efs-restrict-css checked />
				<span><?php esc_html_e( 'Only migrate used CSS classes (recommended)', 'etch-fusion-suite' ); ?></span>
			</label>
			<p class="efs-wizard-hint">
				<?php esc_html_e( 'Scans selected posts + Bricks Templates (Header, Footer, Global Sections) to find referenced classes only. Uncheck to migrate ALL Bricks Global Classes.', 'etch-fusion-suite' ); ?>
			</p>

			<div class="efs-execution-mode" data-efs-execution-mode>
				<p class="efs-wizard-section-label">
					<?php esc_html_e( 'Execution Mode', 'etch-fusion-suite' ); ?>
				</p>
				<label class="efs-mode-option efs-mode-option--selected" data-efs-mode-option="browser">
					<input type="radio" name="efs-execution-mode" value="browser" checked
						data-efs-mode-radio />
					<span class="efs-mode-title">&#x1F5A5; <?php esc_html_e( 'Browser Mode', 'etch-fusion-suite' ); ?></span>
					<span class="efs-mode-hint">
						<?php esc_html_e( 'Browser must stay open. Best for small migrations.', 'etch-fusion-suite' ); ?>
					</span>
				</label>
				<label class="efs-mode-option" data-efs-mode-option="headless">
					<input type="radio" name="efs-execution-mode" value="headless"
						data-efs-mode-radio />
					<span class="efs-mode-title">&#9881; <?php esc_html_e( 'Headless Mode', 'etch-fusion-suite' ); ?></span>
					<span class="efs-mode-hint">
						<?php esc_html_e( 'Runs server-side. Browser can be closed.', 'etch-fusion-suite' ); ?>
					</span>
					<span class="efs-cron-indicator" data-efs-cron-indicator hidden></span>
				</label>
			</div>

			<p class="efs-wizard-message" data-efs-select-message hidden></p>
		</section>

		<section data-efs-step-panel="3" hidden>
			<h3><?php esc_html_e( 'Preview Migration', 'etch-fusion-suite' ); ?></h3>
			<p><?php esc_html_e( 'Review your migration breakdown and warnings before starting.', 'etch-fusion-suite' ); ?></p>
			<div class="efs-wizard-preview" data-efs-preview-breakdown></div>
			<div class="efs-wizard-css-preview" data-efs-css-preview hidden></div>
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
			<button type="button" class="efs-btn--primary" data-efs-wizard-next><?php esc_html_e( 'Next', 'etch-fusion-suite' ); ?></button>
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
			<p class="efs-wizard-progress__elapsed" data-efs-wizard-elapsed hidden></p>
			<ol class="efs-steps" data-efs-wizard-step-status></ol>
			<div class="efs-actions efs-actions--inline">
				<button type="button" class="button" data-efs-retry-migration hidden><?php esc_html_e( 'Retry Migration', 'etch-fusion-suite' ); ?></button>
				<button type="button" class="button" data-efs-progress-cancel><?php esc_html_e( 'Cancel', 'etch-fusion-suite' ); ?></button>
			</div>
		</div>
		<div class="efs-wizard-result" data-efs-wizard-result hidden>
			<span class="efs-wizard-result__icon" data-efs-result-icon aria-hidden="true"></span>
			<h3 class="efs-wizard-result__title" data-efs-result-title><?php esc_html_e( 'Migration complete', 'etch-fusion-suite' ); ?></h3>
			<p data-efs-result-subtitle><?php esc_html_e( 'The migration has finished.', 'etch-fusion-suite' ); ?></p>
			<div class="efs-actions efs-actions--inline">
				<button type="button" class="efs-btn--primary" data-efs-start-new><?php esc_html_e( 'Finish', 'etch-fusion-suite' ); ?></button>
				<button type="button" class="button" data-efs-open-logs><?php esc_html_e( 'View logs', 'etch-fusion-suite' ); ?></button>
			</div>
		</div>
		<div class="efs-headless-screen" data-efs-headless-screen hidden>
			<h3><?php esc_html_e( 'Headless Migration Running', 'etch-fusion-suite' ); ?></h3>
			<span class="efs-badge">&#9881; <?php esc_html_e( 'Server-Side via Action Scheduler', 'etch-fusion-suite' ); ?></span>
			<p><?php esc_html_e( 'The migration is running in the background. You can safely close this browser tab.', 'etch-fusion-suite' ); ?></p>
			<div class="efs-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="efs-progress-fill" data-efs-headless-progress-fill style="width:0%"></div>
			</div>
			<p class="efs-wizard-progress__percent" data-efs-headless-progress-percent>0%</p>
			<div class="efs-actions efs-actions--inline">
				<button type="button" class="button" data-efs-view-logs>
					<?php esc_html_e( 'View Logs', 'etch-fusion-suite' ); ?>
				</button>
				<button type="button" class="button" data-efs-cancel-headless>
					<?php esc_html_e( 'Cancel Migration', 'etch-fusion-suite' ); ?>
				</button>
			</div>
		</div>
	</div>

	<div class="efs-wizard-banner" data-efs-progress-banner hidden>
		<span data-efs-banner-text><?php esc_html_e( 'Migration in progress: 0%', 'etch-fusion-suite' ); ?></span>
		<button type="button" class="button button-secondary" data-efs-expand-progress><?php esc_html_e( 'Expand', 'etch-fusion-suite' ); ?></button>
	</div>

	<div class="efs-etch-environment efs-card__section">
		<h3 class="efs-etch-environment__title"><?php esc_html_e( 'System Status', 'etch-fusion-suite' ); ?></h3>
		<dl class="efs-etch-environment__list">
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'Bricks Builder', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value"><?php echo isset( $is_bricks_site ) && $is_bricks_site ? esc_html__( 'Detected', 'etch-fusion-suite' ) : esc_html__( 'Not detected', 'etch-fusion-suite' ); ?></dd>
			</div>
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'Etch PageBuilder', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value"><?php echo isset( $is_etch_site ) && $is_etch_site ? esc_html__( 'Detected', 'etch-fusion-suite' ) : esc_html__( 'Not detected', 'etch-fusion-suite' ); ?></dd>
			</div>
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'WordPress Version', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value"><?php echo isset( $wp_version ) ? esc_html( $wp_version ) : ''; ?></dd>
			</div>
			<div class="efs-etch-environment__row">
				<dt class="efs-etch-environment__label"><?php esc_html_e( 'PHP Version', 'etch-fusion-suite' ); ?></dt>
				<dd class="efs-etch-environment__value"><?php echo isset( $php_version ) ? esc_html( $php_version ) : ''; ?></dd>
			</div>
		</dl>
	</div>
</section>
