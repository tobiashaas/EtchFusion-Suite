import { initUI, showToast } from './ui.js';
import { bindSettings } from './settings.js';
import { bindValidation } from './validation.js';
import {
    startMigration,
    cancelMigration,
    startProgressPolling,
    stopProgressPolling,
} from './migration.js';
import { initLogs, startAutoRefreshLogs, stopAutoRefreshLogs } from './logs.js';
import { serializeForm } from './api.js';
import { init as initTemplateExtractor } from './template-extractor.js';

let templateExtractorInitialized = false;

const ensureTemplateExtractor = () => {
    if (!window.efsData?.framer_enabled) {
        return;
    }
    if (templateExtractorInitialized) {
        return;
    }

    const panel = document.querySelector('[data-efs-tab-panel="templates"]');
    if (!panel) {
        return;
    }

    initTemplateExtractor();
    templateExtractorInitialized = true;
};

const bindMigrationForm = () => {
    const form = document.querySelector('[data-efs-migration-form]');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = serializeForm(form);
        try {
            await startMigration(payload);
            startProgressPolling();
            startAutoRefreshLogs();
        } catch (error) {
            console.error('Start migration failed', error);
            showToast(error.message, 'error');
        }
    });

    document.querySelectorAll('[data-efs-cancel-migration]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await cancelMigration();
                stopProgressPolling();
                stopAutoRefreshLogs();
            } catch (error) {
                console.error('Cancel migration failed', error);
                showToast(error.message, 'error');
            }
        });
    });
};

const bindTabs = () => {
    const tabsRoot = document.querySelector('[data-efs-tabs]');
    if (!tabsRoot) {
        return;
    }

    const tabs = Array.from(tabsRoot.querySelectorAll('[data-efs-tab]'));
    const panels = Array.from(tabsRoot.querySelectorAll('.efs-tab__panel'));

    const isFeatureDisabled = (tab) => tab?.hasAttribute('data-efs-feature-disabled')
        || tab?.hasAttribute('aria-disabled');

    const activateTab = (targetKey) => {
        tabs.forEach((tab) => {
            const isTarget = tab.dataset.efsTab === targetKey;
            tab.classList.toggle('is-active', isTarget);
            tab.setAttribute('aria-selected', String(isTarget));
            if (isTarget) {
                tab.removeAttribute('aria-disabled');
            }
        });

        panels.forEach((panel) => {
            const isTarget = panel.id === `efs-tab-${targetKey}`;
            panel.classList.toggle('is-active', isTarget);
            panel.toggleAttribute('hidden', !isTarget);
        });

        if (targetKey === 'templates') {
            ensureTemplateExtractor();
        }
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            if (isFeatureDisabled(tab)) {
                tab.setAttribute('aria-disabled', 'true');
                const targetNotice = document.querySelector('[data-efs-feature-disabled-message]');
                if (targetNotice) {
                    targetNotice.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                showToast(
                    window.efsData?.i18n?.featureDisabled
                        || 'This feature is currently disabled. Enable it via EFS_ENABLE_FRAMER or the efs_enable_framer filter.',
                    'info',
                );
                return;
            }
            activateTab(tab.dataset.efsTab);
        });
    });

    const initialTab = tabs.find((tab) => tab.classList.contains('is-active'));
    if (initialTab) {
        activateTab(initialTab.dataset.efsTab);
    }
};

const bootstrap = () => {
    // Guard: Ensure efsData is available
    if (!window.efsData) {
        console.warn('[EFS] efsData not localized. Admin scripts may not function correctly.');
        window.efsData = {
            ajaxUrl: '',
            nonce: '',
            settings: {},
        };
        showToast(
            window.efsStrings?.localizationMissing || 'Etch Fusion data not loaded. Some features may be unavailable. Refresh and ensure plugin scripts are localized.',
            'warning'
        );
    }

    if (!window.efsData?.ajaxUrl || !window.efsData?.nonce) {
        console.warn('[EFS] efsData missing required fields.', window.efsData);
        showToast(
            window.efsStrings?.localizationInvalid || 'Etch Fusion configuration incomplete. Check your WordPress setup or refresh the page.',
            'warning'
        );
    }

    initUI();
    bindSettings();
    bindValidation();
    bindMigrationForm();
    initLogs();
    bindTabs();

    // Resume migration if in progress
    const progress = window.efsData?.progress_data || {};
    const localizedMigrationId = window.efsData?.migrationId || progress?.migrationId;
    const completed = window.efsData?.completed || progress?.completed || false;

    if (localizedMigrationId && !completed) {
        const { percentage = 0, status = '' } = progress;
        const isRunning = percentage > 0 || (status && status !== 'completed' && status !== 'error');
        
        if (isRunning) {
            console.log('[EFS] Resuming migration polling:', localizedMigrationId);
            startProgressPolling({ migrationId: localizedMigrationId });
            startAutoRefreshLogs();
        }
    }
};

document.addEventListener('DOMContentLoaded', bootstrap);
