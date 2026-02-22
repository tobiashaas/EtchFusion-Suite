import { post, getInitialData } from './api.js';
import { showToast } from './ui.js';

const ACTION_FETCH_LOGS = 'efs_get_logs';
const ACTION_CLEAR_LOGS = 'efs_clear_logs';

let refreshTimer = null;
let currentFilter = 'all';
let allSecurityLogs = [];
let allMigrationRuns = [];
let pendingHashMigrationId = '';

const formatTimestamp = (timestamp) => {
    if (!timestamp) {
        return '';
    }
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }
    return date.toLocaleString();
};

const formatDuration = (seconds) => {
    if (!seconds || seconds < 0) {
        return '00:00';
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
};

const formatContextValue = (value) => {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'object') {
        try {
            return JSON.stringify(value);
        } catch (error) {
            return String(value);
        }
    }
    return String(value);
};

const renderLogs = (logs = []) => {
    const container = document.querySelector('[data-efs-logs-list]');
    if (!container) {
        return;
    }
    // Remove existing security log articles (keep migration run articles).
    Array.from(container.querySelectorAll('article.efs-log-entry:not([data-migration-id])')).forEach((el) => el.remove());
    // Remove empty-state paragraph if present.
    const emptyState = container.querySelector('.efs-empty-state');
    if (emptyState) {
        emptyState.remove();
    }

    if (!Array.isArray(logs) || logs.length === 0) {
        if (!container.querySelector('article.efs-log-entry')) {
            const empty = document.createElement('p');
            empty.className = 'efs-empty-state';
            empty.textContent = 'No logs yet. Migration activity will appear here.';
            container.appendChild(empty);
        }
        return;
    }

    logs.forEach((log) => {
        const severity = log.severity || 'info';
        const article = document.createElement('article');
        article.className = `efs-log-entry efs-log-entry--${severity}`;

        const header = document.createElement('header');
        header.className = 'efs-log-entry__header';

        const meta = document.createElement('div');
        meta.className = 'efs-log-entry__meta';

        if (log.timestamp) {
            const time = document.createElement('time');
            time.dateTime = log.timestamp;
            time.textContent = formatTimestamp(log.timestamp);
            meta.appendChild(time);
        }

        if (log.event_type || log.code) {
            const code = document.createElement('span');
            code.className = 'efs-log-entry__code';
            code.textContent = log.event_type || log.code;
            meta.appendChild(code);
        }

        header.appendChild(meta);

        const badge = document.createElement('span');
        badge.className = `efs-log-entry__badge efs-log-entry__badge--${severity}`;
        badge.textContent = (severity.charAt(0).toUpperCase() + severity.slice(1)).replace(/_/g, ' ');
        header.appendChild(badge);

        article.appendChild(header);

        if (log.message) {
            const message = document.createElement('p');
            message.className = 'efs-log-entry__message';
            message.textContent = log.message;
            article.appendChild(message);
        }

        if (log.context && typeof log.context === 'object' && Object.keys(log.context).length > 0) {
            const contextList = document.createElement('dl');
            contextList.className = 'efs-log-entry__context';

            Object.entries(log.context).forEach(([key, value]) => {
                const item = document.createElement('div');
                item.className = 'efs-log-entry__context-item';

                const term = document.createElement('dt');
                term.textContent = key;

                const description = document.createElement('dd');
                description.textContent = formatContextValue(value);

                item.appendChild(term);
                item.appendChild(description);
                contextList.appendChild(item);
            });

            article.appendChild(contextList);
        }

        container.appendChild(article);
    });
};

