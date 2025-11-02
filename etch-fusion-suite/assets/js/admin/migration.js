import { post, buildAjaxErrorMessage } from './api.js';
import { showToast, updateProgress, expandAccordionSection } from './ui.js';

const ACTION_START_MIGRATION = 'efs_start_migration';
const ACTION_GET_PROGRESS = 'efs_get_migration_progress';
const ACTION_PROCESS_BATCH = 'efs_migrate_batch';
const ACTION_CANCEL_MIGRATION = 'efs_cancel_migration';

let pollTimer = null;
let activeMigrationId = window.efsData?.migrationId || null;
let pollState = null;

const DEFAULT_POLL_INTERVAL_MS = 3000;
const MAX_POLL_INTERVAL_MS = 30000;
const MAX_CONSECUTIVE_POLL_FAILURES = 5;
const DEFAULT_PROGRESS_TIMEOUT_MS = 15000;

const setActiveMigrationId = (migrationId) => {
    if (!migrationId) {
        activeMigrationId = null;
        if (window.efsData) {
            delete window.efsData.migrationId;
        }
        return;
    }

    activeMigrationId = migrationId;
    window.efsData = {
        ...(window.efsData || {}),
        migrationId,
    };
};

const getActiveMigrationId = () => activeMigrationId || window.efsData?.migrationId || null;

const requestProgress = async (params = {}, requestOptions = {}) => {
    const migrationId = params?.migrationId || getActiveMigrationId();
    if (!migrationId) {
        console.warn('[EFS] No migration ID available for progress request');
        return {
            progress: { percentage: 0, status: '', current_step: '' },
            steps: [],
            migrationId: null,
            completed: false,
        };
    }

    try {
        const data = await post(ACTION_GET_PROGRESS, {
            ...params,
            migrationId,
        }, requestOptions);

        if (data?.migrationId) {
            setActiveMigrationId(data.migrationId);
        }

        const progress = data?.progress || { percentage: 0, status: '', current_step: '' };
        const steps = data?.steps || progress.steps || [];
        
        updateProgress({
            percentage: progress.percentage || 0,
            status: progress.status || progress.current_step || '',
            steps,
        });

        if (data?.completed) {
            showToast('Migration completed successfully.', 'success');
            stopProgressPolling();
            setActiveMigrationId(null);
        }

        return data;
    } catch (error) {
        console.error('[EFS] Progress request failed:', error);
        return {
            progress: { percentage: 0, status: 'error', current_step: 'error' },
            steps: [],
            migrationId,
            completed: false,
            error: buildAjaxErrorMessage(error, 'Failed to retrieve migration progress.'),
            failed: true,
        };
    }
};

export const startMigration = async (payload) => {
    expandAccordionSection('efs-accordion-start-migration');
    const tokenField = document.querySelector('#efs-migration-token');
    if (tokenField && !payload.migration_token) {
        payload.migration_token = tokenField.value || '';
    }
    const migrationForm = document.querySelector('[data-efs-migration-form]');
    const migrationSection = migrationForm?.closest('[data-efs-accordion-section]');
    const keyField = migrationSection?.querySelector('#efs-migration-key')
        || document.querySelector('#efs-migration-key');
    if (keyField && !payload.migration_key) {
        payload.migration_key = keyField.value || '';
    }

    const requestPayload = { ...payload };
    if (!requestPayload.migration_token) {
        delete requestPayload.migration_token;
    }

    const data = await post(ACTION_START_MIGRATION, requestPayload);
    if (data?.token) {
        const tokenField = document.querySelector('#efs-migration-token');
        if (tokenField) {
            tokenField.value = data.token;
        }
    }
    if (data?.migrationId) {
        setActiveMigrationId(data.migrationId);
    }

    showToast(data?.message || 'Migration started.', 'success');
    updateProgress({
        percentage: data?.progress?.percentage || 0,
        status: data?.progress?.status || '',
        steps: data?.steps || [],
    });
    startProgressPolling({ migrationId: getActiveMigrationId() });
    return data;
};

