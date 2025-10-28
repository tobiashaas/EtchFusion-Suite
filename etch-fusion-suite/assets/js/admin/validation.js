import { post, buildAjaxErrorMessage } from './api.js';
import { showToast, setLoading } from './ui.js';

const ACTION_VALIDATE_API_KEY = 'efs_validate_api_key';
const ACTION_VALIDATE_TOKEN = 'efs_validate_migration_token';

const extractMigrationKeyParts = (rawKey) => {
    try {
        const url = new URL(rawKey);
        return {
            target_url: url.searchParams.get('domain') || url.origin,
            token: url.searchParams.get('token'),
            expires: url.searchParams.get('expires'),
        };
    } catch (error) {
        return {
            token: rawKey,
        };
    }
};

const handleValidateApiKey = async (event) => {
    event.preventDefault();
    const button = event.currentTarget;
    const container = button.closest('[data-efs-field]');
    const form = container.querySelector('form');
    if (!form) {
        return;
    }
    setLoading(button, true);
    try {
        const payload = extractMigrationKeyParts(form.querySelector('[name="target_url"]').value);
        payload.api_key = form.querySelector('[name="api_key"]').value;
        await post(ACTION_VALIDATE_API_KEY, payload);
        showToast('API key validated successfully.', 'success');
    } catch (error) {
        console.error('API key validation failed', error);
        showToast(buildAjaxErrorMessage(error, 'API key validation failed.'), 'error');
    } finally {
        setLoading(button, false);
    }
};

const handleValidateToken = async (event) => {
    event.preventDefault();
    const button = event.currentTarget;
    const textarea = document.querySelector('[data-efs-migration-key]');
    if (!textarea) {
        return;
    }
    const rawKey = textarea.value.trim();
    if (!rawKey) {
        showToast('Please provide a migration key first.', 'warning');
        return;
    }
    setLoading(button, true);
    try {
        const payload = extractMigrationKeyParts(rawKey);
        await post(ACTION_VALIDATE_TOKEN, payload);
        showToast('Migration token validated.', 'success');
    } catch (error) {
        console.error('Migration token validation failed', error);
        showToast(buildAjaxErrorMessage(error, 'Migration token validation failed.'), 'error');
    } finally {
        setLoading(button, false);
    }
};

export const bindValidation = () => {
    document.querySelectorAll('[data-efs-validate-api-key]').forEach((button) => {
        button.addEventListener('click', handleValidateApiKey);
    });

    document.querySelectorAll('[data-efs-validate-migration-key]').forEach((button) => {
        button.addEventListener('click', handleValidateToken);
    });
};
