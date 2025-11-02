import { post, serializeForm, getInitialData, buildAjaxErrorMessage } from './api.js';
import { showToast, setLoading } from './ui.js';

const ACTION_SAVE_SETTINGS = 'efs_save_settings';
const ACTION_TEST_CONNECTION = 'efs_test_connection';
const ACTION_GENERATE_KEY = 'efs_generate_migration_key';
const ACTION_SAVE_FEATURE_FLAGS = 'efs_save_feature_flags';

const populateSettingsForm = () => {
    const form = document.querySelector('[data-efs-settings-form]');
    const settings = getInitialData('settings', {});
    if (!form || !settings) {
        return;
    }
    Object.entries(settings).forEach(([key, value]) => {
        const field = form.querySelector(`[name="${key}"]`);
        if (field) {
            field.value = value;
        }
    });
};

const syncMigrationKeyForms = () => {
    const settings = getInitialData('settings', {});
    const settingsForm = document.querySelector('[data-efs-settings-form]');
    const formTargetUrl = settingsForm?.querySelector('input[name="target_url"]')?.value?.trim();
    const formApiKey = settingsForm?.querySelector('input[name="api_key"]')?.value?.trim();
    const siteUrl = window.efsData?.site_url || '';

    document.querySelectorAll('[data-efs-generate-key]').forEach((form) => {
        const context = form.querySelector('input[name="context"]')?.value;
        const targetField = form.querySelector('input[name="target_url"]');
        const apiKeyField = form.querySelector('input[name="api_key"]');

        if (context === 'bricks') {
            const targetUrl = formTargetUrl || settings.target_url || '';
            const apiKey = formApiKey || settings.api_key || '';
            if (targetField && targetUrl) {
                targetField.value = targetUrl;
            }
            if (apiKeyField && apiKey) {
                apiKeyField.value = apiKey;
            }
        }

        if (context === 'etch' && targetField && siteUrl) {
            targetField.value = siteUrl;
        }
    });
};

const setPinBoxesInvalidState = (form, isInvalid) => {
    if (!form) {
        return;
    }
    const boxes = form.querySelectorAll('[data-efs-pin-input] .efs-pin-input-box');
    boxes.forEach((box) => {
        box.classList.toggle('is-invalid', isInvalid);
    });
};

const handleSaveSettings = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('button[type="submit"]');
    const apiKeyInput = form.querySelector('input[name="api_key"]');
    const apiKeyValue = (apiKeyInput?.value ?? '').trim();

    if (apiKeyInput) {
        const isValidApiKey = apiKeyValue.length === 24;
        setPinBoxesInvalidState(form, !isValidApiKey);
        if (!isValidApiKey) {
            showToast(
                window.efsData?.i18n?.invalidPinPaste ?? 'Application passwords must be 24 characters.',
                'error',
            );
            return;
        }
    }

    setLoading(submitButton, true);
    try {
        const payload = serializeForm(form);
        const data = await post(ACTION_SAVE_SETTINGS, payload);
        showToast(data?.message || 'Settings saved.', 'success');
        if (data?.settings && typeof window !== 'undefined') {
            window.efsData = window.efsData || {};
            window.efsData.settings = { ...window.efsData.settings, ...data.settings };
            populateSettingsForm();
            syncMigrationKeyForms();
        }
    } catch (error) {
        console.error('Save settings failed', error);
        showToast(buildAjaxErrorMessage(error, 'Settings save failed.'), 'error');
    } finally {
        setLoading(submitButton, false);
    }
};

const handleTestConnection = async (event) => {
    event.preventDefault();
    const button = event.currentTarget;
    const form = document.querySelector('[data-efs-settings-form]');
    if (!form) {
        return;
    }

    const apiKeyInput = form.querySelector('input[name="api_key"]');
    const apiKeyValue = (apiKeyInput?.value ?? '').trim();
    if (apiKeyInput) {
        const isValidApiKey = apiKeyValue.length === 24;
        setPinBoxesInvalidState(form, !isValidApiKey);
        if (!isValidApiKey) {
            showToast(
                window.efsData?.i18n?.invalidPinPaste ?? 'Application passwords must be 24 characters.',
                'error',
            );
            return;
        }
    }

    setLoading(button, true);
    try {
        const payload = serializeForm(form);
        const data = await post(ACTION_TEST_CONNECTION, payload);
        const successMessage = data?.message || 'Connection successful.';
        showToast(successMessage, 'success');
    } catch (error) {
        console.error('Test connection failed', error);
        showToast(buildAjaxErrorMessage(error, 'Connection test failed.'), 'error');
    } finally {
        setLoading(button, false);
    }
};

