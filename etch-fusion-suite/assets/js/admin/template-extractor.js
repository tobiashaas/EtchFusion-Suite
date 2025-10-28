import { post } from './api.js';
import { showToast } from './ui.js';

const ACTION_EXTRACT_TEMPLATE = 'efs_extract_template';
const ACTION_GET_EXTRACTION_PROGRESS = 'efs_get_extraction_progress';
const ACTION_SAVE_TEMPLATE = 'efs_save_template';
const ACTION_GET_SAVED_TEMPLATES = 'efs_get_saved_templates';
const ACTION_DELETE_TEMPLATE = 'efs_delete_template';

let progressPollTimer = null;
const DEFAULT_PROGRESS_STEPS = ['fetching', 'sanitizing', 'analyzing', 'generating', 'validating', 'completed'];

/**
 * Initiates template extraction from URL or HTML string.
 */
export const extractFromUrl = async (url) => {
	try {
		showProgressStart('fetching');
		startProgressPolling();
		const data = await post(ACTION_EXTRACT_TEMPLATE, {
			source: url,
			source_type: 'url',
		});
		handleExtractionSuccess(data);
		return data;
	} catch (error) {
		showToast(error.message || 'Extraction failed', 'error');
		throw error;
	}
};

/**
 * Extracts template from raw HTML string.
 */
export const extractFromHtml = async (html) => {
	try {
		showProgressStart('fetching');
		startProgressPolling();
		const data = await post(ACTION_EXTRACT_TEMPLATE, {
			source: html,
			source_type: 'html',
		});
		handleExtractionSuccess(data);
		return data;
	} catch (error) {
		showToast(error.message || 'Extraction failed', 'error');
		throw error;
	}
};

/**
 * Polls extraction progress.
 */
const pollExtractionProgress = async () => {
	try {
		const data = await post(ACTION_GET_EXTRACTION_PROGRESS, {});
		updateProgressUI(data);
		
		if (data.status === 'completed') {
			handleExtractionSuccess(data, { fromProgress: true });
		}
	} catch (error) {
		console.error('Progress polling failed:', error);
	}
};

/**
 * Starts progress polling interval.
 */
const startProgressPolling = () => {
	stopProgressPolling();
	progressPollTimer = setInterval(pollExtractionProgress, 2000);
	updateProgressUI({
		progress: 0.1,
		step: 'fetching',
		steps: DEFAULT_PROGRESS_STEPS,
		status: 'fetching',
	});
	pollExtractionProgress();
};

/**
 * Stops progress polling.
 */
const stopProgressPolling = () => {
	if (progressPollTimer) {
		clearInterval(progressPollTimer);
		progressPollTimer = null;
	}
};

/**
 * Updates progress UI elements.
 */
const updateProgressUI = (progressData) => {
	const progressSection = document.querySelector('[data-efs-template-progress]');
	if (!progressSection) return;

	progressSection.classList.remove('is-hidden');

	const progressBar = progressSection.querySelector('[data-efs-progress-bar]');
	const statusText = progressSection.querySelector('[data-efs-status-text]');
	const stepsList = progressSection.querySelector('[data-efs-steps]');

	if (progressBar) {
		const percent = progressData.progress || 0;
		progressBar.style.width = `${percent * 100}%`;
		progressBar.setAttribute('aria-valuenow', percent * 100);
	}

	if (statusText) {
		statusText.textContent = progressData.step || 'Processing...';
	}

	if (stepsList) {
		const steps = progressData.steps || DEFAULT_PROGRESS_STEPS;
		stepsList.innerHTML = steps
			.map((step) => `<li class="${progressData.step === step ? 'active' : ''}">${step}</li>`)
			.join('');
	}
};

const handleExtractionSuccess = (templateData, options = {}) => {
	stopProgressPolling();
	updateProgressUI({
		progress: 1,
		step: 'completed',
		status: 'completed',
		steps: DEFAULT_PROGRESS_STEPS,
	});
	showPreview(templateData);
	if (!options.fromProgress) {
		showToast('Template extraction completed!', 'success');
	}
};

const showProgressStart = (step) => {
	updateProgressUI({
		progress: 0,
		step: step || 'fetching',
		status: step || 'fetching',
		steps: DEFAULT_PROGRESS_STEPS,
	});
};

/**
 * Shows template preview in UI.
 */
const showPreview = (templateData) => {
	const previewSection = document.querySelector('[data-efs-template-preview]');
	if (!previewSection) return;

	previewSection.classList.remove('is-hidden');

	const metadataEl = previewSection.querySelector('[data-efs-template-metadata]');
	if (metadataEl && templateData.metadata) {
		metadataEl.innerHTML = `
			<h3>${templateData.metadata.title || 'Imported Template'}</h3>
			<p>${templateData.metadata.description || ''}</p>
			<div class="template-stats">
				<span>Complexity: ${templateData.metadata.complexity_score || 0}/100</span>
				<span>Sections: ${templateData.metadata.section_overview?.length || 0}</span>
			</div>
		`;
	}

	const blocksPreview = previewSection.querySelector('[data-efs-blocks-preview]');
	if (blocksPreview && templateData.blocks) {
		const previewBlocks = templateData.blocks.slice(0, 3);
		blocksPreview.innerHTML = previewBlocks
			.map((block) => `<div class="block-preview">${escapeHtml(block.substring(0, 200))}...</div>`)
			.join('');
	}

	// Store template data for save action
	previewSection.dataset.templateData = JSON.stringify(templateData);
};

