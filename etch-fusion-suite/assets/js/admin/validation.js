import { post, buildAjaxErrorMessage } from './api.js';
import { showToast, setLoading } from './ui.js';

const ACTION_VALIDATE_TOKEN = 'efs_validate_migration_token';

const extractMigrationKey = (rawKey) => rawKey?.trim();

const handleValidateToken = async (event) => {
    event.preventDefault();
    const button = event.currentTarget;
    const migrationSection = button.closest('.efs-card__section');
    const textarea = migrationSection?.querySelector('#efs-migration-key')
        || document.querySelector('#efs-migration-key');
    if (!textarea) {
        return;
    }
    const rawKey = extractMigrationKey(textarea.value);
    if (!rawKey) {
        showToast('Please provide a migration key first.', 'warning');
        return;
    }
    const settingsForm = document.querySelector('[data-efs-settings-form]');
    const targetUrl = settingsForm?.querySelector('input[name="target_url"]')?.value?.trim();
    setLoading(button, true);
    try {
        const payload = { migration_key: rawKey };
        if (targetUrl) {
            payload.target_url = targetUrl;
        }
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
    document.querySelectorAll('[data-efs-validate-migration-key]').forEach((button) => {
        button.addEventListener('click', handleValidateToken);
    });
};