const renderMigrationRuns = (runs = []) => {
    const container = document.querySelector('[data-efs-logs-list]');
    if (!container) {
        return;
    }
    // Remove existing migration run articles.
    Array.from(container.querySelectorAll('article.efs-log-entry[data-migration-id]')).forEach((el) => el.remove());

    if (!Array.isArray(runs) || runs.length === 0) {
        return;
    }

    const statusMap = {
        success: { cls: 'success', label: 'Success' },
        success_with_warnings: { cls: 'warning', label: 'Success with warnings' },
        failed: { cls: 'error', label: 'Failed' },
    };

    runs.forEach((run) => {
        const statusInfo = statusMap[run.status] || { cls: 'info', label: run.status || 'Unknown' };

        const article = document.createElement('article');
        article.className = 'efs-log-entry efs-log-entry--migration';
        article.dataset.migrationId = run.migrationId || '';

        // Header row.
        const header = document.createElement('header');
        header.className = 'efs-log-entry__header';

        const meta = document.createElement('div');
        meta.className = 'efs-log-entry__meta';

        if (run.timestamp_started_at) {
            const time = document.createElement('time');
            time.dateTime = run.timestamp_started_at;
            time.textContent = formatTimestamp(run.timestamp_started_at);
            meta.appendChild(time);
        }

        if (run.target_url || run.source_site) {
            const site = document.createElement('span');
            site.className = 'efs-log-entry__code';
            site.textContent = run.target_url || run.source_site;
            meta.appendChild(site);
        }

        header.appendChild(meta);

        const badge = document.createElement('span');
        badge.className = `efs-log-entry__badge efs-log-entry__badge--${statusInfo.cls}`;
        badge.textContent = statusInfo.label;
        header.appendChild(badge);

        article.appendChild(header);

        // Counts row.
        if (run.counts_by_post_type && typeof run.counts_by_post_type === 'object') {
            const byTypeEntries = Object.entries(run.counts_by_post_type).filter(([postType]) => (
                postType !== 'total' && postType !== 'migrated'
            ));

            const counts = document.createElement('p');
            counts.className = 'efs-log-entry__counts';
            if (byTypeEntries.length > 0) {
                const parts = byTypeEntries.map(([postType, value]) => {
                    if (value && typeof value === 'object') {
                        const total = Number(value.total ?? value.items_total ?? 0);
                        const migrated = Number(value.migrated ?? value.items_processed ?? 0);
                        return `${postType}: ${migrated} / ${total}`;
                    }

                    const numericValue = Number(value);
                    return `${postType}: ${Number.isFinite(numericValue) ? numericValue : 0}`;
                });
                counts.textContent = parts.join(' | ');
            } else {
                const total = run.counts_by_post_type.total ?? 0;
                const migrated = run.counts_by_post_type.migrated ?? 0;
                counts.textContent = `${migrated} / ${total} items migrated`;
            }
            article.appendChild(counts);
        }

        // Duration.
        if (run.duration_sec != null) {
            const dur = document.createElement('p');
            dur.className = 'efs-log-entry__duration';
            dur.textContent = `Duration: ${formatDuration(run.duration_sec)}`;
            article.appendChild(dur);
        }

        // Expandable details.
        const hasDetails = run.post_type_mappings || run.errors_summary || run.warnings_summary;
        if (hasDetails) {
            const detailsToggle = document.createElement('button');
            detailsToggle.type = 'button';
            detailsToggle.className = 'efs-logs-filter__btn efs-log-entry__details-toggle';
            detailsToggle.textContent = 'Details';

            const details = document.createElement('div');
            details.className = 'efs-log-entry__details';

            if (run.post_type_mappings && typeof run.post_type_mappings === 'object' && Object.keys(run.post_type_mappings).length > 0) {
                const mappingsLabel = document.createElement('p');
                mappingsLabel.className = 'efs-log-entry__mappings-label';
                mappingsLabel.textContent = 'Post type mappings:';
                details.appendChild(mappingsLabel);

                const mappingsList = document.createElement('dl');
                mappingsList.className = 'efs-log-entry__mappings';
                Object.entries(run.post_type_mappings).forEach(([src, tgt]) => {
                    const item = document.createElement('div');
                    item.className = 'efs-log-entry__context-item';
                    const dt = document.createElement('dt');
                    dt.textContent = src;
                    const dd = document.createElement('dd');
                    dd.textContent = `→ ${tgt}`;
                    item.appendChild(dt);
                    item.appendChild(dd);
                    mappingsList.appendChild(item);
                });
                details.appendChild(mappingsList);
            }

            if (run.target_url) {
                const targetP = document.createElement('p');
                targetP.className = 'efs-log-entry__target-url';
                targetP.textContent = `Target: ${run.target_url}`;
                details.appendChild(targetP);
            }

            if (run.source_site) {
                const sourceP = document.createElement('p');
                sourceP.className = 'efs-log-entry__source-site';
                sourceP.textContent = `Source: ${run.source_site}`;
                details.appendChild(sourceP);
            }

            if (run.errors_summary) {
                const errP = document.createElement('p');
                errP.className = 'efs-log-entry__errors-summary';
                errP.textContent = `Errors: ${run.errors_summary}`;
                details.appendChild(errP);
            }

            if (run.warnings_summary) {
                const warnP = document.createElement('p');
                warnP.className = 'efs-log-entry__warnings-summary';
                warnP.textContent = `Warnings: ${run.warnings_summary}`;
                details.appendChild(warnP);
            }

            detailsToggle.addEventListener('click', () => {
                const isOpen = details.classList.toggle('is-open');
                detailsToggle.textContent = isOpen ? 'Hide details' : 'Details';
            });

            article.appendChild(detailsToggle);
            article.appendChild(details);
        }

        container.appendChild(article);
    });
};

