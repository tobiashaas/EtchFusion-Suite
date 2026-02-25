import { setLoading, showToast } from './ui.js';

const REST_PATH_PAIRING_CODE = 'efs/v1/generate-pairing-code';

const bindPairingCodeButton = () => {
    const btn     = document.querySelector('[data-efs-generate-pairing-code]');
    const result  = document.querySelector('[data-efs-pairing-code-result]');
    const display = document.querySelector('[data-efs-pairing-code-display]');
    const copyBtn = document.querySelector('[data-efs-copy-pairing-code]');
    const expiry  = document.querySelector('[data-efs-pairing-code-expiry]');
    if (!btn) {
        return;
    }

    btn.addEventListener('click', async () => {
        setLoading(btn, true);
        try {
            const rawRestUrl = (window.efsData?.rest_url || '').replace(/\/$/, '');
            const restNonce  = window.efsData?.rest_nonce || '';

            // Normalize the REST URL to the current window origin so the request
            // is always same-origin. On live domains rest_url() may differ in
            // www/non-www or http/https from the browsed URL, which makes the
            // fetch cross-origin, drops the auth cookie, and causes a 403.
            let restUrl = rawRestUrl;
            try {
                const restOrigin    = new URL(rawRestUrl).origin;
                const currentOrigin = window.location.origin;
                if (restOrigin !== currentOrigin) {
                    restUrl = rawRestUrl.replace(restOrigin, currentOrigin);
                }
            } catch (_) {
                // keep raw URL if parsing fails
            }

            const res = await fetch(
                `${restUrl}/${REST_PATH_PAIRING_CODE}`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': restNonce },
                }
            );
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const data    = await res.json();
            const siteUrl = (window.efsData?.site_url || '').replace(/\/$/, '');
            display.textContent = `${siteUrl}/?_efs_pair=${data.raw_code}`;
            result.hidden = false;

            let remaining = Number(data.expires_in) || 900;
            const tick = () => {
                if (remaining <= 0) {
                    result.hidden = true;
                    return;
                }
                const m = Math.floor(remaining / 60);
                const s = remaining % 60;
                expiry.textContent = `Expires in ${m}:${String(s).padStart(2, '0')}`;
                remaining--;
                setTimeout(tick, 1000);
            };
            tick();
            showToast('Connection URL generated. Valid for 15 minutes.', 'success');
        } catch (err) {
            showToast(err?.message || 'Failed to generate connection URL.', 'error');
        } finally {
            setLoading(btn, false);
        }
    });

    copyBtn?.addEventListener('click', () => {
        const url = display?.textContent || '';
        if (navigator?.clipboard?.writeText) {
            navigator.clipboard.writeText(url);
        }
        showToast('Copied!', 'success');
    });
};

export const initEtchDashboard = () => {
    const root = document.querySelector('[data-efs-etch-dashboard]');
    if (!root) {
        return;
    }

    bindPairingCodeButton();
};
