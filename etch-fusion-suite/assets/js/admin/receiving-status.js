import { post } from './api.js';

const ACTION_GET_RECEIVING_STATUS = 'efs_get_receiving_status';
const POLL_INTERVAL_MS = 3000;
const DISMISSIBLE_STATUSES = new Set(['receiving', 'completed', 'stale']);
const STORAGE_KEY_DISMISSED = 'efsReceivingDismissedKeys';

const SOURCE_FALLBACK = 'Unknown source';
const PHASE_FALLBACK = 'Initializing';
const ACTIVITY_FALLBACK = 'Not yet available';

const extractHost = (value) => {
    if (!value) {
        return SOURCE_FALLBACK;
    }

    try {
        const parsed = new URL(value);
        return parsed.host || value;
    } catch (error) {
        return value;
    }
};

const formatStatusCopy = (status, phase, isStale) => {
    if (status === 'completed') {
        return 'Migration payload received successfully. You can now review imported content.';
    }

    if (status === 'stale' || isStale) {
        return 'No new payloads detected recently. Confirm the source migration is still running.';
    }

    return `Receiving migration payloads (${phase || PHASE_FALLBACK}).`;
};

const formatTitle = (status, isStale) => {
    if (status === 'completed') {
        return 'Migration Received';
    }

    if (status === 'stale' || isStale) {
        return 'Migration Stalled';
    }

    return 'Receiving Migration';
};

const formatSubtitle = (status, isStale) => {
    if (status === 'completed') {
        return 'Incoming migration completed on this Etch site.';
    }

    if (status === 'stale' || isStale) {
        return 'Receiving updates paused. Check the source site and retry if needed.';
    }

    return 'Incoming data from the source site is being processed.';
};

const setRootStateClasses = (root, state) => {
    root.classList.toggle('is-receiving-completed', state === 'completed');
    root.classList.toggle('is-receiving-stale', state === 'stale');
};

const normalizeStatus = (response = {}) => {
    const rawStatus = String(response?.status || 'idle').toLowerCase();
    const stale = Boolean(response?.is_stale);

    if (rawStatus === 'stale' || stale) {
        return 'stale';
    }

    if (rawStatus === 'completed') {
        return 'completed';
    }

    if (rawStatus === 'receiving') {
        return 'receiving';
    }

    return 'idle';
};

const createUiModel = (payload = {}) => {
    const status = normalizeStatus(payload);
    const sourceRaw = String(payload?.source_site || '').trim();
    const source = extractHost(sourceRaw);
    const phase = String(payload?.current_phase || '').trim() || PHASE_FALLBACK;
    const items = Number(payload?.items_received) || 0;
    const lastActivityRaw = String(payload?.last_activity || '').trim();
    const lastActivity = lastActivityRaw || ACTIVITY_FALLBACK;
    const migrationId = String(payload?.migration_id || '').trim();
    const hasSignal = migrationId !== '' || sourceRaw !== '' || items > 0 || lastActivityRaw !== '';

    return {
        status,
        source,
        phase,
        items,
        lastActivity,
        migrationId,
        sourceRaw,
        lastActivityRaw,
        hasSignal,
        isStale: status === 'stale',
        title: formatTitle(status, status === 'stale'),
        subtitle: formatSubtitle(status, status === 'stale'),
        statusCopy: formatStatusCopy(status, phase, status === 'stale'),
    };
};