export const processBatch = async (payload) => {
    expandAccordionSection('efs-accordion-start-migration');
    const migrationId = payload?.migrationId || getActiveMigrationId();
    const data = await post(ACTION_PROCESS_BATCH, {
        ...payload,
        migrationId,
    });

    if (data?.migrationId) {
        setActiveMigrationId(data.migrationId);
    }

    const progress = data?.progress || {};
    updateProgress({
        percentage: progress.percentage || 0,
        status: progress.status || progress.current_step || '',
        steps: data?.steps || progress.steps || [],
    });
    if (data?.completed) {
        showToast('Migration completed successfully.', 'success');
        stopProgressPolling();
        setActiveMigrationId(null);
    }
    return data;
};

export const cancelMigration = async (payload) => {
    expandAccordionSection('efs-accordion-start-migration');
    const migrationId = payload?.migrationId || getActiveMigrationId();
    const data = await post(ACTION_CANCEL_MIGRATION, {
        ...payload,
        migrationId,
    });
    showToast(data?.message || 'Migration cancelled.', 'info');
    stopProgressPolling();
    setActiveMigrationId(null);
    return data;
};

export const startProgressPolling = (params = {}, options = {}) => {
    stopProgressPolling();
    const migrationId = params?.migrationId || getActiveMigrationId();
    if (!migrationId) {
        return;
    }

    const pollParams = {
        ...params,
        migrationId,
    };

    const initialInterval = options.intervalMs ?? DEFAULT_POLL_INTERVAL_MS;
    const maxInterval = options.maxIntervalMs ?? MAX_POLL_INTERVAL_MS;
    const maxFailures = options.maxFailures ?? MAX_CONSECUTIVE_POLL_FAILURES;
    const timeoutMs = options.timeoutMs ?? DEFAULT_PROGRESS_TIMEOUT_MS;

    pollState = {
        pollParams,
        intervalMs: initialInterval,
        initialInterval,
        maxInterval,
        maxFailures,
        failureCount: 0,
        timeoutMs,
        abortController: null,
    };

    const scheduleNextPoll = () => {
        if (!pollState) {
            return;
        }
        pollTimer = window.setTimeout(runPoll, pollState.intervalMs);
    };

    const runPoll = async () => {
        if (!pollState) {
            return;
        }

        if (pollState.abortController) {
            pollState.abortController.abort();
        }

        pollState.abortController = new AbortController();

        let result;

        try {
            result = await requestProgress(pollState.pollParams, {
                signal: pollState.abortController.signal,
                timeoutMs: pollState.timeoutMs,
            });
        } catch (error) {
            result = {
                error: buildAjaxErrorMessage(error, 'Failed to retrieve migration progress.'),
                failed: true,
            };
        }

        if (!pollState) {
            return;
        }

        const hasError = Boolean(result?.error || result?.failed);

        if (hasError) {
            pollState.failureCount += 1;
            pollState.intervalMs = Math.min(pollState.intervalMs * 2, pollState.maxInterval);
            console.warn(
                `[EFS] Progress polling failed (${pollState.failureCount}/${pollState.maxFailures}): ${result?.error}`,
            );

            if (pollState.failureCount >= pollState.maxFailures) {
                const message = result?.error || 'Migration progress polling failed repeatedly.';
                stopProgressPolling();
                showToast(message, 'error');
                return;
            }
        } else {
            pollState.failureCount = 0;
            pollState.intervalMs = pollState.initialInterval;
        }

        scheduleNextPoll();
    };

    runPoll();
};

export const stopProgressPolling = () => {
    if (pollTimer) {
        window.clearTimeout(pollTimer);
        pollTimer = null;
    }
    if (pollState?.abortController) {
        pollState.abortController.abort();
    }
    pollState = null;
};