const applyFilter = () => {
    const container = document.querySelector('[data-efs-logs-list]');
    if (!container) {
        return;
    }
    const allEntries = container.querySelectorAll('article.efs-log-entry');
    allEntries.forEach((entry) => {
        const isMigration = entry.hasAttribute('data-migration-id');
        if (currentFilter === 'all') {
            entry.hidden = false;
        } else if (currentFilter === 'migration') {
            entry.hidden = !isMigration;
        } else if (currentFilter === 'security') {
            entry.hidden = isMigration;
        }
    });
};

const renderAll = () => {
    renderMigrationRuns(allMigrationRuns);
    renderLogs(allSecurityLogs);
    applyFilter();
};

const setFilterButton = (filterValue) => {
    const filterContainer = document.querySelector('[data-efs-logs-filter]');
    if (!filterContainer) {
        return;
    }

    filterContainer.querySelectorAll('[data-efs-filter]').forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.efsFilter === filterValue);
    });
};

const highlightMigrationFromHash = () => {
    if (!pendingHashMigrationId) {
        return;
    }

    currentFilter = 'migration';
    setFilterButton('migration');
    applyFilter();

    window.requestAnimationFrame(() => {
        const target = document.querySelector(`article[data-migration-id="${CSS.escape(pendingHashMigrationId)}"]`);
        if (!target) {
            return;
        }

        target.classList.add('is-highlighted');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        pendingHashMigrationId = '';
    });
};

const fetchLogs = async () => {
    try {
        const data = await post(ACTION_FETCH_LOGS);
        allSecurityLogs = data?.security_logs || [];
        allMigrationRuns = data?.migration_runs || [];
        renderAll();
        highlightMigrationFromHash();
    } catch (error) {
        console.error('Fetch logs failed', error);
        showToast(error.message, 'error');
    }
};

const clearLogs = async () => {
    try {
        const data = await post(ACTION_CLEAR_LOGS);
        showToast(data?.message || 'Logs cleared.', 'success');
        allSecurityLogs = [];
        allMigrationRuns = [];
        stopAutoRefreshLogs();
        renderAll();
    } catch (error) {
        console.error('Clear logs failed', error);
        showToast(error.message, 'error');
    }
};

export const startAutoRefreshLogs = (intervalMs = 5000) => {
    stopAutoRefreshLogs();
    refreshTimer = window.setInterval(fetchLogs, intervalMs);
};

export const stopAutoRefreshLogs = () => {
    if (refreshTimer) {
        window.clearInterval(refreshTimer);
        refreshTimer = null;
    }
};

const bindLogControls = () => {
    const clearButton = document.querySelector('[data-efs-clear-logs]');
    clearButton?.addEventListener('click', clearLogs);

    const filterContainer = document.querySelector('[data-efs-logs-filter]');
    if (filterContainer) {
        filterContainer.querySelectorAll('[data-efs-filter]').forEach((btn) => {
            btn.addEventListener('click', () => {
                currentFilter = btn.dataset.efsFilter || 'all';
                filterContainer.querySelectorAll('[data-efs-filter]').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                applyFilter();
            });
        });
    }
};

const hydrateInitialLogs = () => {
    allSecurityLogs = getInitialData('logs', []);
    allMigrationRuns = getInitialData('migration_runs', []);
    renderAll();
};

export const initLogs = () => {
    hydrateInitialLogs();
    bindLogControls();

    // Hash-based deep-link: #migration-{uuid} scrolls to and highlights a run entry.
    const hash = window.location.hash;
    if (hash.startsWith('#migration-')) {
        pendingHashMigrationId = hash.slice('#migration-'.length);
        highlightMigrationFromHash();
    }
};
