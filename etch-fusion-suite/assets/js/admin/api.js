const ensureAjaxContext = () => {
    if (!window.efsData) {
        throw new Error('EFS admin data is not available on window.efsData.');
    }
    const { ajaxUrl, nonce } = window.efsData;
    if (!ajaxUrl) {
        throw new Error('AJAX URL is not defined in window.efsData.ajaxUrl.');
    }
    if (!nonce) {
        throw new Error('Nonce is not defined in window.efsData.nonce.');
    }
    return { ajaxUrl, nonce };
};

const appendValue = (params, key, value) => {
    if (value === undefined || value === null) {
        return;
    }
    if (Array.isArray(value)) {
        value.forEach((item) => params.append(`${key}[]`, item));
        return;
    }
    params.append(key, value);
};

const toSearchParams = (payload) => {
    if (payload instanceof URLSearchParams) {
        return payload;
    }
    if (payload instanceof FormData) {
        const params = new URLSearchParams();
        for (const [key, value] of payload.entries()) {
            appendValue(params, key, value);
        }
        return params;
    }
    const params = new URLSearchParams();
    Object.entries(payload || {}).forEach(([key, value]) => {
        appendValue(params, key, value);
    });
    return params;
};

export const post = async (action, payload = {}, options = {}) => {
    const { ajaxUrl, nonce } = ensureAjaxContext();
    const params = toSearchParams(payload);
    params.set('action', action);
    params.set('nonce', nonce);

    const controller = new AbortController();
    let timeoutId = null;
    let abortListener = null;

    if (options?.signal) {
        if (options.signal.aborted) {
            controller.abort(options.signal.reason);
        } else {
            abortListener = () => controller.abort(options.signal.reason);
            options.signal.addEventListener('abort', abortListener);
        }
    }

    if (typeof options?.timeoutMs === 'number' && options.timeoutMs > 0) {
        timeoutId = window.setTimeout(() => {
            controller.abort(new Error('Request timed out.'));
        }, options.timeoutMs);
    }

    let response;
    try {
        response = await fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: params.toString(),
            credentials: 'same-origin',
            signal: controller.signal,
        });
    } finally {
        if (abortListener && options?.signal) {
            options.signal.removeEventListener('abort', abortListener);
        }

        if (timeoutId) {
            window.clearTimeout(timeoutId);
        }
    }

    let result;
    let responseText;
    
    // Clone response before reading to preserve body for error handling
    const clonedResponse = response.clone();
    
    try {
        result = await response.json();
    } catch (parseError) {
        try {
            responseText = await clonedResponse.text();
        } catch {
            responseText = 'Unable to read response text';
        }
        console.error('[EFS API] Failed to parse JSON response', {
            status: response.status,
            action,
            parseError: parseError.message,
            responseText: responseText.substring(0, 500) // First 500 chars
        });
        throw new Error(`Server returned invalid JSON (status ${response.status})`);
    }
    
    if (!response.ok || !result?.success) {
        const errorPayload = result?.data ?? {};
        const errorMessage = typeof errorPayload === 'string'
            ? errorPayload
            : errorPayload?.message || `Request failed with status ${response.status}`;

        console.error('[EFS API Error]', {
            status: response.status,
            action,
            payload: errorPayload,
            fullResult: result
        });

        const error = new Error(errorMessage);

        if (errorPayload && typeof errorPayload === 'object') {
            if (errorPayload.code) {
                error.code = errorPayload.code;
            }
            if (errorPayload.details) {
                error.details = errorPayload.details;
            }
        }

        throw error;
    }

    return result.data ?? {};
};

export const serializeForm = (form) => {
    if (!form) {
        return {};
    }
    const formData = new FormData(form);
    const output = {};
    for (const [key, value] of formData.entries()) {
        if (Object.prototype.hasOwnProperty.call(output, key)) {
            output[key] = Array.isArray(output[key]) ? [...output[key], value] : [output[key], value];
        } else {
            output[key] = value;
        }
    }
    return output;
};

export const getInitialData = (key, defaultValue = null) => {
    if (!window.efsData || typeof window.efsData !== 'object') {
        return defaultValue;
    }
    return Object.prototype.hasOwnProperty.call(window.efsData, key)
        ? window.efsData[key]
        : defaultValue;
};

const humanizeFieldName = (value) => {
    const label = String(value || '').replace(/[_-]+/g, ' ').trim();
    if (!label) {
        return '';
    }
    return label.replace(/\b\w/g, (match) => match.toUpperCase());
};

const collectContextFields = (context) => {
    if (!context || typeof context !== 'object') {
        return [];
    }

    const fields = new Set();

    const maybeAdd = (value) => {
        if (typeof value === 'string' && value.trim() !== '') {
            fields.add(value.trim());
        }
    };

    maybeAdd(context.field);

    if (Array.isArray(context.fields)) {
        context.fields.forEach(maybeAdd);
    }

    if (Array.isArray(context.missing_fields)) {
        context.missing_fields.forEach(maybeAdd);
    }

    return Array.from(fields);
};

export const buildAjaxErrorMessage = (error, fallback = 'Request failed.') => {
    const baseMessage = error?.message || fallback;
    const contextFields = collectContextFields(error?.details?.context);

    if (!contextFields.length) {
        return baseMessage;
    }

    const humanizedList = contextFields
        .map(humanizeFieldName)
        .filter(Boolean)
        .join(', ');

    if (!humanizedList) {
        return baseMessage;
    }

    const label = contextFields.length === 1 ? 'Field' : 'Fields';

    return `${baseMessage} — ${label}: ${humanizedList}`;
};