export const initReceivingStatus = () => {
    const root = document.querySelector('[data-efs-etch-dashboard]');
    const takeover = root?.querySelector('[data-efs-receiving-display]');
    const banner = root?.querySelector('[data-efs-receiving-banner]');
    if (!root || !takeover || !banner) {
        return;
    }

    const elements = {
        title: root.querySelector('[data-efs-receiving-title]'),
        subtitle: root.querySelector('[data-efs-receiving-subtitle]'),
        source: root.querySelector('[data-efs-receiving-source]'),
        phase: root.querySelector('[data-efs-receiving-phase]'),
        items: root.querySelector('[data-efs-receiving-items]'),
        lastActivity: root.querySelector('[data-efs-receiving-last-activity]'),
        status: root.querySelector('[data-efs-receiving-status]'),
        bannerText: root.querySelector('[data-efs-receiving-banner-text]'),
        minimize: root.querySelector('[data-efs-receiving-minimize]'),
        expand: root.querySelector('[data-efs-receiving-expand]'),
        dismiss: root.querySelector('[data-efs-receiving-dismiss]'),
        viewReceivedContent: root.querySelector('[data-efs-view-received-content]'),
    };

    let inFlight = false;
    let collapsed = true;
    let hasAutoExpanded = false;
    let currentModel = createUiModel();

    const readDismissedKeys = () => {
        try {
            const raw = window.sessionStorage.getItem(STORAGE_KEY_DISMISSED);
            if (!raw) {
                return new Set();
            }
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? new Set(parsed.filter(Boolean)) : new Set();
        } catch (error) {
            return new Set();
        }
    };

    const writeDismissedKeys = (keys) => {
        try {
            window.sessionStorage.setItem(STORAGE_KEY_DISMISSED, JSON.stringify(Array.from(keys)));
        } catch (error) {
            // Ignore storage failures; UI should still work in-memory.
        }
    };

    const dismissedKeys = readDismissedKeys();

    const getDismissKey = (model) => {
        if (model.migrationId) {
            return `migration:${model.migrationId}`;
        }
        if (model.sourceRaw) {
            return `source:${model.sourceRaw}`;
        }
        return '';
    };

    const render = (model) => {
        currentModel = model;
        const dismissKey = getDismissKey(model);
        const isVisibleState = model.status !== 'idle' && model.hasSignal;
        const isDismissed = dismissKey !== '' && dismissedKeys.has(dismissKey);
        const visualState = (!isVisibleState || isDismissed) ? 'idle' : model.status;
        setRootStateClasses(root, visualState);

        if (elements.title) {
            elements.title.textContent = model.title;
        }
        if (elements.subtitle) {
            elements.subtitle.textContent = model.subtitle;
        }
        if (elements.source) {
            elements.source.textContent = model.source;
        }
        if (elements.phase) {
            const phaseBadge = elements.phase.querySelector('.status-badge');
            if (phaseBadge) {
                phaseBadge.classList.toggle('is-active', model.status === 'receiving' || model.status === 'completed');
                phaseBadge.classList.toggle('is-warning', model.status === 'stale');
                phaseBadge.classList.toggle('is-error', model.status === 'idle');

                const textNode = Array.from(phaseBadge.childNodes).find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '');
                if (textNode) {
                    textNode.textContent = ` ${model.phase}`;
                } else {
                    phaseBadge.append(document.createTextNode(` ${model.phase}`));
                }
            } else {
                elements.phase.textContent = model.phase;
            }
        }
        if (elements.items) {
            elements.items.textContent = `${model.items}`;
        }
        if (elements.lastActivity) {
            elements.lastActivity.textContent = model.lastActivity;
        }
        if (elements.status) {
            elements.status.textContent = model.statusCopy;
        }
        if (elements.bannerText) {
            elements.bannerText.textContent = `${model.title}: ${model.source}`;
        }

        if (elements.viewReceivedContent) {
            elements.viewReceivedContent.hidden = model.status !== 'completed';
        }
        if (elements.dismiss) {
            elements.dismiss.hidden = !isVisibleState || !DISMISSIBLE_STATUSES.has(model.status);
        }

        if (!isVisibleState || isDismissed) {
            takeover.hidden = true;
            banner.hidden = true;
            return;
        }

        // Auto-expand on first detection
        if (!hasAutoExpanded && isVisibleState) {
            collapsed = false;
            hasAutoExpanded = true;
        }

        takeover.hidden = collapsed;
        banner.hidden = !collapsed;
    };

    const schedulePoll = () => {
        window.setTimeout(runPoll, POLL_INTERVAL_MS);
    };

    const runPoll = async () => {
        if (inFlight) {
            schedulePoll();
            return;
        }

        inFlight = true;
        try {
            const payload = await post(ACTION_GET_RECEIVING_STATUS);
            const model = createUiModel(payload);
            render(model);
        } catch (error) {
            console.warn('[EFS] Receiving status polling failed.', error);
        } finally {
            inFlight = false;
            schedulePoll();
        }
    };

    elements.minimize?.addEventListener('click', () => {
        collapsed = true;
        render(currentModel);
    });

    elements.expand?.addEventListener('click', () => {
        collapsed = false;
        render(currentModel);
    });

    elements.dismiss?.addEventListener('click', () => {
        if (!DISMISSIBLE_STATUSES.has(currentModel.status)) {
            return;
        }
        const dismissKey = getDismissKey(currentModel);
        if (dismissKey) {
            dismissedKeys.add(dismissKey);
            writeDismissedKeys(dismissedKeys);
        }
        setRootStateClasses(root, 'idle');
        render(currentModel);
    });

    render(currentModel);
    runPoll();
};
