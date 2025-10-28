<?php
/**
 * Template Extractor View
 *
 * UI for importing Framer templates into Etch.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etch_fusion_suite_template_context = array(
	'nonce'           => isset( $nonce ) ? (string) $nonce : wp_create_nonce( 'efs_nonce' ),
	'saved_templates' => isset( $saved_templates ) && is_array( $saved_templates ) ? $saved_templates : array(),
);

$etch_fusion_suite_render_template_extractor = static function ( array $context ) {
	$nonce           = sanitize_text_field( $context['nonce'] );
	$saved_templates = $context['saved_templates']; // Reserved for future use, kept local to avoid global scope.
	?>
	<div class="efs-template-extractor" data-efs-template-extractor>
		<header class="efs-template-extractor__header">
			<h2><?php esc_html_e( 'Framer Template Extractor', 'etch-fusion-suite' ); ?></h2>
			<p><?php esc_html_e( 'Import Framer templates directly into Etch. Extract from a live Framer URL or paste HTML code.', 'etch-fusion-suite' ); ?></p>
		</header>

		<section class="efs-card efs-card--input">
			<div class="efs-tabs" role="tablist">
				<button type="button" class="efs-tab is-active" data-efs-tab="url" aria-selected="true">
					<?php esc_html_e( 'From URL', 'etch-fusion-suite' ); ?>
				</button>
				<button type="button" class="efs-tab" data-efs-tab="html" aria-selected="false">
					<?php esc_html_e( 'From HTML', 'etch-fusion-suite' ); ?>
				</button>
			</div>

			<div class="efs-tab-content is-active" data-efs-tab-content="url" role="tabpanel">
				<form data-efs-extract-url-form>
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<div class="efs-form-group">
						<label for="framer_url"><?php esc_html_e( 'Framer Website URL', 'etch-fusion-suite' ); ?></label>
						<input
							type="url"
							id="framer_url"
							name="framer_url"
							class="efs-input"
							placeholder="https://example.framer.website/"
							required
						>
						<p class="efs-help-text"><?php esc_html_e( 'Enter the full URL of your published Framer website.', 'etch-fusion-suite' ); ?></p>
					</div>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Extract Template', 'etch-fusion-suite' ); ?>
					</button>
				</form>
			</div>

			<div class="efs-tab-content" data-efs-tab-content="html" role="tabpanel" hidden>
				<form data-efs-extract-html-form>
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<div class="efs-form-group">
						<label for="framer_html"><?php esc_html_e( 'Framer HTML Code', 'etch-fusion-suite' ); ?></label>
						<textarea
							id="framer_html"
							name="framer_html"
							class="efs-textarea"
							rows="10"
							placeholder="<html>...</html>"
							required
						></textarea>
						<p class="efs-help-text"><?php esc_html_e( 'Paste the complete HTML source code from your Framer page.', 'etch-fusion-suite' ); ?></p>
					</div>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Extract from HTML', 'etch-fusion-suite' ); ?>
					</button>
				</form>
			</div>
		</section>

		<section class="efs-card efs-card--progress is-hidden" data-efs-template-progress>
			<h3><?php esc_html_e( 'Extraction Progress', 'etch-fusion-suite' ); ?></h3>
			<div class="efs-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<span class="efs-progress__fill" data-efs-progress-bar></span>
			</div>
			<p class="efs-status-text" data-efs-status-text><?php esc_html_e( 'Starting extraction...', 'etch-fusion-suite' ); ?></p>
			<ol class="efs-steps-list" data-efs-steps></ol>
		</section>

		<section class="efs-card efs-card--preview is-hidden" data-efs-template-preview>
			<h3><?php esc_html_e( 'Template Preview', 'etch-fusion-suite' ); ?></h3>
			<div class="efs-template-metadata" data-efs-template-metadata></div>
			<div class="efs-blocks-preview" data-efs-blocks-preview></div>
			<div class="efs-form-group">
				<label for="template_name"><?php esc_html_e( 'Template Name', 'etch-fusion-suite' ); ?></label>
				<input
					type="text"
					id="template_name"
					name="template_name"
					class="efs-input"
					placeholder="<?php esc_attr_e( 'My Framer Template', 'etch-fusion-suite' ); ?>"
				>
			</div>
			<button type="button" class="button button-primary" data-efs-save-template>
				<?php esc_html_e( 'Save Template', 'etch-fusion-suite' ); ?>
			</button>
		</section>

		<section class="efs-card efs-card--saved">
			<div class="efs-card__header">
				<h3><?php esc_html_e( 'Saved Templates', 'etch-fusion-suite' ); ?></h3>
			</div>
			<div class="efs-saved-templates" data-efs-saved-templates>
				<p class="efs-loading-state"><?php esc_html_e( 'Loading templates...', 'etch-fusion-suite' ); ?></p>
			</div>
		</section>
	</div>
	<?php
};

$etch_fusion_suite_render_template_extractor( $etch_fusion_suite_template_context );
