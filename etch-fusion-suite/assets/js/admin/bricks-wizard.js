import { post } from './api.js';
import { showToast } from './ui.js';
import { perfMetrics } from './utilities/perf-metrics.js';
import { updateTabTitle, resetTabTitle } from './utilities/tab-title.js';
import { createProgressChip, updateProgressChip, removeProgressChip } from './utilities/progress-chip.js';
import { formatElapsed, formatEta } from './utilities/time-format.js';

const ACTION_VALIDATE_URL = 'efs_wizard_validate_url';
const ACTION_VALIDATE_TOKEN = 'efs_validate_migration_token';
const ACTION_SAVE_STATE = 'efs_wizard_save_state';
const ACTION_GET_STATE = 'efs_wizard_get_state';
const ACTION_CLEAR_STATE = 'efs_wizard_clear_state';
const ACTION_DISCOVER_CONTENT = 'efs_get_bricks_posts';
const ACTION_GET_TARGET_POST_TYPES = 'efs_get_target_post_types';
const ACTION_START_MIGRATION = 'efs_start_migration';
const ACTION_GET_PROGRESS = 'efs_get_migration_progress';
const ACTION_CANCEL_MIGRATION = 'efs_cancel_migration';
const ACTION_MIGRATE_BATCH = 'efs_migrate_batch';
const ACTION_RESUME_MIGRATION = 'efs_resume_migration';
const ACTION_GET_CSS_PREVIEW = 'efs_get_css_preview';
const ACTION_RUN_PREFLIGHT = 'efs_run_preflight_check';
const ACTION_INVALIDATE_PREFLIGHT = 'efs_invalidate_preflight_cache';

const BATCH_SIZE = 10;

const POLL_INTERVAL_MS = 3000;
const STEP_COUNT = 4;
const DISCOVERY_PLACEHOLDER_ROW = '<tr><td colspan="5">Discovery has not started yet.</td></tr>';

const defaultState = () => ({
	currentStep: 1,
	migrationUrl: '',
	migrationKey: '',
	targetUrl: '',
	discoveryData: null,
	/** @type {{ slug: string, label: string }[]} Post types from Etch (target) site for mapping dropdown. */
	targetPostTypes: [],
	selectedPostTypes: [],
	postTypeMappings: {},
	includeMedia: true,
	restrictCssToUsed: true,
	batchSize: 50,
	migrationId: '',
	progressMinimized: false,
	preflight: null,
	preflightConfirmed: false,
});

const humanize = (value) => String(value || '')
	.replace(/[_-]+/g, ' ')
	.replace(/\b\w/g, (char) => char.toUpperCase())
	.trim();

const parseMigrationUrl = (rawUrl) => {
	if (!rawUrl || typeof rawUrl !== 'string') {
		throw new Error('Migration key is required.');
	}

	let parsed;
	try {
		parsed = new URL(rawUrl.trim());
	} catch (error) {
		throw new Error('Migration key format is invalid.');
	}

	const token = parsed.searchParams.get('token')
		|| parsed.searchParams.get('migration_key')
		|| parsed.searchParams.get('key')
		|| '';

	if (!token) {
		throw new Error('Migration key does not contain a token.');
	}

	return {
		token,
		origin: parsed.origin,
		normalizedUrl: parsed.toString(),
	};
};

const normalizeSteps = (steps) => {
	if (Array.isArray(steps)) {
		return steps;
	}
	if (steps && typeof steps === 'object') {
		return Object.entries(steps).map(([slug, data]) => ({
			slug,
			...(data || {}),
		}));
	}
	return [];
};

const getDiscoveryPostTypes = (discoveryData) => discoveryData?.postTypes || discoveryData?.post_types || [];
const getDiscoverySummary = (discoveryData) => discoveryData?.summary || null;

