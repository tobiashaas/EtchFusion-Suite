import { post } from './api.js';
import { setLoading, showToast } from './ui.js';

const ACTION_GENERATE_KEY = 'efs_generate_migration_key';
const DEFAULT_EXPIRY_SECONDS = 8 * 60 * 60;

const formatExpiryLabel = (expiresAt, expirationSeconds) => {
    const seconds = Number(expirationSeconds) > 0 ? Number(expirationSeconds) : DEFAULT_EXPIRY_SECONDS;
    const hours = Math.max(1, Math.round(seconds / 3600));
    const relativeLabel = `Expires in ${hours} hour${hours === 1 ? '' : 's'}.`;

    if (expiresAt) {
        return `${relativeLabel} (at ${expiresAt}).`;
    }

    return relativeLabel;
};

const fallbackCopy = (value) => {
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch (error) {
        copied = false;
    }

    textarea.remove();
    return copied;
};

export const copyToClipboard = async (value) => {
    if (!value) {
        return false;
    }

    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            return true;
        }
    } catch (error) {
        return fallbackCopy(value);
    }

    return fallbackCopy(value);
};

export const showSecurityGuidance = (elements, response = {}) => {
    const securityNote = elements?.securityNote;
    const httpsWarning = elements?.httpsWarning;

    if (securityNote) {
        const serverNote = response?.treat_as_password_note || 'Treat this key like a password.';
        securityNote.textContent = serverNote;
    }

    if (httpsWarning) {
        const warning = response?.security_warning || '';
        if (warning) {
            httpsWarning.textContent = warning;
            httpsWarning.hidden = false;
        } else {
            httpsWarning.hidden = true;
        }
    }
};

const revealGeneratedUrl = (elements, expiration = {}) => {
    if (elements?.generatedUrlWrapper) {
        elements.generatedUrlWrapper.hidden = false;
    }

    if (elements?.copyActions) {
        elements.copyActions.hidden = false;
    }

    if (elements?.expiryDisplay) {
        const nextLabel = formatExpiryLabel(expiration?.expires_at, expiration?.expiration_seconds);
        elements.expiryDisplay.textContent = nextLabel;
        elements.expiryDisplay.hidden = false;
    }

    if (elements?.generateButton) {
        const regenLabel = elements.generateButton.getAttribute('data-efs-regenerate-label') || 'Regenerate';
        elements.generateButton.textContent = regenLabel;
    }
};

export const generateMigrationUrl = async (form, elements) => {
    const button = elements?.generateButton;
    const output = elements?.urlOutput;
    const existingUrl = output?.value?.trim();

    if (existingUrl) {
        const confirmed = window.confirm('Generate a new migration key? Previous keys will be invalidated.');
        if (!confirmed) {
            return;
        }
    }

    setLoading(button, true);
    button?.classList.add('is-loading');
    try {
        const targetUrl = form.querySelector('input[name="target_url"]')?.value?.trim() || window.efsData?.site_url || '';
        const response = await post(ACTION_GENERATE_KEY, { target_url: targetUrl, context: 'etch' });
        const migrationUrl = response?.migration_url || '';

        if (!migrationUrl) {
            throw new Error('Migration key generation failed.');
        }

        if (output) {
            output.value = migrationUrl;
        }

        revealGeneratedUrl(elements, response);
        showSecurityGuidance(elements, response);

        if (response?.invalidated_previous_key) {
            showToast(response?.message || 'New key generated. Previous keys are now invalid.', 'success');
            return;
        }

        showToast(response?.message || 'Migration key generated.', 'success');
    } finally {
        setLoading(button, false);
        button?.classList.remove('is-loading');
    }
};

const bindCopyButton = (elements) => {
    const copyButton = elements?.copyButton;
    const output = elements?.urlOutput;

    if (!copyButton || !output) {
        return;
    }

    copyButton.addEventListener('click', async () => {
        const copied = await copyToClipboard(output.value);
        if (!copied) {
            showToast('Unable to copy key. Copy it manually.', 'error');
            return;
        }

        const originalLabel = copyButton.textContent;
        copyButton.textContent = 'Copied';
        showToast('Key copied to clipboard.', 'success');
        window.setTimeout(() => {
            copyButton.textContent = originalLabel;
        }, 1200);
    });
};

export const initEtchDashboard = () => {
    const root = document.querySelector('[data-efs-etch-dashboard]');
    if (!root) {
        return;
    }

    const form = root.querySelector('[data-efs-etch-generate-url]');
    if (!form) {
        return;
    }

    const elements = {
        generateButton: root.querySelector('[data-efs-generate-migration-url]'),
        generatedUrlWrapper: root.querySelector('[data-efs-generated-url-wrapper]'),
        copyActions: root.querySelector('[data-efs-copy-url-actions]'),
        copyButton: root.querySelector('[data-efs-copy-migration-url]'),
        urlOutput: root.querySelector('[data-efs-generated-migration-url]'),
        securityNote: root.querySelector('[data-efs-security-note]'),
        httpsWarning: root.querySelector('[data-efs-https-warning]'),
        expiryDisplay: root.querySelector('[data-efs-expiry-display]'),
    };

    bindCopyButton(elements);

    if (elements.urlOutput?.value?.trim()) {
        revealGeneratedUrl(elements);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await generateMigrationUrl(form, elements);
        } catch (error) {
            showToast(error?.message || 'Unable to generate migration key.', 'error');
        }
    });
};
