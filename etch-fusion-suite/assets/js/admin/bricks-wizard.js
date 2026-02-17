import { post } from './api.js';
import { showToast } from './ui.js';

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
	batchSize: 50,
	migrationId: '',
	progressMinimized: false,
});

const humanize = (value) => String(value || '')
	.replace(/[_-]+/g, ' ')
	.replace(/\b\w/g, (char) => char.toUpperCase())
	.trim();

const parseMigrationUrl = (rawUrl) => {
	if (!rawUrl || typeof rawUrl !== 'string') {
		throw new Error('Migration URL is required.');
	}

	let parsed;
	try {
		parsed = new URL(rawUrl.trim());
	} catch (error) {
		throw new Error('Migration URL format is invalid.');
	}

	const token = parsed.searchParams.get('token')
		|| parsed.searchParams.get('migration_key')
		|| parsed.searchParams.get('key')
		|| '';

	if (!token) {
		throw new Error('Migration URL does not contain a token.');
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
		keyInput: root.querySelector('[data-efs-wizard-migration-key]'),
		connectMessage: root.querySelector('[data-efs-connect-message]'),
		selectMessage: root.querySelector('[data-efs-select-message]'),
		discoveryLoading: root.querySelector('[data-efs-discovery-loading]'),
		discoverySummary: root.querySelector('[data-efs-discovery-summary]'),
		summaryGrade: root.querySelector('[data-efs-summary-grade]'),
		summaryBreakdown: root.querySelector('[data-efs-summary-breakdown]'),
		runFullAnalysis: root.querySelector('[data-efs-run-full-analysis]'),
		rowsBody: root.querySelector('[data-efs-post-type-rows]'),
		includeMedia: root.querySelector('[data-efs-include-media]'),
		previewBreakdown: root.querySelector('[data-efs-preview-breakdown]'),
		previewWarnings: root.querySelector('[data-efs-preview-warnings]'),
		warningList: root.querySelector('[data-efs-warning-list]'),
		progressTakeover: root.querySelector('[data-efs-progress-takeover]'),
		progressBanner: root.querySelector('[data-efs-progress-banner]'),
		progressFill: root.querySelector('[data-efs-wizard-progress-fill]'),
		progressPercent: root.querySelector('[data-efs-wizard-progress-percent]'),
		progressStatus: root.querySelector('[data-efs-wizard-progress-status]'),
		progressItems: root.querySelector('[data-efs-wizard-items]'),
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

	const hasValidStep2Selection = () => {
		if (!state.discoveryData || !getDiscoveryPostTypes(state.discoveryData).length) {
			return false;
		}

		return state.selectedPostTypes.some((slug) => Boolean(state.postTypeMappings[slug]));
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
			} else if (state.currentStep === 2) {
				disabled = !hasValidStep2Selection();
				label = 'Next';
			} else if (state.currentStep === 3) {
				disabled = false;
				label = 'Confirm & Start Migration';
			} else {
				disabled = true;
				label = 'Migration Running';
			}

			refs.nextButton.disabled = disabled;
			refs.nextButton.textContent = label;
			refs.nextButton.classList.toggle('efs-wizard-next--validating', state.currentStep === 1 && runtime.validatingConnect);
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
			const li = document.createElement('li');
			li.textContent = `${item.label}: ${item.value}`;
			refs.summaryBreakdown.appendChild(li);
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

			return `
				<tr data-efs-post-type-row="${postType.slug}">
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
			runtime.discoveryLoaded = true;
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

	const renderPreview = () => {
		if (!refs.previewBreakdown) {
			return;
		}

		const postTypes = getDiscoveryPostTypes(state.discoveryData);
		const selected = postTypes.filter((item) => state.selectedPostTypes.includes(item.slug));

		if (!selected.length) {
			refs.previewBreakdown.innerHTML = '<p>No post types selected.</p>';
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
		const mappingTargets = Object.values(state.postTypeMappings)
			.filter(Boolean)
			.filter((target, index, all) => all.indexOf(target) !== index);

		if (mappingTargets.length > 0) {
			warnings.push({
				level: 'warning',
				text: 'Multiple source post types map to the same destination post type.',
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
	};

	const renderProgress = (payload) => {
		const progress = payload?.progress || {};
		const percentage = Number(progress?.percentage || 0);
		const status = progress?.current_phase_name || progress?.status || progress?.current_step || 'Running migration...';
		const itemsProcessed = Number(progress?.items_processed || 0);
		const itemsTotal = Number(progress?.items_total || 0);

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
			if (itemsTotal > 0) {
				refs.progressItems.textContent = `Items processed: ${itemsProcessed}/${itemsTotal}`;
			} else if (itemsProcessed > 0) {
				refs.progressItems.textContent = `Items processed: ${itemsProcessed}`;
			} else {
				refs.progressItems.textContent = '';
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
		if (type === 'success') {
			showToast(subtitle || 'Migration complete.', 'success');
			resetWizard();
			return;
		}
		showToast(subtitle || 'Migration failed.', 'error');
		if (refs.progressTakeover) {
			refs.progressTakeover.hidden = true;
		}
		if (refs.progressBanner) {
			refs.progressBanner.hidden = true;
		}
		runtime.migrationRunning = false;
	};

	const hideResult = () => {
		if (!refs.result) {
			return;
		}

		refs.result.hidden = true;
		refs.result.classList.remove('is-error', 'is-success');
	};

	const toggleProgressMinimized = (minimized) => {
		state.progressMinimized = Boolean(minimized);
		root.classList.toggle('is-progress-minimized', state.progressMinimized);
		const takeoverVisible = runtime.migrationRunning && !state.progressMinimized;
		const bannerVisible = runtime.migrationRunning && state.progressMinimized;

		if (refs.progressTakeover) {
			refs.progressTakeover.hidden = !takeoverVisible;
		}

		if (refs.progressBanner) {
			refs.progressBanner.hidden = !bannerVisible;
		}
	};

	const stopPolling = () => {
		if (runtime.pollingTimer) {
			window.clearTimeout(runtime.pollingTimer);
			runtime.pollingTimer = null;
		}
	};

	const startPolling = (migrationId) => {
		if (!migrationId) {
			return;
		}

		stopPolling();
		runtime.migrationRunning = true;

		const poll = async () => {
			try {
				const payload = await post(ACTION_GET_PROGRESS, {
					migration_id: migrationId,
				});

				state.migrationId = payload?.migrationId || migrationId;
				renderProgress(payload);

				const status = String(payload?.progress?.status || payload?.progress?.current_step || '').toLowerCase();

				if (payload?.completed || status === 'completed') {
					runtime.migrationRunning = false;
					toggleProgressMinimized(false);
					showResult('success', 'Migration finished successfully.');
					return;
				}

				if (status === 'error') {
					runtime.migrationRunning = false;
					if (refs.retryButton) {
						refs.retryButton.hidden = false;
					}
					showResult('error', payload?.progress?.message || 'Migration stopped due to an error.');
					return;
				}

				runtime.pollingTimer = window.setTimeout(poll, POLL_INTERVAL_MS);
			} catch (error) {
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
			renderPreview();
		}

		if (nextStep < 4) {
			toggleProgressMinimized(false);
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
			throw new Error('Please paste a migration URL before continuing.');
		}

		runtime.validatingConnect = true;
		updateNavigationState();
		showToast('Validating… This may take a few seconds.', 'info');

		try {
			const parsed = parseMigrationUrl(url);
			if (runtime.validatedMigrationUrl && runtime.validatedMigrationUrl !== parsed.normalizedUrl) {
				resetDiscoveryState({ clearConnection: true });
			}

			const urlResult = await post(ACTION_VALIDATE_URL, {
				wizard_nonce: wizardNonce,
				migration_url: parsed.normalizedUrl,
			});

			if (urlResult?.warning) {
				setMessage(refs.connectMessage, urlResult.warning, 'warning');
			}

			const validation = await post(ACTION_VALIDATE_TOKEN, {
				migration_key: parsed.token,
				target_url: parsed.origin,
			});

			if (!validation?.valid) {
				throw new Error('The migration token could not be validated.');
			}

			state.migrationKey = parsed.token;
			state.targetUrl = parsed.origin;
			runtime.validatedMigrationUrl = parsed.normalizedUrl;
			if (refs.keyInput) {
				refs.keyInput.value = parsed.token;
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
			});

			state.migrationId = payload?.migrationId ?? payload?.migration_id ?? '';
			if (!state.migrationId) {
				throw new Error('Migration did not return an ID.');
			}

			toggleProgressMinimized(false);
			await setStep(4);
			if (refs.progressTakeover) {
				refs.progressTakeover.hidden = false;
			}
			renderProgress(payload);
			await saveWizardState();
			startPolling(state.migrationId);
		} finally {
			runtime.migrationRunning = false;
			updateNavigationState();
		}
	};

	const resetWizard = async () => {
		stopPolling();
		runtime.migrationRunning = false;
		runtime.discoveryLoaded = false;
		runtime.validatedMigrationUrl = '';

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

		if (refs.progressTakeover) {
			refs.progressTakeover.hidden = true;
		}

		if (refs.progressBanner) {
			refs.progressBanner.hidden = true;
		}

		toggleProgressMinimized(false);
		root.classList.remove('is-progress-minimized');
		hideResult();
		await clearWizardState();
		await setStep(1, { skipSave: true });
	};

	const handleCancel = async () => {
		if (state.currentStep === 4 && state.migrationId) {
			try {
				await post(ACTION_CANCEL_MIGRATION, {
					migration_id: state.migrationId,
				});
				showToast('Migration cancellation requested.', 'info');
			} catch (error) {
				showToast(error?.message || 'Unable to cancel migration.', 'error');
			}
		}

		await resetWizard();
	};

	const openLogsTab = () => {
		const logsTab = document.querySelector('[data-efs-tab="logs"]');
		logsTab?.click();
		document.querySelector('#efs-tab-logs')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
		refs.retryButton?.addEventListener('click', async () => {
			refs.retryButton.hidden = true;
			try {
				await startMigration();
			} catch (error) {
				const message = error?.message || 'Unable to restart migration.';
				showResult('error', message);
				refs.retryButton.hidden = false;
			}
		});

		refs.minimizeButton?.addEventListener('click', () => toggleProgressMinimized(true));
		refs.expandButton?.addEventListener('click', () => toggleProgressMinimized(false));

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

		if (!migrationId || completed || status === 'completed') {
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
			const isRunning = pollStatus === 'running' || pollStatus === 'receiving';
			if (payload?.completed || pollStatus === 'completed' || pollStatus === 'error' || !isRunning) {
				return false;
			}

			state.migrationId = payload?.migrationId || migrationId;
			await setStep(4, { skipSave: true });
			if (refs.progressTakeover) {
				refs.progressTakeover.hidden = false;
			}
			renderProgress(payload);
			startPolling(state.migrationId);
			return true;
		} catch (error) {
			return false;
		}
	};

	const init = async () => {
		bindEvents();
		await restoreState();

		const resumed = await autoResumeMigration();
		if (!resumed) {
			if (state.currentStep >= 4) {
				state.currentStep = hasValidStep2Selection() ? 3 : 1;
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