const handleGenerateKey = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    setLoading(button, true);
    try {
        const payload = serializeForm(form);
        const context = payload.context || form.querySelector('input[name="context"]')?.value || '';

        if (!payload.target_url || (!payload.api_key && context !== 'etch')) {
            const settingsForm = document.querySelector('[data-efs-settings-form]');
            const settingsPayload = settingsForm ? serializeForm(settingsForm) : {};
            payload.target_url = payload.target_url || settingsPayload.target_url || window.efsData?.site_url || '';
            if (context !== 'etch') {
                payload.api_key = payload.api_key || settingsPayload.api_key || '';
            }
        }

        if (!payload.target_url) {
            showToast('Enter the Etch site URL before generating a key.', 'error');
            return;
        }

        if (context !== 'etch' && !payload.api_key) {
            showToast('Provide the Etch application password before generating a key.', 'error');
            return;
        }

        const data = await post(ACTION_GENERATE_KEY, payload);
        const container = form.closest('[data-efs-accordion-section]') || document;
        const targetSelector = context === 'etch' ? '#efs-generated-key' : '#efs-migration-key';
        const textarea = container.querySelector(targetSelector) || document.querySelector(targetSelector);
        if (textarea && data?.key) {
            textarea.value = data.key;
        }
        showToast(data?.message || 'Migration key generated.', 'success');
    } catch (error) {
        console.error('Generate key failed', error);
        showToast(buildAjaxErrorMessage(error, 'Migration key generation failed.'), 'error');
    } finally {
        setLoading(button, false);
    }
};

const collectFeatureFlags = (form) => {
    const flags = {};
    const checkboxes = form.querySelectorAll('input[type="checkbox"][name^="feature_flags["]');
    checkboxes.forEach((checkbox) => {
        const match = checkbox.name.match(/feature_flags\[(.+)]/);
        if (!match || !match[1]) {
            return;
        }
        const flagName = match[1];
        flags[flagName] = checkbox.checked;
    });
    return { flags, checkboxes };
};

const handleSaveFeatureFlags = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('button[type="submit"]');
    setLoading(submitButton, true);

    try {
        const { flags, checkboxes } = collectFeatureFlags(form);
        const payload = new FormData();
        Object.entries(flags).forEach(([key, value]) => {
            payload.append(`feature_flags[${key}]`, value ? '1' : '0');
        });

        const data = await post(ACTION_SAVE_FEATURE_FLAGS, payload);
        showToast(data?.message || 'Feature flags saved.', 'success');

        if (typeof window !== 'undefined') {
            window.efsData = window.efsData || {};
            window.efsData.featureFlags = window.efsData.featureFlags || {};
            checkboxes.forEach((checkbox) => {
                const match = checkbox.name.match(/feature_flags\[(.+)]/);
                if (!match || !match[1]) {
                    return;
                }
                window.efsData.featureFlags[match[1]] = checkbox.checked;
            });
        }

        window.setTimeout(() => {
            window.location.reload();
        }, 400);
    } catch (error) {
        console.error('Save feature flags failed', error);
        showToast(buildAjaxErrorMessage(error, 'Failed to save feature flags.'), 'error');
    } finally {
        setLoading(submitButton, false);
    }
};

const initFeatureFlagsForm = () => {
    const form = document.querySelector('[data-efs-feature-flags]');
    if (!form) {
        return;
    }

    form.addEventListener('submit', handleSaveFeatureFlags);
};

export const bindSettings = () => {
    populateSettingsForm();
    syncMigrationKeyForms();
    const settingsForm = document.querySelector('[data-efs-settings-form]');
    const testConnectionButton = document.querySelector('[data-efs-test-connection-trigger]');
    const generateKeyForms = document.querySelectorAll('[data-efs-generate-key]');
    initFeatureFlagsForm();

    settingsForm?.addEventListener('submit', handleSaveSettings);
    testConnectionButton?.addEventListener('click', handleTestConnection);
    generateKeyForms.forEach((form) => {
        form.addEventListener('submit', handleGenerateKey);
    });

    settingsForm?.addEventListener('input', (event) => {
        const name = event.target?.name;
        if (name === 'target_url' || name === 'api_key') {
            syncMigrationKeyForms();
        }
    });
};