const createWizard = (root) => {
	const refs = {
		stepNav: Array.from(root.querySelectorAll('[data-efs-step-nav]')),
		stepPanels: Array.from(root.querySelectorAll('[data-efs-step-panel]')),
		backButton: root.querySelector('[data-efs-wizard-back]'),
		nextButton: root.querySelector('[data-efs-wizard-next]'),
		cancelButton: root.querySelector('[data-efs-wizard-cancel]'),
		urlInput: root.querySelector('[data-efs-wizard-url]'),
		pasteButton: root.querySelector('[data-efs-paste-migration-url]'),
		keyInput: root.querySelector('[data-efs-wizard-migration-key]'),
		connectMessage: root.querySelector('[data-efs-connect-message]'),
		selectMessage: root.querySelector('[data-efs-select-message]'),
		discoveryLoading: root.querySelector('[data-efs-discovery-loading]'),
		discoverySummary: root.querySelector('[data-efs-discovery-summary]'),
		summaryGrade: root.querySelector('[data-efs-summary-grade]'),
		summaryBreakdown: root.querySelector('[data-efs-summary-breakdown]'),
		progressChipContainer: root.querySelector('[data-efs-progress-chip-container]'),
		runFullAnalysis: root.querySelector('[data-efs-run-full-analysis]'),
		rowsBody: root.querySelector('[data-efs-post-type-rows]'),
		includeMedia: root.querySelector('[data-efs-include-media]'),
		restrictCssToUsed: root.querySelector('[data-efs-restrict-css]'),
		previewBreakdown: root.querySelector('[data-efs-preview-breakdown]'),
		cssPreview: root.querySelector('[data-efs-css-preview]'),
		previewWarnings: root.querySelector('[data-efs-preview-warnings]'),
		warningList: root.querySelector('[data-efs-warning-list]'),
		progressTakeover: root.querySelector('[data-efs-progress-takeover]'),
		progressPanel: root.querySelector('[data-efs-progress-takeover] .efs-wizard-progress__panel'),
		progressBanner: root.querySelector('[data-efs-progress-banner]'),
		progressFill: root.querySelector('[data-efs-wizard-progress-fill]'),
		progressPercent: root.querySelector('[data-efs-wizard-progress-percent]'),
		progressStatus: root.querySelector('[data-efs-wizard-progress-status]'),
		progressItems: root.querySelector('[data-efs-wizard-items]'),
		progressElapsed: root.querySelector('[data-efs-wizard-elapsed]'),
		progressSteps: root.querySelector('[data-efs-wizard-step-status]'),
		retryButton: root.querySelector('[data-efs-retry-migration]'),
		progressCancelButton: root.querySelector('[data-efs-progress-cancel]'),
		minimizeButton: root.querySelector('[data-efs-minimize-progress]'),
		expandButton: root.querySelector('[data-efs-expand-progress]'),
		bannerText: root.querySelector('[data-efs-banner-text]'),
		result: root.querySelector('[data-efs-wizard-result]'),
		resultIcon: root.querySelector('[data-efs-result-icon]'),
		resultTitle: root.querySelector('[data-efs-result-title]'),
		resultSubtitle: root.querySelector('[data-efs-result-subtitle]'),
		openLogsButton: root.querySelector('[data-efs-open-logs]'),
		startNewButton: root.querySelector('[data-efs-start-new]'),
		presets: Array.from(root.querySelectorAll('[data-efs-preset]')),
		preflightContainer: root.querySelector('[data-efs-preflight]'),
		preflightLoading: root.querySelector('[data-efs-preflight-loading]'),
		preflightResults: root.querySelector('[data-efs-preflight-results]'),
		preflightActions: root.querySelector('[data-efs-preflight-actions]'),
		preflightOverride: root.querySelector('[data-efs-preflight-override]'),
		preflightConfirm: root.querySelector('[data-efs-preflight-confirm]'),
		preflightRecheck: root.querySelector('[data-efs-preflight-recheck]'),
	};

	const wizardNonce = root.getAttribute('data-efs-state-nonce') || window.efsData?.nonce || '';

	const state = defaultState();
	state.migrationUrl = refs.urlInput?.value?.trim() || '';
	state.migrationKey = refs.keyInput?.value?.trim() || '';

	const runtime = {
		discoveryLoaded: false,
		validatedMigrationUrl: '',
		validatingConnect: false,
		pollingTimer: null,
		migrationRunning: false,
		progressChip: null,
		lastProgressPercentage: 0,
		lastProcessedCount: undefined,
		lastPollTime: undefined,
	};

	const setMessage = (el, message, level = 'info') => {
		if (!el) {
			return;
		}

		if (!message) {
			el.hidden = true;
			el.textContent = '';
			el.classList.remove('is-error', 'is-success', 'is-warning');
			return;
		}

		el.hidden = false;
		el.textContent = message;
		el.classList.remove('is-error', 'is-success', 'is-warning');
		if (level === 'error') {
			el.classList.add('is-error');
		} else if (level === 'success') {
			el.classList.add('is-success');
		} else if (level === 'warning') {
			el.classList.add('is-warning');
		}
	};

	const PREFLIGHT_HINTS = {
		memory: 'PHP memory_limit is below 64 MB. Contact your hosting provider to increase it.',
		target_reachable: 'The target site is not reachable. Check the URL and ensure the site is online.',
		wp_cron: 'WP Cron is disabled (DISABLE_WP_CRON). Switch to Browser Mode or enable WP Cron.',
		memory_warning: 'Memory is limited. Large migrations may fail. Consider increasing memory_limit.',
		execution_time: 'max_execution_time is low. Batch size will be reduced automatically.',
		disk_space: 'Target site has less than 500 MB free disk space.',
	};

	const renderPreflightUI = (result) => {
		if (refs.preflightLoading) { refs.preflightLoading.hidden = true; }
		if (refs.preflightResults) { refs.preflightResults.hidden = false; }
		if (refs.preflightActions) { refs.preflightActions.hidden = false; }

		if (!result || !Array.isArray(result.checks)) {
			if (refs.preflightResults) {
				refs.preflightResults.innerHTML = '<p>Environment check failed &ndash; please try again.</p>';
			}
			updateNavigationState();
			return;
		}

		const rows = result.checks.map((check) => {
			const hint = (check.status === 'error' || check.status === 'warning')
				? (PREFLIGHT_HINTS[check.id] || '')
				: '';
			const hintHtml = hint
				? `<span class="efs-preflight__hint">${hint}</span>`
				: '';
			return `<div class="efs-preflight__row">
				<span class="efs-preflight__badge efs-preflight__badge--${check.status}">${check.status}</span>
				<div class="efs-preflight__row-content">
					<span>${check.message || check.id}</span>
					${hintHtml}
				</div>
			</div>`;
		}).join('');

		const errorCount = result.checks.filter((c) => c.status === 'error').length;
		const warnCount = result.checks.filter((c) => c.status === 'warning').length;

		let barClass = '';
		let barText = 'All checks passed.';
		if (result.has_hard_block) {
			barClass = 'efs-preflight__bar--error';
			barText = `${errorCount} error${errorCount !== 1 ? 's' : ''} &ndash; migration blocked.`;
		} else if (result.has_soft_block) {
			barClass = 'efs-preflight__bar--warn';
			barText = `${warnCount} warning${warnCount !== 1 ? 's' : ''} detected.`;
		}

		const barHtml = `<div class="efs-preflight__bar ${barClass}">${barText}</div>`;

		if (refs.preflightResults) {
			refs.preflightResults.innerHTML = rows + barHtml;
		}

		if (refs.preflightOverride) {
			refs.preflightOverride.hidden = !(result.has_soft_block && !result.has_hard_block);
		}

		updateNavigationState();
	};

	const runPreflightCheck = async (targetUrl = '', mode = 'browser') => {
		if (refs.preflightLoading) { refs.preflightLoading.hidden = false; }
		if (refs.preflightResults) { refs.preflightResults.hidden = true; }
		if (refs.preflightActions) { refs.preflightActions.hidden = true; }

		try {
			const result = await post(ACTION_RUN_PREFLIGHT, { target_url: targetUrl, mode });
			state.preflight = result;
			renderPreflightUI(result);
		} catch (err) {
			if (refs.preflightLoading) { refs.preflightLoading.hidden = true; }
			if (refs.preflightResults) {
				refs.preflightResults.hidden = false;
				refs.preflightResults.innerHTML = '<p>Environment check failed &ndash; please try again.</p>';
			}
			updateNavigationState();
		}
	};

	const invalidateAndRecheck = async () => {
		if (refs.preflightRecheck) { refs.preflightRecheck.disabled = true; }
		try {
			await post(ACTION_INVALIDATE_PREFLIGHT, {});
		} catch (_err) {
			// ignore invalidation errors
		}
		await runPreflightCheck(state.targetUrl || '', state.mode || 'browser');
		if (refs.preflightRecheck) { refs.preflightRecheck.disabled = false; }
	};

	const hasValidStep2Selection = () => {
		if (!state.discoveryData || !getDiscoveryPostTypes(state.discoveryData).length) {
			return false;
		}

		return state.selectedPostTypes.some((slug) => Boolean(state.postTypeMappings[slug]));
	};

	const validatePostTypeMappings = () => {
		const errors = [];
		const availableTargetSlugs = state.targetPostTypes.map((pt) => pt.slug);

		state.selectedPostTypes.forEach((sourceSlug) => {
			const targetSlug = state.postTypeMappings[sourceSlug];

			if (!targetSlug || targetSlug === '') {
				errors.push(`Missing mapping for "${humanize(sourceSlug)}"`);
				return;
			}

			if (!availableTargetSlugs.includes(targetSlug)) {
				errors.push(`Invalid mapping for "${humanize(sourceSlug)}" -> "${humanize(targetSlug)}" (not available on target)`);
			}
		});

		return errors;
	};

	const updateNavigationState = () => {
		if (refs.backButton) {
			refs.backButton.disabled = state.currentStep <= 1 || state.currentStep >= 4;
		}

		if (refs.nextButton) {
			let disabled = false;
			let label = 'Next';

			if (state.currentStep === 1) {
				disabled = !state.migrationUrl || runtime.validatingConnect;
				label = runtime.validatingConnect ? 'Validating...' : 'Next';
				// Pre-flight hard block always disables Next
				if (state.preflight?.has_hard_block) {
					disabled = true;
				}
				// Pre-flight soft block requires confirmation checkbox
				if (state.preflight?.has_soft_block && !state.preflight?.has_hard_block && !state.preflightConfirmed) {
					disabled = true;
				}
			} else if (state.currentStep === 2) {
				disabled = !hasValidStep2Selection();
				label = 'Next';
			} else if (state.currentStep === 3) {
				disabled = runtime.migrationRunning;
				label = runtime.migrationRunning ? 'Starting migration…' : 'Confirm & Start Migration';
			} else {
				disabled = true;
				label = 'Migration Running';
			}

			refs.nextButton.disabled = disabled;
			refs.nextButton.textContent = label;
			refs.nextButton.classList.toggle('efs-wizard-next--validating', state.currentStep === 1 && runtime.validatingConnect);
			refs.nextButton.classList.toggle('efs-wizard-next--loading', state.currentStep === 3 && runtime.migrationRunning);
			refs.nextButton.setAttribute('aria-busy', state.currentStep === 3 && runtime.migrationRunning ? 'true' : 'false');
		}
	};

	const renderStepShell = () => {
		refs.stepNav.forEach((stepButton) => {
			const step = Number(stepButton.getAttribute('data-efs-step-nav') || '1');
			stepButton.classList.toggle('is-active', step === state.currentStep);
			stepButton.classList.toggle('is-complete', step < state.currentStep);
			stepButton.classList.toggle('is-clickable', step < state.currentStep && state.currentStep < 4);
			if (step === state.currentStep) {
				stepButton.setAttribute('aria-current', 'step');
			} else {
				stepButton.removeAttribute('aria-current');
			}
		});

		refs.stepPanels.forEach((panel) => {
			const step = Number(panel.getAttribute('data-efs-step-panel') || '1');
			const active = step === state.currentStep;
			panel.classList.toggle('is-active', active);
			panel.hidden = !active;
		});

		updateNavigationState();
	};

	const saveWizardState = async () => {
		try {
			await post(ACTION_SAVE_STATE, {
				wizard_nonce: wizardNonce,
				state: JSON.stringify({
					current_step: state.currentStep,
					migration_url: state.migrationUrl,
					migration_key: state.migrationKey,
					target_url: state.targetUrl,
					discovery_data: state.discoveryData || {},
					selected_post_types: state.selectedPostTypes,
					post_type_mappings: state.postTypeMappings,
					include_media: state.includeMedia,
					restrict_css_to_used: state.restrictCssToUsed,
					batch_size: state.batchSize,
				}),
			});
		} catch (error) {
			console.warn('[EFS] Failed to persist wizard state', error);
		}
	};

	const clearWizardState = async () => {
		try {
			await post(ACTION_CLEAR_STATE, {
				wizard_nonce: wizardNonce,
			});
		} catch (error) {
			console.warn('[EFS] Failed to clear wizard state', error);
		}
	};

	const renderSummary = () => {
		const summary = getDiscoverySummary(state.discoveryData);
		if (!summary || !refs.discoverySummary || !refs.summaryGrade || !refs.summaryBreakdown) {
			return;
		}

		refs.discoverySummary.hidden = false;
		refs.summaryGrade.textContent = summary.label;
		refs.summaryGrade.classList.remove('is-green', 'is-yellow', 'is-red');
		refs.summaryGrade.classList.add(`is-${summary.grade}`);

		refs.summaryBreakdown.innerHTML = '';
		(summary.breakdown || []).forEach((item) => {
			const entry = document.createElement('div');
			entry.className = 'efs-wizard-summary__item';
			entry.innerHTML = `
				<span class="efs-wizard-summary__label">${item.label}</span>
				<span class="efs-wizard-summary__value">${item.value}</span>
			`;
			refs.summaryBreakdown.appendChild(entry);
		});
	};

	/** Returns Etch (target) post type slugs for the mapping dropdown; fallback if not yet loaded. */
	const getAvailableMappingOptions = () => {
		const list = state.targetPostTypes && state.targetPostTypes.length
			? state.targetPostTypes.map((pt) => pt.slug)
			: ['post', 'page'];
		return Array.from(new Set(list)).filter(Boolean);
	};

	const getTargetPostTypeLabels = () => {
		if (state.targetPostTypes && state.targetPostTypes.length) {
			return Object.fromEntries(state.targetPostTypes.map((pt) => [pt.slug, pt.label]));
		}
		return { post: 'Posts', page: 'Pages' };
	};

	const renderPostTypeTable = () => {
		if (!refs.rowsBody) {
			return;
		}

		const normalizedPostTypes = getDiscoveryPostTypes(state.discoveryData);
		if (!normalizedPostTypes.length) {
			refs.rowsBody.innerHTML = '<tr><td colspan="5">No content found for migration.</td></tr>';
			return;
		}

		const options = getAvailableMappingOptions();
		const labels = getTargetPostTypeLabels();
		const rowsMarkup = normalizedPostTypes.map((postType) => {
			const checked = state.selectedPostTypes.includes(postType.slug);
			const selectedMapping = state.postTypeMappings[postType.slug] || '';
			const optionsMarkup = options.map((slug) => {
				const selected = slug === selectedMapping ? ' selected' : '';
				const label = labels[slug] || humanize(slug);
				return `<option value="${slug}"${selected}>${label}</option>`;
			}).join('');

			const disabled = checked ? '' : ' disabled';
			const checkedAttr = checked ? ' checked' : '';
			const rowStateClass = checked ? 'is-active' : 'is-inactive';

			return `
				<tr class="${rowStateClass}" data-efs-post-type-row="${postType.slug}">
					<td><input type="checkbox" data-efs-post-type-check="${postType.slug}"${checkedAttr}></td>
					<td>${postType.label}</td>
					<td>${postType.count}</td>
					<td>${postType.customFields}</td>
					<td>
						<select data-efs-post-type-map="${postType.slug}"${disabled}>
							<option value="">Select post type...</option>
							${optionsMarkup}
						</select>
					</td>
				</tr>
			`;
		}).join('');

		refs.rowsBody.innerHTML = rowsMarkup;
	};

	const resetDiscoveryUi = () => {
		if (refs.discoveryLoading) {
			refs.discoveryLoading.hidden = true;
		}
		if (refs.discoverySummary) {
			refs.discoverySummary.hidden = true;
		}
		if (refs.summaryGrade) {
			refs.summaryGrade.textContent = '';
			refs.summaryGrade.classList.remove('is-green', 'is-yellow', 'is-red');
		}
		if (refs.summaryBreakdown) {
			refs.summaryBreakdown.innerHTML = '';
		}
		if (refs.rowsBody) {
			refs.rowsBody.innerHTML = DISCOVERY_PLACEHOLDER_ROW;
		}
		if (refs.previewBreakdown) {
			refs.previewBreakdown.innerHTML = '';
		}
		if (refs.previewWarnings) {
			refs.previewWarnings.hidden = true;
		}
		if (refs.warningList) {
			refs.warningList.innerHTML = '';
		}
	};

	const resetDiscoveryState = ({ clearConnection = false } = {}) => {
		state.discoveryData = null;
		state.selectedPostTypes = [];
		state.postTypeMappings = {};
		state.targetPostTypes = [];
		runtime.discoveryLoaded = false;
		setMessage(refs.selectMessage, '');
		resetDiscoveryUi();

		if (clearConnection) {
			state.migrationKey = '';
			state.targetUrl = '';
			state.targetPostTypes = [];
			runtime.validatedMigrationUrl = '';
			if (refs.keyInput) {
				refs.keyInput.value = '';
			}
		}
	};

	const buildDiscoveryData = (response) => {
		const posts = Array.isArray(response?.posts) ? response.posts : [];
		const grouped = new Map();

		posts.forEach((item) => {
			const type = String(item?.type || '').trim();
			if (!type || type === 'attachment') {
				return;
			}

			const existing = grouped.get(type) || {
				slug: type,
				label: humanize(type),
				count: 0,
				customFields: 0,
				hasBricks: false,
			};

			existing.count += 1;
			existing.hasBricks = existing.hasBricks || Boolean(item?.has_bricks);
			grouped.set(type, existing);
		});

		const postTypes = Array.from(grouped.values()).sort((a, b) => b.count - a.count);

		const bricksCount = Number(response?.bricks_count || 0);
		const gutenbergCount = Number(response?.gutenberg_count || 0);
		const mediaCount = Number(response?.media_count || 0);
		const totalContent = Math.max(bricksCount + gutenbergCount, 1);
		const nonBricksRatio = gutenbergCount / totalContent;

		let grade = 'green';
		if (nonBricksRatio > 0.35) {
			grade = 'red';
		} else if (nonBricksRatio > 0.15) {
			grade = 'yellow';
		}

		const gradeLabelMap = {
			green: 'High convertibility detected (Green)',
			yellow: 'Mixed convertibility detected (Yellow)',
			red: 'Low convertibility detected (Red)',
		};

		return {
			postTypes,
			summary: {
				grade,
				label: gradeLabelMap[grade],
				breakdown: [
					{ label: 'Bricks entries', value: bricksCount },
					{ label: 'Non-Bricks entries', value: gutenbergCount },
					{ label: 'Media items', value: mediaCount },
				],
			},
			raw: {
				bricksCount,
				gutenbergCount,
				mediaCount,
			},
		};
	};

	const applyDefaultSelections = () => {
		const postTypes = getDiscoveryPostTypes(state.discoveryData);
		if (!postTypes.length) {
			return;
		}

		if (!state.selectedPostTypes.length) {
			state.selectedPostTypes = postTypes.map((item) => item.slug);
		}

		const etchSlugs = getAvailableMappingOptions();
		const defaultMap = (bricksSlug) => {
			if (etchSlugs.includes(bricksSlug)) {
				return bricksSlug;
			}
			if (bricksSlug === 'bricks_template' && etchSlugs.includes('etch_template')) {
				return 'etch_template';
			}
			if (etchSlugs.includes('post')) {
				return 'post';
			}
			return etchSlugs[0] || '';
		};

		postTypes.forEach((item) => {
			if (!state.postTypeMappings[item.slug]) {
				state.postTypeMappings[item.slug] = defaultMap(item.slug);
			}
		});
	};

	const fetchTargetPostTypes = async () => {
		if (state.targetPostTypes && state.targetPostTypes.length) {
			return;
		}
		if (!state.targetUrl || !state.migrationKey) {
			return;
		}
		try {
			const result = await post(ACTION_GET_TARGET_POST_TYPES, {
				target_url: state.targetUrl,
				migration_key: state.migrationKey,
			});
			const list = result?.post_types;
			if (Array.isArray(list) && list.length) {
				state.targetPostTypes = list;
			} else {
				state.targetPostTypes = [{ slug: 'post', label: 'Posts' }, { slug: 'page', label: 'Pages' }];
			}
		} catch (err) {
			state.targetPostTypes = [{ slug: 'post', label: 'Posts' }, { slug: 'page', label: 'Pages' }];
		}
	};

	const runDiscovery = async () => {
		if (runtime.discoveryLoaded) {
			return;
		}
		perfMetrics.startDiscovery();

		if (refs.discoveryLoading) {
			refs.discoveryLoading.hidden = false;
		}

		setMessage(refs.selectMessage, '');

		try {
			await fetchTargetPostTypes();
			const result = await post(ACTION_DISCOVER_CONTENT, {});
			state.discoveryData = buildDiscoveryData(result);
			applyDefaultSelections();
			renderSummary();
			renderPostTypeTable();
			showToast('Discovery complete', 'success');
			runtime.discoveryLoaded = true;
			perfMetrics.endDiscovery();
			if (refs.discoveryLoading) {
				refs.discoveryLoading.hidden = true;
			}
			updateNavigationState();
			await saveWizardState();
		} catch (error) {
			setMessage(refs.selectMessage, error?.message || 'Discovery failed. Try again.', 'error');
		} finally {
			if (refs.discoveryLoading) {
				refs.discoveryLoading.hidden = true;
			}
		}
	};

	const renderPreview = async () => {
		if (!refs.previewBreakdown) {
			return;
		}

		const postTypes = getDiscoveryPostTypes(state.discoveryData);
		const selected = postTypes.filter((item) => state.selectedPostTypes.includes(item.slug));

		if (!selected.length) {
			refs.previewBreakdown.innerHTML = '<p>No post types selected.</p>';
			if (refs.cssPreview) {
				refs.cssPreview.hidden = true;
			}
			return;
		}

		const breakdown = selected.map((item) => {
			const mapped = state.postTypeMappings[item.slug] || 'Unmapped';
			return `<li><strong>${item.label}</strong>: ${item.count} -> ${humanize(mapped)}</li>`;
		}).join('');

		const totalSelectedCount = selected.reduce((sum, item) => sum + item.count, 0);

		refs.previewBreakdown.innerHTML = `
			<ul class="efs-wizard-preview-list">${breakdown}</ul>
			<p><strong>Total selected items:</strong> ${totalSelectedCount}</p>
			<p><strong>Media:</strong> ${state.includeMedia ? 'Included' : 'Excluded'}</p>
			<p><strong>Custom fields summary:</strong> ${selected.reduce((sum, item) => sum + item.customFields, 0)} detected groups.</p>
		`;

		const warnings = [];
		// Only consider selected items: same destination used by multiple *selected* source types?
		const selectedTargets = selected.map((item) => state.postTypeMappings[item.slug]).filter(Boolean);
		const duplicateTargets = selectedTargets.filter((target, index, all) => all.indexOf(target) !== index);

		if (duplicateTargets.length > 0) {
			warnings.push({
				level: 'info',
				text: 'More than one source post type maps to the same destination (e.g. Bricks Template and Post → Posts). This is valid; all selected content will be migrated into that type.',
			});
		}

		if (selected.some((item) => item.slug.includes('product') || item.slug.includes('woocommerce'))) {
			warnings.push({
				level: 'warning',
				text: 'WooCommerce-related post types were selected. Verify compatibility before migration.',
			});
		}

		if ((getDiscoverySummary(state.discoveryData)?.grade || '') === 'red') {
			warnings.push({
				level: 'error',
				text: 'Discovery indicates low convertibility. Unconvertible dynamic data may need manual cleanup.',
			});
		}

		if (!refs.previewWarnings || !refs.warningList) {
			return;
		}

		if (!warnings.length) {
			refs.previewWarnings.hidden = true;
			refs.warningList.innerHTML = '';
			return;
		}

		refs.previewWarnings.hidden = false;
		refs.warningList.innerHTML = warnings.map((warning) => `<li class="is-${warning.level}">${warning.text}</li>`).join('');

		if (refs.cssPreview) {
			try {
				const cssData = await post(ACTION_GET_CSS_PREVIEW, {
					selected_post_types: state.selectedPostTypes,
					restrict_css_to_used: state.restrictCssToUsed ? '1' : '0',
				});
				const total = cssData?.css_classes_total ?? 0;
				const toMigrate = cssData?.css_classes_to_migrate ?? 0;
				if (state.restrictCssToUsed && toMigrate < total) {
					refs.cssPreview.innerHTML = `<p><strong>CSS Classes:</strong> ${toMigrate.toLocaleString()} <span class="efs-wizard-hint">&#10003; filtered from ${total.toLocaleString()} total</span></p>`;
				} else {
					refs.cssPreview.innerHTML = `<p><strong>CSS Classes:</strong> ${total.toLocaleString()} <span class="efs-wizard-hint">(all classes)</span></p>`;
				}
				refs.cssPreview.hidden = false;
			} catch (e) {
				refs.cssPreview.hidden = true;
			}
		}
	};

	const renderProgress = (payload) => {
		const progress = payload?.progress || {};
		const percentage = Number(progress?.percentage || 0);
		const status = progress?.current_phase_name || progress?.status || progress?.current_step || 'Running migration...';
		const itemsProcessed = Number(progress?.items_processed || 0);
		const itemsTotal = Number(progress?.items_total || 0);
		runtime.lastProgressPercentage = percentage;

		updateTabTitle(percentage, status);
		if (runtime.progressChip) {
			updateProgressChip(runtime.progressChip, percentage);
		}

		if (refs.progressFill) {
			refs.progressFill.style.width = `${Math.max(0, Math.min(percentage, 100))}%`;
		}

		if (refs.progressPercent) {
			refs.progressPercent.textContent = `${Math.round(percentage)}%`;
		}

		if (refs.progressStatus) {
			refs.progressStatus.textContent = String(status);
		}

		if (refs.bannerText) {
			refs.bannerText.textContent = `Migration in progress: ${Math.round(percentage)}%`;
		}

		if (refs.progressItems) {
			const currentItemTitle = payload?.current_item?.title || progress?.current_item_title || '';
			if (itemsTotal > 0) {
				let itemsText = `Items processed: ${itemsProcessed}/${itemsTotal}`;
				if (currentItemTitle) {
					itemsText += ` — ${currentItemTitle}`;
				}
				refs.progressItems.textContent = itemsText;
			} else if (itemsProcessed > 0) {
				refs.progressItems.textContent = `Items processed: ${itemsProcessed}`;
			} else if (currentItemTitle) {
				refs.progressItems.textContent = currentItemTitle;
			} else {
				refs.progressItems.textContent = '';
			}
		}

		if (refs.progressElapsed) {
			const startedAtRaw = progress?.started_at;
			// Append 'Z' so the browser parses the WP UTC datetime as UTC, not local time.
			// Without 'Z', new Date('2026-02-21T04:35:42') is treated as local time,
			// producing a constant offset equal to the browser UTC offset (e.g. +360 min for UTC+6).
			const startedAt = typeof startedAtRaw === 'string' && startedAtRaw.trim() !== ''
				? startedAtRaw.trim().replace(' ', 'T') + 'Z'
				: '';
			const startedMs = startedAt ? new Date(startedAt).getTime() : NaN;
			const elapsedSec = Number.isFinite(startedMs) && startedMs > 0
				? Math.max(0, Math.floor((Date.now() - startedMs) / 1000))
				: null;
			const etaSec = progress?.estimated_time_remaining != null
				? Number(progress.estimated_time_remaining)
				: null;
			const etaStr = formatEta(etaSec);
			if (elapsedSec != null) {
				let text = `Elapsed: ${formatElapsed(elapsedSec)}`;
				if (etaStr) {
					text += `  •  ${etaStr}`;
				}
				refs.progressElapsed.textContent = text;
				refs.progressElapsed.hidden = false;
			} else {
				refs.progressElapsed.textContent = '';
				refs.progressElapsed.hidden = true;
			}
		}

		if (refs.progressSteps) {
			const steps = normalizeSteps(payload?.steps || progress?.steps || []);
			refs.progressSteps.innerHTML = steps.map((step) => {
				const label = step?.label || humanize(step?.slug || 'step');
				const statusClass = step?.completed ? 'is-complete' : (step?.active ? 'is-active' : 'is-pending');
				return `<li class="efs-migration-step ${statusClass}">${label}</li>`;
			}).join('');
		}
	};

	const showResult = (type, subtitle) => {
		resetTabTitle();
		removeProgressChip(runtime.progressChip);
		runtime.progressChip = null;
		runtime.migrationRunning = false;
		state.progressMinimized = false;
		root.classList.remove('is-progress-minimized');
		if (refs.progressTakeover) {
			if (refs.progressTakeover.parentNode !== document.body) {
				document.body.appendChild(refs.progressTakeover);
			}
			refs.progressTakeover.hidden = false;
			refs.progressTakeover.classList.add('is-showing-result');
		}
		if (refs.progressPanel) {
			refs.progressPanel.hidden = true;
		}
		if (refs.progressBanner) {
			refs.progressBanner.hidden = true;
		}
		if (refs.minimizeButton) {
			refs.minimizeButton.hidden = true;
		}

		const isSuccess = type === 'success';
		if (refs.resultTitle) {
			refs.resultTitle.textContent = isSuccess ? 'Migration complete' : 'Migration failed';
		}
		if (refs.resultSubtitle) {
			refs.resultSubtitle.textContent = subtitle || (isSuccess ? 'Migration completed successfully.' : 'Migration failed.');
		}
		if (refs.result) {
			refs.result.classList.toggle('is-success', isSuccess);
			refs.result.classList.toggle('is-error', !isSuccess);
			refs.result.hidden = false;
		}

		showToast(subtitle || (isSuccess ? 'Migration complete.' : 'Migration failed.'), isSuccess ? 'success' : 'error');
	};

	const hideResult = () => {
		if (!refs.result) {
			return;
		}

		refs.result.hidden = true;
		refs.result.classList.remove('is-error', 'is-success');
	};

	const reopenProgress = () => {
		state.progressMinimized = false;
		root.classList.remove('is-progress-minimized');
		if (refs.progressTakeover) {
			if (refs.progressTakeover.parentNode !== document.body) {
				document.body.appendChild(refs.progressTakeover);
			}
			refs.progressTakeover.hidden = !runtime.migrationRunning;
			refs.progressTakeover.classList.remove('is-showing-result');
		}
		if (refs.progressPanel) {
			refs.progressPanel.hidden = false;
		}
		if (refs.minimizeButton) {
			refs.minimizeButton.hidden = false;
		}
		hideResult();
		if (refs.progressBanner) {
			refs.progressBanner.hidden = true;
		}
		removeProgressChip(runtime.progressChip);
		runtime.progressChip = null;
	};

	const dismissProgress = () => {
		state.progressMinimized = true;
		root.classList.add('is-progress-minimized');
		if (refs.progressTakeover) {
			refs.progressTakeover.hidden = true;
		}
		if (refs.progressBanner) {
			refs.progressBanner.hidden = false;
		}
		if (refs.bannerText) {
			refs.bannerText.textContent = `Migration in progress: ${Math.round(runtime.lastProgressPercentage)}%`;
		}
		if (refs.expandButton) {
			refs.expandButton.hidden = false;
		}

		if (!runtime.progressChip && refs.progressChipContainer) {
			runtime.progressChip = createProgressChip(refs.progressChipContainer);
			if (runtime.progressChip) {
				runtime.progressChip.addEventListener('click', reopenProgress);
				runtime.progressChip.addEventListener('keydown', (event) => {
					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						reopenProgress();
					}
				});
			}
		}

		if (runtime.progressChip) {
			updateProgressChip(runtime.progressChip, runtime.lastProgressPercentage);
		}
	};

	const stopPolling = () => {
		if (runtime.pollingTimer) {
			window.clearTimeout(runtime.pollingTimer);
			runtime.pollingTimer = null;
		}
	};

	const runBatchLoop = async (migrationId) => {
		runtime.batchFailCount = 0;
		let currentBatchSize = BATCH_SIZE;

		const processBatch = async () => {
			try {
				const payload = await post(ACTION_MIGRATE_BATCH, {
					migration_id: migrationId,
					batch_size: currentBatchSize,
				});
				runtime.batchFailCount = 0;

				const progress = payload?.progress || {};
				const itemsProcessed = Number(progress?.items_processed || 0);
				if (runtime.lastProcessedCount !== undefined && itemsProcessed > runtime.lastProcessedCount) {
					const batchDiff = itemsProcessed - runtime.lastProcessedCount;
					const batchDuration = performance.now() - (runtime.lastPollTime || performance.now());
					perfMetrics.recordBatch(batchDiff, batchDuration);
				}
				runtime.lastProcessedCount = itemsProcessed;
				runtime.lastPollTime = performance.now();

				renderProgress(payload);

				if (payload?.memory_pressure) {
					currentBatchSize = Math.max(1, Math.floor(currentBatchSize / 2));
					showToast('⚡ Batch size adjusted due to memory pressure', 'info');
				}

				if (payload?.completed) {
					perfMetrics.endMigration();
					const hints = perfMetrics.getBottleneckHints();
					if (hints && hints.length > 0) {
						console.warn('[EFS][Perf] Bottleneck hints:', hints);
					}
					runtime.migrationRunning = false;
					showResult('success', 'Migration finished successfully.');
					return;
				}

				// Yield to event loop then continue.
				window.setTimeout(processBatch, 100);
			} catch (error) {
				runtime.batchFailCount = (runtime.batchFailCount || 0) + 1;
				if (runtime.batchFailCount >= 3) {
					runtime.migrationRunning = false;
					if (refs.retryButton) {
						refs.retryButton.hidden = false;
					}
					showResult('error', error?.message || 'Batch processing failed. Try resuming the migration.');
					return;
				}
				window.setTimeout(processBatch, POLL_INTERVAL_MS);
			}
		};

		await processBatch();
	};

	const resumeMigration = async (migrationId) => {
		try {
			const payload = await post(ACTION_RESUME_MIGRATION, {
				migration_id: migrationId,
			});

			if (payload?.resumed) {
				hideResult();
				if (refs.retryButton) {
					refs.retryButton.hidden = true;
				}
				runtime.migrationRunning = true;
				runtime.batchFailCount = 0;
				updateNavigationState();
				renderProgress(payload);
				await runBatchLoop(migrationId);
			} else {
				showResult('error', 'No checkpoint found — restart the migration from the beginning.');
				if (refs.retryButton) {
					refs.retryButton.hidden = false;
				}
			}
		} catch (error) {
			const isNoCheckpoint = error?.code === 'no_checkpoint'
				|| String(error?.message || '').toLowerCase().includes('checkpoint');
			const message = isNoCheckpoint
				? 'No checkpoint found — you need to restart the migration.'
				: (error?.message || 'Unable to resume migration.');
			showResult('error', message);
			if (refs.retryButton) {
				refs.retryButton.hidden = false;
			}
		}
	};

	const startPolling = (migrationId) => {
		if (!migrationId) {
			return;
		}

		stopPolling();
		runtime.migrationRunning = true;
		runtime.pollingFailCount = 0;
		const pollingStartTime = Date.now();

		const poll = async () => {
			try {
				const payload = await post(ACTION_GET_PROGRESS, {
					migration_id: migrationId,
				});
				runtime.pollingFailCount = 0;
				const progress = payload?.progress || {};
				const itemsProcessed = Number(progress?.items_processed || 0);

				if (runtime.lastProcessedCount !== undefined && itemsProcessed > runtime.lastProcessedCount) {
					const batchSize = itemsProcessed - runtime.lastProcessedCount;
					const batchDuration = performance.now() - (runtime.lastPollTime || performance.now());
					perfMetrics.recordBatch(batchSize, batchDuration);
				}
				runtime.lastProcessedCount = itemsProcessed;
				runtime.lastPollTime = performance.now();

				state.migrationId = payload?.migrationId || migrationId;
				renderProgress(payload);

				const status = String(payload?.progress?.status || payload?.progress?.current_step || '').toLowerCase();
				const currentStep = String(payload?.progress?.current_step || '').toLowerCase();
				const percentage = Number(payload?.progress?.percentage ?? 0);

				if (payload?.completed || status === 'completed' || percentage >= 100) {
					perfMetrics.endMigration();
					const hints = perfMetrics.getBottleneckHints();
					if (hints && hints.length > 0) {
						console.warn('[EFS][Perf] Bottleneck hints:', hints);
					}
					runtime.migrationRunning = false;
					showResult('success', 'Migration finished successfully.');
					return;
				}

				if (status === 'error' || payload?.error) {
					runtime.migrationRunning = false;
					if (refs.retryButton) {
						refs.retryButton.hidden = false;
					}
					showResult('error', payload?.progress?.message || payload?.message || payload?.error || 'Migration stopped due to an error.');
					return;
				}

				const isStale = Boolean(payload?.is_stale || payload?.progress?.is_stale || status === 'stale');
				if (isStale && percentage === 0) {
					runtime.migrationRunning = false;
					if (refs.retryButton) {
						refs.retryButton.hidden = false;
					}
					showResult('error', 'Migration did not start (e.g. background request could not reach the server). Try again or check server configuration.');
					return;
				}

				// Early client-side detection: if after 60 s progress is still 0%, the
				// background process likely never started (loopback blocked, etc.).
				const elapsedSeconds = (Date.now() - pollingStartTime) / 1000;
				if (percentage === 0 && status === 'running' && elapsedSeconds > 60) {
					runtime.migrationRunning = false;
					if (refs.retryButton) {
						refs.retryButton.hidden = false;
					}
					showResult('error', 'Migration did not start within 60 seconds. The server may not be able to reach itself (loopback request). Check server logs or try again.');
					return;
				}

				// Background phase complete: switch to JS-driven batch loop (media or posts).
				if ((currentStep === 'media' || currentStep === 'posts') && status === 'running') {
					stopPolling();
					await runBatchLoop(migrationId);
					return;
				}

				runtime.pollingTimer = window.setTimeout(poll, POLL_INTERVAL_MS);
			} catch (error) {
				runtime.pollingFailCount = (runtime.pollingFailCount || 0) + 1;
				if (runtime.pollingFailCount >= 3) {
					runtime.migrationRunning = false;
					if (refs.retryButton) {
						refs.retryButton.hidden = false;
					}
					showResult('error', error?.message || 'Progress check failed. Migration may still be running on the server.');
					return;
				}
				runtime.pollingTimer = window.setTimeout(poll, POLL_INTERVAL_MS);
			}
		};

		poll();
	};

	const setStep = async (step, options = {}) => {
		const nextStep = Math.max(1, Math.min(STEP_COUNT, Number(step) || 1));
		state.currentStep = nextStep;

		setMessage(refs.connectMessage, '');
		setMessage(refs.selectMessage, '');

		renderStepShell();

		if (nextStep === 2 && !runtime.discoveryLoaded) {
			await runDiscovery();
		}

		if (nextStep === 3) {
			await renderPreview();
		}

		if (nextStep < 4) {
			reopenProgress();
		}

		if (!options.skipSave) {
			await saveWizardState();
		}
	};

	const applyPreset = async (preset) => {
		const postTypes = getDiscoveryPostTypes(state.discoveryData);
		if (!postTypes.length) {
			return;
		}

		if (preset === 'all') {
			state.selectedPostTypes = postTypes.map((item) => item.slug);
		} else if (preset === 'posts') {
			state.selectedPostTypes = postTypes
				.filter((item) => item.slug === 'post')
				.map((item) => item.slug);
		} else if (preset === 'cpts') {
			state.selectedPostTypes = postTypes
				.filter((item) => !['post', 'page'].includes(item.slug))
				.map((item) => item.slug);
		} else {
			state.selectedPostTypes = [];
		}

		renderPostTypeTable();
		updateNavigationState();
		await saveWizardState();
	};

	const validateConnectStep = async () => {
		const url = refs.urlInput?.value?.trim() || '';
		state.migrationUrl = url;

		if (!url) {
			throw new Error('Please enter the target site URL or paste a migration key before continuing.');
		}

		runtime.validatingConnect = true;
		updateNavigationState();
		showToast('Connecting to target site…', 'info');

		try {
			let token = '';
			let targetUrl = '';

			// Detect input format: a full migration URL contains an embedded token;
			// a bare target URL (e.g. https://mysite.com) triggers the reverse-generation flow.
			try {
				const parsed = parseMigrationUrl(url);
				token = parsed.token;
				targetUrl = parsed.origin;
			} catch (parseError) {
				// No embedded token — treat the input as the target site base URL.
				try {
					targetUrl = new URL(url.includes('://') ? url : `https://${url}`).origin;
				} catch (urlError) {
					throw new Error('Invalid URL. Enter the target site URL (e.g. https://mysite.com) or paste a migration URL.');
				}
			}

			if (runtime.validatedMigrationUrl && runtime.validatedMigrationUrl !== targetUrl) {
				resetDiscoveryState({ clearConnection: true });
			}

			if (token) {
				// Legacy flow: embedded token was parsed — validate it against the target.
				const urlResult = await post(ACTION_VALIDATE_URL, {
					wizard_nonce: wizardNonce,
					migration_url: url,
				});
				if (urlResult?.warning) {
					setMessage(refs.connectMessage, urlResult.warning, 'warning');
				}

				const validation = await post(ACTION_VALIDATE_TOKEN, {
					migration_key: token,
					target_url: targetUrl,
				});
				if (!validation?.valid) {
					throw new Error('The migration token could not be validated.');
				}
			} else {
				// Reverse-generation flow: request a fresh token directly from the target.
				// The connection URL from the Etch side embeds the one-time pairing code as
				// ?_efs_pair=<raw_code>. Extract it automatically — no separate input needed.
				let pairingCode = '';
				try {
					const parsedInput = new URL(url.includes('://') ? url : `https://${url}`);
					pairingCode = parsedInput.searchParams.get('_efs_pair') || '';
				} catch (_parseErr) {
					// ignore — pairingCode stays empty
				}
				if (!pairingCode) {
					throw new Error('Paste the connection URL from the target site (generated via "Generate Connection URL" on the Etch dashboard).');
				}

				const sourceUrl = window.efsData?.site_url || window.location.origin;
				const generateUrl = `${targetUrl}/wp-json/efs/v1/generate-key`
					+ `?source_url=${encodeURIComponent(sourceUrl)}`
					+ `&pairing_code=${encodeURIComponent(pairingCode)}`;

				let tokenRes;
				try {
					tokenRes = await fetch(generateUrl, { credentials: 'omit' });
				} catch (fetchError) {
					throw new Error(`Could not reach target site at ${targetUrl}. Check the URL and ensure the plugin is active on the target.`);
				}

				if (!tokenRes.ok) {
					throw new Error(`Target site rejected the connection (HTTP ${tokenRes.status}). Verify the target URL and CORS settings.`);
				}

				const tokenData = await tokenRes.json();
				token = tokenData?.migration_key || tokenData?.token || '';
				if (!token) {
					throw new Error('Target did not return a migration token. Ensure Etch Fusion Suite is active on the target site.');
				}
			}

			state.migrationKey = token;
			state.targetUrl = targetUrl;
			runtime.validatedMigrationUrl = targetUrl;
			runPreflightCheck(state.targetUrl, state.mode || 'browser').catch(() => {});
			if (refs.keyInput) {
				refs.keyInput.value = token;
			}

			setMessage(refs.connectMessage, 'Connection successful.', 'success');
			await saveWizardState();
			return true;
		} finally {
			runtime.validatingConnect = false;
			updateNavigationState();
		}
	};

	const startMigration = async () => {
		if (runtime.migrationRunning) {
			return;
		}

		if (!state.migrationKey && refs.keyInput?.value) {
			state.migrationKey = refs.keyInput.value.trim();
		}
		if ((!state.targetUrl || !state.migrationKey) && state.migrationUrl) {
			try {
				const parsed = parseMigrationUrl(state.migrationUrl);
				state.migrationKey = state.migrationKey || parsed.token;
				state.targetUrl = state.targetUrl || parsed.origin;
			} catch (error) {
				// Leave validation errors to the migration start endpoint for now.
			}
		}

		hideResult();
		if (refs.retryButton) {
			refs.retryButton.hidden = true;
		}

		runtime.migrationRunning = true;
		updateNavigationState();

		try {
			const payload = await post(ACTION_START_MIGRATION, {
				migration_key: state.migrationKey,
				target_url: state.targetUrl,
				batch_size: state.batchSize,
				selected_post_types: state.selectedPostTypes,
				post_type_mappings: state.postTypeMappings,
				include_media: state.includeMedia ? '1' : '0',
				restrict_css_to_used: state.restrictCssToUsed ? '1' : '0',
			});

			state.migrationId = payload?.migrationId ?? payload?.migration_id ?? '';
			if (!state.migrationId) {
				throw new Error('Migration did not return an ID.');
			}
			const totalItems = getDiscoveryPostTypes(state.discoveryData)
				.filter((pt) => state.selectedPostTypes.includes(pt.slug))
				.reduce((sum, pt) => sum + pt.count, 0);
			perfMetrics.startMigration(totalItems);
			runtime.lastProcessedCount = undefined;
			runtime.lastPollTime = undefined;

			reopenProgress();
			await setStep(4);
			if (refs.progressTakeover) {
				if (refs.progressTakeover.parentNode !== document.body) {
					document.body.appendChild(refs.progressTakeover);
				}
				refs.progressTakeover.hidden = false;
			}
			renderProgress(payload);
			await saveWizardState();
			startPolling(state.migrationId);
		} catch (error) {
			runtime.migrationRunning = false;
			throw error;
		} finally {
			updateNavigationState();
		}
	};

	const resetWizard = async () => {
		stopPolling();
		runtime.migrationRunning = false;
		runtime.discoveryLoaded = false;
		runtime.validatedMigrationUrl = '';
		runtime.lastProgressPercentage = 0;
		resetTabTitle();
		removeProgressChip(runtime.progressChip);
		runtime.progressChip = null;

		Object.assign(state, defaultState());

		if (refs.urlInput) {
			refs.urlInput.value = '';
		}

		if (refs.keyInput) {
			refs.keyInput.value = '';
		}
		resetDiscoveryUi();

		if (refs.includeMedia) {
			refs.includeMedia.checked = true;
		}

		if (refs.restrictCssToUsed) {
			refs.restrictCssToUsed.checked = true;
		}
		state.restrictCssToUsed = true;

		if (refs.progressTakeover) {
			refs.progressTakeover.hidden = true;
		}

		if (refs.progressBanner) {
			refs.progressBanner.hidden = true;
		}

		reopenProgress();
		root.classList.remove('is-progress-minimized');
		hideResult();
		await clearWizardState();
		await setStep(1, { skipSave: true });
	};

	const handleCancel = async () => {
		if (state.currentStep === 4) {
			try {
				const migrationId = state.migrationId || window.efsData?.migrationId || window.efsData?.in_progress_migration?.migrationId || '';
				await post(ACTION_CANCEL_MIGRATION, {
					migration_id: migrationId,
				});
				showToast('Migration cancelled.', 'info');
			} catch (error) {
				showToast(error?.message || 'Unable to cancel migration.', 'error');
			}
		}

		await resetWizard();
	};

	const openLogsTab = () => {
		if (refs.progressTakeover) {
			refs.progressTakeover.hidden = true;
		}
		if (refs.progressBanner) {
			refs.progressBanner.hidden = true;
		}

		const migrationHash = state.migrationId ? `#migration-${state.migrationId}` : '#logs';
		if (state.migrationId) {
			window.history.replaceState(null, '', migrationHash);
		}

		const logsTab = document.querySelector('[data-efs-tab="logs"]');
		if (logsTab) {
			logsTab.click();
			document.querySelector('#efs-tab-logs')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
			return;
		}

		const logsPanel = document.querySelector('[data-efs-log-panel]');
		if (logsPanel) {
			logsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
			return;
		}

		const fallbackUrl = new URL(window.location.href);
		fallbackUrl.searchParams.set('page', 'etch-fusion-suite');
		fallbackUrl.hash = migrationHash;
		window.location.assign(fallbackUrl.toString());
	};

	const bindEvents = () => {
		refs.urlInput?.addEventListener('input', () => {
			const nextUrl = refs.urlInput.value.trim();
			if (nextUrl !== state.migrationUrl) {
				resetDiscoveryState({ clearConnection: true });
			}
			state.migrationUrl = nextUrl;
			setMessage(refs.connectMessage, '');
			updateNavigationState();
		});

		refs.pasteButton?.addEventListener('click', async () => {
			try {
				const text = navigator.clipboard?.readText ? await navigator.clipboard.readText() : '';
				if (!text || typeof text !== 'string') {
					showToast('Clipboard is empty or paste is not supported.', 'info');
					return;
				}
				const trimmed = text.trim();
				if (refs.urlInput) {
					refs.urlInput.value = trimmed;
					if (trimmed !== state.migrationUrl) {
						resetDiscoveryState({ clearConnection: true });
					}
					state.migrationUrl = trimmed;
					setMessage(refs.connectMessage, '');
					updateNavigationState();
					showToast('Key pasted.', 'success');
				}
			} catch (err) {
				showToast('Could not read clipboard. Paste manually.', 'error');
			}
		});

		refs.backButton?.addEventListener('click', async () => {
			if (state.currentStep > 1 && state.currentStep < 4) {
				await setStep(state.currentStep - 1);
			}
		});

		refs.nextButton?.addEventListener('click', async () => {
			try {
				if (state.currentStep === 1) {
					await validateConnectStep();
					await setStep(2);
					return;
				}

				if (state.currentStep === 2) {
					if (!hasValidStep2Selection()) {
						setMessage(refs.selectMessage, 'Select at least one post type and mapping to continue.', 'error');
						return;
					}
					const mappingErrors = validatePostTypeMappings();
					if (mappingErrors.length > 0) {
						setMessage(refs.selectMessage, mappingErrors.join('; '), 'error');
						return;
					}
					await setStep(3);
					return;
				}

				if (state.currentStep === 3) {
					await startMigration();
				}
			} catch (error) {
				const message = error?.message || 'Unable to continue to the next step.';
				if (state.currentStep === 1) {
					setMessage(refs.connectMessage, message, 'error');
				} else if (state.currentStep === 2) {
					setMessage(refs.selectMessage, message, 'error');
				} else {
					showResult('error', message);
				}
			}
		});

		refs.cancelButton?.addEventListener('click', handleCancel);
		refs.progressCancelButton?.addEventListener('click', handleCancel);
		refs.preflightRecheck?.addEventListener('click', () => invalidateAndRecheck());
		refs.preflightConfirm?.addEventListener('change', (e) => {
			state.preflightConfirmed = e.target.checked;
			updateNavigationState();
		});
		refs.retryButton?.addEventListener('click', async () => {
			refs.retryButton.hidden = true;
			try {
				if (state.migrationId) {
					await resumeMigration(state.migrationId);
				} else {
					await startMigration();
				}
			} catch (error) {
				const message = error?.message || 'Unable to restart migration.';
				showResult('error', message);
				refs.retryButton.hidden = false;
			}
		});

		refs.minimizeButton?.addEventListener('click', dismissProgress);
		refs.expandButton?.addEventListener('click', reopenProgress);

		refs.runFullAnalysis?.addEventListener('click', () => {
			showToast('Full analysis endpoint is not available yet. Showing sampled discovery data.', 'info');
		});

		refs.rowsBody?.addEventListener('change', async (event) => {
			const target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}

			if (target.matches('[data-efs-post-type-check]')) {
				const slug = target.getAttribute('data-efs-post-type-check') || '';
				const checked = target instanceof HTMLInputElement ? target.checked : false;
				if (checked) {
					if (!state.selectedPostTypes.includes(slug)) {
						state.selectedPostTypes.push(slug);
					}
				} else {
					state.selectedPostTypes = state.selectedPostTypes.filter((item) => item !== slug);
				}
				renderPostTypeTable();
				updateNavigationState();
				await saveWizardState();
				return;
			}

			if (target.matches('[data-efs-post-type-map]')) {
				const slug = target.getAttribute('data-efs-post-type-map') || '';
				const value = target instanceof HTMLSelectElement ? target.value : '';
				state.postTypeMappings[slug] = value;
				updateNavigationState();
				await saveWizardState();
			}
		});

		refs.includeMedia?.addEventListener('change', async () => {
			state.includeMedia = Boolean(refs.includeMedia?.checked);
			await saveWizardState();
		});

		refs.restrictCssToUsed?.addEventListener('change', async () => {
			state.restrictCssToUsed = Boolean(refs.restrictCssToUsed?.checked);
			await saveWizardState();
		});

		refs.presets.forEach((button) => {
			button.addEventListener('click', async () => {
				await applyPreset(button.getAttribute('data-efs-preset') || 'none');
			});
		});

		refs.stepNav.forEach((button) => {
			button.addEventListener('click', async () => {
				const step = Number(button.getAttribute('data-efs-step-nav') || '1');
				if (step < state.currentStep && state.currentStep < 4) {
					await setStep(step);
				}
			});
		});

		refs.openLogsButton?.addEventListener('click', openLogsTab);
		refs.startNewButton?.addEventListener('click', resetWizard);
	};

	const restoreState = async () => {
		try {
			const result = await post(ACTION_GET_STATE, {
				wizard_nonce: wizardNonce,
			});

			const saved = result?.state;
			if (!saved || typeof saved !== 'object') {
				return;
			}

			state.currentStep = Number(saved.current_step || state.currentStep);
			state.migrationUrl = String(saved.migration_url || state.migrationUrl || '');
			state.migrationKey = String(saved.migration_key || state.migrationKey || '');
			state.targetUrl = String(saved.target_url || state.targetUrl || '');
			state.discoveryData = saved.discovery_data && typeof saved.discovery_data === 'object'
				? {
					...(state.discoveryData || {}),
					...saved.discovery_data,
				}
				: state.discoveryData;
			state.selectedPostTypes = Array.isArray(saved.selected_post_types) ? saved.selected_post_types : state.selectedPostTypes;
			state.postTypeMappings = saved.post_type_mappings && typeof saved.post_type_mappings === 'object'
				? saved.post_type_mappings
				: state.postTypeMappings;
			state.includeMedia = typeof saved.include_media === 'boolean' ? saved.include_media : state.includeMedia;
			state.restrictCssToUsed = typeof saved.restrict_css_to_used === 'boolean'
				? saved.restrict_css_to_used
				: state.restrictCssToUsed;
			if (refs.restrictCssToUsed) {
				refs.restrictCssToUsed.checked = state.restrictCssToUsed;
			}
			state.batchSize = Number(saved.batch_size || state.batchSize);

			if (refs.urlInput && state.migrationUrl) {
				refs.urlInput.value = state.migrationUrl;
			}
			if (refs.keyInput && state.migrationKey) {
				refs.keyInput.value = state.migrationKey;
			}
			if (state.migrationUrl && state.migrationKey && state.targetUrl) {
				try {
					runtime.validatedMigrationUrl = parseMigrationUrl(state.migrationUrl).normalizedUrl;
				} catch (error) {
					runtime.validatedMigrationUrl = state.migrationUrl;
				}
			}

			if (getDiscoveryPostTypes(state.discoveryData).length) {
				runtime.discoveryLoaded = true;
				if (refs.discoveryLoading) {
					refs.discoveryLoading.hidden = true;
				}
				await fetchTargetPostTypes();
				renderSummary();
				renderPostTypeTable();
			}
		} catch (error) {
			console.warn('[EFS] Failed to restore wizard state', error);
		}
	};

	const autoResumeMigration = async () => {
		const progress = window.efsData?.progress_data || {};
		const inProgress = window.efsData?.in_progress_migration || {};
		const migrationId = window.efsData?.migrationId || progress?.migrationId || inProgress?.migrationId || '';
		const completed = Boolean(window.efsData?.completed || progress?.completed);
		const status = String(progress?.status || progress?.current_step || '').toLowerCase();
		const percentage = Number(progress?.percentage ?? 0);

		if (!migrationId || completed || status === 'completed' || percentage >= 100) {
			return false;
		}

		if (!inProgress?.resumable && !status) {
			return false;
		}

		try {
			const payload = await post(ACTION_GET_PROGRESS, {
				migration_id: migrationId,
			});
			const pollStatus = String(payload?.progress?.status || payload?.progress?.current_step || '').toLowerCase();
			const pollPercentage = Number(payload?.progress?.percentage ?? 0);
			const isRunning = pollStatus === 'running' || pollStatus === 'receiving';
		const isStale = pollStatus === 'stale' || Boolean(payload?.progress?.is_stale || payload?.is_stale);
		const pollCurrentStep = String(payload?.progress?.current_step || '').toLowerCase();

		// Stale but checkpoint phase is media/posts: auto-resume via server (resets stale
			// flag) then start the batch loop directly — no user interaction required.
			if (isStale && (pollCurrentStep === 'media' || pollCurrentStep === 'posts')) {
				state.migrationId = payload?.migrationId || migrationId;
				try {
				const resumed = await post(ACTION_RESUME_MIGRATION, { migration_id: state.migrationId });
				if (resumed?.resumed) {
						await setStep(4, { skipSave: true });
						if (refs.progressTakeover) {
							refs.progressTakeover.hidden = false;
						}
						renderProgress(resumed);
						stopPolling();
						runtime.migrationRunning = true;
						await runBatchLoop(state.migrationId);
						return true;
					}
				} catch (e) {
					// No valid checkpoint — fall through to return false.
				}
				return false;
			}

			if (payload?.completed || pollStatus === 'completed' || pollStatus === 'error' || pollPercentage >= 100 || !isRunning) {
				return false;
			}

			state.migrationId = payload?.migrationId || migrationId;
			await setStep(4, { skipSave: true });
			if (refs.progressTakeover) {
				refs.progressTakeover.hidden = false;
			}
			renderProgress(payload);

			// If the background phase already completed (checkpoint saved) and the JS batch
			// loop should take over, start it directly instead of waiting for one polling round-trip.
			const resumeStep = String(payload?.progress?.current_step || '').toLowerCase();
			if ((resumeStep === 'media' || resumeStep === 'posts') && isRunning) {
				stopPolling();
				runtime.migrationRunning = true;
				await runBatchLoop(state.migrationId);
			} else {
				startPolling(state.migrationId);
			}
			return true;
		} catch (error) {
			return false;
		}
	};

	const init = async () => {
		bindEvents();
		await restoreState();

		// Run pre-flight silently on wizard load (no target URL yet)
		runPreflightCheck('', state.mode || 'browser').catch(() => {});

		const resumed = await autoResumeMigration();
		if (!resumed) {
			if (state.currentStep >= 4) {
				state.currentStep = hasValidStep2Selection() ? 3 : 1;
				resetTabTitle();
				removeProgressChip(runtime.progressChip);
				runtime.progressChip = null;
				if (refs.progressTakeover) {
					refs.progressTakeover.hidden = true;
				}
				if (refs.progressBanner) {
					refs.progressBanner.hidden = true;
				}
			}
			await setStep(state.currentStep, { skipSave: true });
		}
	};

	return { init };
};

export const initBricksWizard = () => {
	const root = document.querySelector('[data-efs-bricks-wizard]');
	if (!root) {
		return;
	}

	const wizard = createWizard(root);
	wizard.init().catch((error) => {
		console.error('[EFS] Failed to initialize Bricks wizard', error);
	});
};