/**
 * Saves extracted template.
 */
export const saveTemplate = async (templateData, name) => {
	try {
		const data = await post(ACTION_SAVE_TEMPLATE, {
			template_data: JSON.stringify(templateData),
			template_name: name,
		});
		showToast('Template saved successfully!', 'success');
		await loadSavedTemplates();
		return data;
	} catch (error) {
		showToast(error.message || 'Failed to save template', 'error');
		throw error;
	}
};

/**
 * Loads saved templates list.
 */
export const loadSavedTemplates = async () => {
	try {
		const data = await post(ACTION_GET_SAVED_TEMPLATES, {});
		renderSavedTemplates(data);
		return data;
	} catch (error) {
		console.error('Failed to load saved templates:', error);
		return [];
	}
};

/**
 * Renders saved templates in UI.
 */
const renderSavedTemplates = (templates) => {
	const listContainer = document.querySelector('[data-efs-saved-templates]');
	if (!listContainer) return;

	if (!templates || templates.length === 0) {
		listContainer.innerHTML = '<p class="efs-empty-state">No saved templates yet.</p>';
		return;
	}

	listContainer.innerHTML = templates
		.map(
			(template) => `
		<div class="efs-saved-template" data-efs-template-id="${template.id}">
			<header class="efs-saved-template__header">
				<h4 class="efs-saved-template__title">${escapeHtml(template.title)}</h4>
				<p class="efs-saved-template__date">${template.created_at}</p>
			</header>
			<div class="efs-saved-template__actions">
				<button class="button button-secondary" data-efs-template-action="preview" data-efs-template-id="${template.id}">Preview</button>
				<button class="button" data-efs-template-action="delete" data-efs-template-id="${template.id}">Delete</button>
			</div>
		</div>
	`
		)
		.join('');

	// Bind delete actions
	listContainer.querySelectorAll('[data-efs-template-action="delete"]').forEach((btn) => {
		btn.addEventListener('click', () => deleteTemplate(parseInt(btn.dataset.efsTemplateId, 10)));
	});
};

/**
 * Deletes a saved template.
 */
export const deleteTemplate = async (templateId) => {
	if (!confirm('Are you sure you want to delete this template?')) {
		return;
	}

	try {
		await post(ACTION_DELETE_TEMPLATE, { template_id: templateId });
		showToast('Template deleted successfully', 'success');
		await loadSavedTemplates();
	} catch (error) {
		showToast(error.message || 'Failed to delete template', 'error');
	}
};

/**
 * Escapes HTML for safe rendering.
 */
const escapeHtml = (text) => {
	const div = document.createElement('div');
	div.textContent = text;
	return div.innerHTML;
};

/**
 * Initializes template extractor UI bindings.
 */
const bindTabs = (container) => {
	const tabs = container.querySelectorAll('[data-efs-tab]');
	const contents = container.querySelectorAll('[data-efs-tab-content]');

	tabs.forEach((tab) => {
		tab.addEventListener('click', () => {
			const target = tab.dataset.efsTab;

			tabs.forEach((button) => {
				button.classList.toggle('is-active', button === tab);
				button.setAttribute('aria-selected', button === tab ? 'true' : 'false');
			});

			contents.forEach((panel) => {
				const match = panel.dataset.efsTabContent === target;
				panel.classList.toggle('is-active', match);
				if (match) {
					panel.removeAttribute('hidden');
				} else {
					panel.setAttribute('hidden', '');
				}
			});
		});
	});
};

export const init = () => {
	const extractorSection = document.querySelector('[data-efs-template-extractor]');
	if (!extractorSection) return;

	bindTabs(extractorSection);

	// Bind URL extraction form
	const urlForm = extractorSection.querySelector('[data-efs-extract-url-form]');
	if (urlForm) {
		urlForm.addEventListener('submit', async (e) => {
			e.preventDefault();
			const urlInput = urlForm.querySelector('[name="framer_url"]');
			if (urlInput && urlInput.value) {
				await extractFromUrl(urlInput.value);
			}
		});
	}

	// Bind HTML extraction form
	const htmlForm = extractorSection.querySelector('[data-efs-extract-html-form]');
	if (htmlForm) {
		htmlForm.addEventListener('submit', async (e) => {
			e.preventDefault();
			const htmlInput = htmlForm.querySelector('[name="framer_html"]');
			if (htmlInput && htmlInput.value) {
				await extractFromHtml(htmlInput.value);
			}
		});
	}

	// Bind save button
	const saveBtn = extractorSection.querySelector('[data-efs-save-template]');
	if (saveBtn) {
		saveBtn.addEventListener('click', async () => {
			const previewSection = document.querySelector('[data-efs-template-preview]');
			const templateData = previewSection?.dataset.templateData;
			const nameInput = extractorSection.querySelector('[name="template_name"]');

			if (templateData && nameInput) {
				await saveTemplate(JSON.parse(templateData), nameInput.value || 'Imported Template');
			}
		});
	}

	// Load saved templates on init
	loadSavedTemplates();
};
