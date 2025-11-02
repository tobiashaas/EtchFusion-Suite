import { getInitialData } from './api.js';

const TOAST_VISIBLE_CLASS = 'show';
const TOAST_CONTAINER_CLASS = 'efs-toast-container';
const ACCORDION_SELECTOR = '[data-efs-accordion]';
const ACCORDION_SECTION_SELECTOR = '[data-efs-accordion-section]';
const ACCORDION_HEADER_SELECTOR = '[data-efs-accordion-header]';
const ACCORDION_CONTENT_SELECTOR = '[data-efs-accordion-content]';

const accordionState = new WeakMap();

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

const getAccordionToggleOptions = (accordion) => ({
    allowMultiple: accordion?.hasAttribute('data-efs-accordion-multi'),
});

const setSectionExpanded = (section, expanded) => {
    if (!section) {
        return;
    }

    const header = section.querySelector(ACCORDION_HEADER_SELECTOR);
    const content = section.querySelector(ACCORDION_CONTENT_SELECTOR);

    if (!header || !content) {
        return;
    }

    section.classList.toggle('is-expanded', expanded);
    header.setAttribute('aria-expanded', String(expanded));

    if (expanded) {
        content.removeAttribute('hidden');
        const height = content.scrollHeight;
        content.style.setProperty('--efs-accordion-content-height', `${height}px`);
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            content.setAttribute('data-efs-accordion-state', 'expanding');
            window.requestAnimationFrame(() => {
                content.style.setProperty('--efs-accordion-content-height', `${content.scrollHeight}px`);
            });
            const onTransitionEnd = (event) => {
                if (event.propertyName === 'max-height') {
                    content.removeAttribute('data-efs-accordion-state');
                    content.removeEventListener('transitionend', onTransitionEnd);
                    content.style.setProperty('--efs-accordion-content-height', 'none');
                }
            };
            content.addEventListener('transitionend', onTransitionEnd);
        } else {
            content.style.setProperty('--efs-accordion-content-height', 'none');
        }
    } else {
        const height = content.scrollHeight;
        content.style.setProperty('--efs-accordion-content-height', `${height}px`);
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            window.requestAnimationFrame(() => {
                content.style.setProperty('--efs-accordion-content-height', '0px');
            });
            const onTransitionEnd = (event) => {
                if (event.propertyName === 'max-height') {
                    content.setAttribute('hidden', 'true');
                    content.removeEventListener('transitionend', onTransitionEnd);
                    content.style.setProperty('--efs-accordion-content-height', '0px');
                }
            };
            content.addEventListener('transitionend', onTransitionEnd);
        } else {
            content.setAttribute('hidden', 'true');
            content.style.setProperty('--efs-accordion-content-height', '0px');
        }
    }
};

const collapseSection = (section) => setSectionExpanded(section, false);

const expandSection = (section, accordion) => {
    if (!section) {
        return;
    }

    const { allowMultiple } = getAccordionToggleOptions(accordion);
    if (!allowMultiple) {
        accordion
            ?.querySelectorAll(`${ACCORDION_SECTION_SELECTOR}.is-expanded`)
            .forEach((openSection) => {
                if (openSection !== section) {
                    collapseSection(openSection);
                }
            });
    }

    if (section.classList.contains('is-expanded')) {
        // Already expanded; ensure content height refreshed.
        setSectionExpanded(section, true);
        return;
    }

    setSectionExpanded(section, true);
};

const toggleSection = (section, accordion) => {
    if (!section) {
        return;
    }
    const isExpanded = section.classList.contains('is-expanded');
    if (isExpanded) {
        collapseSection(section);
    } else {
        expandSection(section, accordion);
    }
};

const focusAdjacentHeader = (headers, currentIndex, direction) => {
    if (!headers.length) {
        return;
    }
    const nextIndex = (currentIndex + direction + headers.length) % headers.length;
    headers[nextIndex]?.focus();
};

