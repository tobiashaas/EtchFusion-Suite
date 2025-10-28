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

    const activateTab = (targetKey) => {
        tabs.forEach((tab) => {
            const isTarget = tab.dataset.efsTab === targetKey;
            tab.classList.toggle('is-active', isTarget);
            tab.setAttribute('aria-selected', String(isTarget));
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
        return;
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
