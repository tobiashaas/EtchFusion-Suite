import { getInitialData } from './api.js';

const TOAST_VISIBLE_CLASS = 'show';
const TOAST_CONTAINER_CLASS = 'efs-toast-container';

const ensureToastContainer = () => {
    let container = document.querySelector(`.${TOAST_CONTAINER_CLASS}`);
    if (!container) {
        container = document.createElement('div');
        container.className = TOAST_CONTAINER_CLASS;
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('role', 'status');
        document.body.appendChild(container);
    }
    return container;
};

const fallbackCopy = (value) => {
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.left = '-9999px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);

    const selection = document.getSelection();
    const previousRange = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    let success = false;
    try {
        success = document.execCommand('copy');
    } catch (error) {
        success = false;
    }

    document.body.removeChild(textarea);

    if (previousRange) {
        selection.removeAllRanges();
        selection.addRange(previousRange);
    } else {
        selection?.removeAllRanges?.();
    }

    return success;
};

export const showToast = (message, type = 'info', { duration = 4000 } = {}) => {
    if (!message) {
        return null;
    }
    const container = ensureToastContainer();
    const toast = document.createElement('div');
    toast.className = 'efs-toast';
    toast.classList.add(type);
    toast.textContent = message;
    container.appendChild(toast);

    window.requestAnimationFrame(() => {
        toast.classList.add(TOAST_VISIBLE_CLASS);
    });

    const hide = () => {
        toast.classList.remove(TOAST_VISIBLE_CLASS);
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    };

    if (duration > 0) {
        window.setTimeout(hide, duration);
    }

    toast.addEventListener('click', hide, { once: true });
    return toast;
};

const bindCopyButtons = () => {
    document.querySelectorAll('[data-efs-copy-button], [data-efs-copy]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            const selector = button.getAttribute('data-efs-target') || button.getAttribute('data-efs-copy');
            const successMessage = button.getAttribute('data-toast-success') || 'Copied to clipboard.';
            if (!selector) {
                return;
            }
            console.debug('[EFS] Copy button clicked', { selector });
            const target = document.querySelector(selector);
            if (!target) {
                console.warn('[EFS] Copy target not found', { selector });
                return;
            }
            const value = target.value || target.textContent || '';
            try {
                try {
                    if (navigator?.clipboard?.writeText) {
                        await navigator.clipboard.writeText(value);
                        console.debug('[EFS] Clipboard API copy succeeded');
                        showToast(successMessage, 'success');
                        return;
                    }
                } catch (error) {
                    console.error('Clipboard copy failed', error);
                }

                const fallbackSuccess = fallbackCopy(value);
                if (fallbackSuccess) {
                    console.debug('[EFS] Fallback copy succeeded');
                    showToast(successMessage, 'success');
                } else {
                    console.warn('[EFS] Fallback copy failed');
                    showToast('Unable to copy to clipboard.', 'error');
                }
            } catch (error) {
                console.error('Clipboard copy failed', error);
                showToast('Unable to copy to clipboard.', 'error');
            }
        });
    });
};

const normalizeSteps = (steps = []) => {
    if (!steps) {
        return [];
    }
    if (Array.isArray(steps)) {
        return steps;
    }
    if (typeof steps === 'object') {
        return Object.entries(steps).map(([slug, data]) => ({
            slug,
            label: data?.label || data?.name || slug.replace(/_/g, ' '),
            ...data,
        }));
    }
    return [];
};

export const setLoading = (element, isLoading) => {
    if (!element) {
        return;
    }
    element.toggleAttribute('aria-busy', Boolean(isLoading));
    element.disabled = Boolean(isLoading);
};

export const updateProgress = ({ percentage = 0, status = '', steps = [] }) => {
    const progressRoot = document.querySelector('[data-efs-progress]');
    const progressFill = progressRoot?.querySelector('.efs-progress-fill');
    const currentStep = document.querySelector('[data-efs-current-step]');
    const stepsList = document.querySelector('[data-efs-steps]');

    progressRoot?.setAttribute('aria-valuenow', String(percentage));
    if (progressFill) {
        progressFill.style.width = `${percentage}%`;
    }
    if (currentStep) {
        currentStep.textContent = status || '';
    }
    if (stepsList) {
        stepsList.innerHTML = '';
        const normalizedSteps = normalizeSteps(steps);
        normalizedSteps.forEach((step) => {
            const li = document.createElement('li');
            li.className = 'efs-migration-step';
            if (step.completed) {
                li.classList.add('is-complete');
            }
            if (step.active) {
                li.classList.add('is-active');
            }
            li.textContent = step.label || step.slug || '';
            stepsList.appendChild(li);
        });
    }
};

const syncInitialProgress = () => {
    const progress = getInitialData('progress_data');
    if (!progress) {
        return;
    }
    updateProgress({
        percentage: progress.percentage || 0,
        status: progress.current_step || progress.status || '',
        steps: progress.steps || [],
    });
};

export const initUI = () => {
    bindCopyButtons();
    syncInitialProgress();
};