export const expandAccordionSection = (identifier) => {
    if (!identifier) {
        return null;
    }

    let targetSection = document.getElementById(identifier)?.closest(ACCORDION_SECTION_SELECTOR);

    if (!targetSection) {
        targetSection = document.querySelector(
            `${ACCORDION_SECTION_SELECTOR}[data-section="${identifier}"]`,
        );
    }

    if (!targetSection) {
        return null;
    }

    const accordion = targetSection.closest(ACCORDION_SELECTOR);
    expandSection(targetSection, accordion);
    return targetSection;
};

const getScrollOffset = () => {
    const adminBar = document.querySelector('#wpadminbar');
    return (adminBar?.offsetHeight || 0) + 24;
};

export const scrollToAccordionSection = (identifier, { focus = true } = {}) => {
    const section = expandAccordionSection(identifier);
    if (!section) {
        return;
    }

    const offset = getScrollOffset();
    const top = section.getBoundingClientRect().top + window.scrollY - offset;

    window.scrollTo({
        top: Math.max(top, 0),
        behavior: 'smooth',
    });

    if (focus) {
        const header = section.querySelector(ACCORDION_HEADER_SELECTOR);
        if (header) {
            window.setTimeout(() => {
                header.focus({ preventScroll: true });
            }, 250);
        }
    }
};

export const initAccordion = () => {
    const accordions = document.querySelectorAll(ACCORDION_SELECTOR);
    accordions.forEach((accordion) => {
        const sections = Array.from(accordion.querySelectorAll(ACCORDION_SECTION_SELECTOR));
        if (!sections.length) {
            return;
        }

        sections.forEach((section, index) => {
            if (accordionState.has(section)) {
                return;
            }

            const header = section.querySelector(ACCORDION_HEADER_SELECTOR);
            const content = section.querySelector(ACCORDION_CONTENT_SELECTOR);

            if (!header || !content) {
                return;
            }

            const isExpanded = section.classList.contains('is-expanded');
            if (!isExpanded) {
                content.setAttribute('hidden', 'true');
            } else {
                content.removeAttribute('hidden');
                content.style.setProperty('--efs-accordion-content-height', `${content.scrollHeight}px`);
            }

            header.setAttribute('aria-expanded', String(isExpanded));
            accordionState.set(section, { accordion });

            header.addEventListener('click', () => toggleSection(section, accordion));
            header.addEventListener('keydown', (event) => {
                switch (event.key) {
                    case 'Enter':
                    case ' ':
                        event.preventDefault();
                        toggleSection(section, accordion);
                        break;
                    case 'ArrowUp':
                    case 'ArrowLeft': {
                        event.preventDefault();
                        focusAdjacentHeader(
                            sections.map((sectionItem) =>
                                sectionItem.querySelector(ACCORDION_HEADER_SELECTOR),
                            ),
                            index,
                            -1,
                        );
                        break;
                    }
                    case 'ArrowDown':
                    case 'ArrowRight': {
                        event.preventDefault();
                        focusAdjacentHeader(
                            sections.map((sectionItem) =>
                                sectionItem.querySelector(ACCORDION_HEADER_SELECTOR),
                            ),
                            index,
                            1,
                        );
                        break;
                    }
                    case 'Home': {
                        event.preventDefault();
                        const headers = sections.map((sectionItem) =>
                            sectionItem.querySelector(ACCORDION_HEADER_SELECTOR),
                        );
                        headers[0]?.focus();
                        break;
                    }
                    case 'End': {
                        event.preventDefault();
                        const headers = sections.map((sectionItem) =>
                            sectionItem.querySelector(ACCORDION_HEADER_SELECTOR),
                        );
                        headers[headers.length - 1]?.focus();
                        break;
                    }
                    default:
                        break;
                }
            });
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
    initAccordion();
    bindCopyButtons();
    syncInitialProgress();
};
