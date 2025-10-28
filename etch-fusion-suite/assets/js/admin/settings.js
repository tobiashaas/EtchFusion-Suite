import { post, serializeForm, getInitialData, buildAjaxErrorMessage } from './api.js';
import { showToast, setLoading } from './ui.js';

const ACTION_SAVE_SETTINGS = 'efs_save_settings';
const ACTION_TEST_CONNECTION = 'efs_test_connection';
const ACTION_GENERATE_KEY = 'efs_generate_migration_key';

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

const handleSaveSettings = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('button[type="submit"]');
    setLoading(submitButton, true);
    try {
        const payload = serializeForm(form);
        const data = await post(ACTION_SAVE_SETTINGS, payload);
        showToast(data?.message || 'Settings saved.', 'success');
    } catch (error) {
        console.error('Save settings failed', error);
        showToast(buildAjaxErrorMessage(error, 'Settings save failed.'), 'error');
    } finally {
        setLoading(submitButton, false);
    }
};

const handleTestConnection = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    setLoading(button, true);
    try {
        const payload = serializeForm(form);
        if (!payload.target_url || !payload.api_key) {
            const settingsForm = document.querySelector('[data-efs-settings-form]');
            const settingsPayload = serializeForm(settingsForm);
            payload.target_url = payload.target_url || settingsPayload.target_url;
            payload.api_key = payload.api_key || settingsPayload.api_key;
        }
        const data = await post(ACTION_TEST_CONNECTION, payload);
        showToast(data?.message || 'Connection successful.', 'success');
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
        const data = await post(ACTION_GENERATE_KEY, payload);
        const textarea = form.querySelector('[data-efs-migration-key]');
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

export const bindSettings = () => {
    populateSettingsForm();
    const settingsForm = document.querySelector('[data-efs-settings-form]');
    const testConnectionForm = document.querySelector('[data-efs-test-connection]');
    const generateKeyForm = document.querySelector('[data-efs-generate-key]');

    settingsForm?.addEventListener('submit', handleSaveSettings);
    testConnectionForm?.addEventListener('submit', handleTestConnection);
    generateKeyForm?.addEventListener('submit', handleGenerateKey);
};
