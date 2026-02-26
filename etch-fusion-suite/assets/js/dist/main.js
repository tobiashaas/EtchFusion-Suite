"use strict";
(() => {
  // assets/js/admin/api.js
  var ensureAjaxContext = () => {
    if (!window.efsData) {
      throw new Error("EFS admin data is not available on window.efsData.");
    }
    const { ajaxUrl, nonce } = window.efsData;
    if (!ajaxUrl) {
      throw new Error("AJAX URL is not defined in window.efsData.ajaxUrl.");
    }
    if (!nonce) {
      throw new Error("Nonce is not defined in window.efsData.nonce.");
    }
    return { ajaxUrl, nonce };
  };
  var appendValue = (params, key, value) => {
    if (value === void 0 || value === null) {
      return;
    }
    if (Array.isArray(value)) {
      value.forEach((item) => appendValue(params, `${key}[]`, item));
      return;
    }
    if (typeof value === "object" && !(value instanceof Date) && !(value instanceof File) && !(value instanceof Blob)) {
      Object.entries(value).forEach(([childKey, childValue]) => {
        appendValue(params, `${key}[${childKey}]`, childValue);
      });
      return;
    }
    params.append(key, value);
  };
  var toSearchParams = (payload) => {
    if (payload instanceof URLSearchParams) {
      return payload;
    }
    if (payload instanceof FormData) {
      const params2 = new URLSearchParams();
      for (const [key, value] of payload.entries()) {
        appendValue(params2, key, value);
      }
      return params2;
    }
    const params = new URLSearchParams();
    Object.entries(payload || {}).forEach(([key, value]) => {
      appendValue(params, key, value);
    });
    return params;
  };
  var post = async (action, payload = {}, options = {}) => {
    const { ajaxUrl, nonce } = ensureAjaxContext();
    const params = toSearchParams(payload);
    params.set("action", action);
    params.set("nonce", nonce);
    const controller = new AbortController();
    let timeoutId = null;
    let abortListener = null;
    if (options?.signal) {
      if (options.signal.aborted) {
        controller.abort(options.signal.reason);
      } else {
        abortListener = () => controller.abort(options.signal.reason);
        options.signal.addEventListener("abort", abortListener);
      }
    }
    if (typeof options?.timeoutMs === "number" && options.timeoutMs > 0) {
      timeoutId = window.setTimeout(() => {
        controller.abort(new Error("Request timed out."));
      }, options.timeoutMs);
    }
    let response;
    try {
      response = await fetch(ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
        },
        body: params.toString(),
        credentials: "same-origin",
        signal: controller.signal
      });
    } finally {
      if (abortListener && options?.signal) {
        options.signal.removeEventListener("abort", abortListener);
      }
      if (timeoutId) {
        window.clearTimeout(timeoutId);
      }
    }
    let result;
    let responseText;
    const clonedResponse = response.clone();
    try {
      result = await response.json();
    } catch (parseError) {
      try {
        responseText = await clonedResponse.text();
      } catch {
        responseText = "Unable to read response text";
      }
      console.error("[EFS API] Failed to parse JSON response", {
        status: response.status,
        action,
        parseError: parseError.message,
        responseText: responseText.substring(0, 500)
        // First 500 chars
      });
      throw new Error(`Server returned invalid JSON (status ${response.status})`);
    }
    if (!response.ok || !result?.success) {
      const errorPayload = result?.data ?? {};
      const errorMessage = typeof errorPayload === "string" ? errorPayload : errorPayload?.message || `Request failed with status ${response.status}`;
      console.error("[EFS API Error]", {
        status: response.status,
        action,
        payload: errorPayload,
        fullResult: result
      });
      const error = new Error(errorMessage);
      if (errorPayload && typeof errorPayload === "object") {
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
  var serializeForm = (form) => {
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
  var getInitialData = (key, defaultValue = null) => {
    if (!window.efsData || typeof window.efsData !== "object") {
      return defaultValue;
    }
    return Object.prototype.hasOwnProperty.call(window.efsData, key) ? window.efsData[key] : defaultValue;
  };
  var humanizeFieldName = (value) => {
    const label = String(value || "").replace(/[_-]+/g, " ").trim();
    if (!label) {
      return "";
    }
    return label.replace(/\b\w/g, (match) => match.toUpperCase());
  };
  var collectContextFields = (context) => {
    if (!context || typeof context !== "object") {
      return [];
    }
    const fields = /* @__PURE__ */ new Set();
    const maybeAdd = (value) => {
      if (typeof value === "string" && value.trim() !== "") {
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
  var buildAjaxErrorMessage = (error, fallback = "Request failed.") => {
    const baseMessage = error?.message || fallback;
    const contextFields = collectContextFields(error?.details?.context);
    if (!contextFields.length) {
      return baseMessage;
    }
    const humanizedList = contextFields.map(humanizeFieldName).filter(Boolean).join(", ");
    if (!humanizedList) {
      return baseMessage;
    }
    const label = contextFields.length === 1 ? "Field" : "Fields";
    return `${baseMessage} \u2014 ${label}: ${humanizedList}`;
  };

  // assets/js/admin/ui.js
  var TOAST_VISIBLE_CLASS = "show";
  var TOAST_CONTAINER_CLASS = "efs-toast-container";
  var ensureToastContainer = () => {
    let container = document.querySelector(`.${TOAST_CONTAINER_CLASS}`);
    if (!container) {
      container = document.createElement("div");
      container.className = TOAST_CONTAINER_CLASS;
      container.setAttribute("aria-live", "polite");
      container.setAttribute("role", "status");
      document.body.appendChild(container);
    }
    return container;
  };
  var fallbackCopy = (value) => {
    const textarea = document.createElement("textarea");
    textarea.value = value;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.top = "-9999px";
    textarea.style.left = "-9999px";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    const selection = document.getSelection();
    const previousRange = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);
    let success = false;
    try {
      success = document.execCommand("copy");
    } catch {
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
  var showToast = (message, type = "info", { duration = 4e3 } = {}) => {
    if (!message) {
      return null;
    }
    const container = ensureToastContainer();
    const toast = document.createElement("div");
    toast.className = "efs-toast";
    toast.classList.add(type);
    toast.textContent = message;
    container.appendChild(toast);
    window.requestAnimationFrame(() => {
      toast.classList.add(TOAST_VISIBLE_CLASS);
    });
    const hide = () => {
      toast.classList.remove(TOAST_VISIBLE_CLASS);
      toast.addEventListener("transitionend", () => toast.remove(), { once: true });
    };
    if (duration > 0) {
      window.setTimeout(hide, duration);
    }
    toast.addEventListener("click", hide, { once: true });
    return toast;
  };
  var bindCopyButtons = () => {
    document.querySelectorAll("[data-efs-copy-button], [data-efs-copy]").forEach((button) => {
      button.addEventListener("click", async (event) => {
        event.preventDefault();
        const selector = button.getAttribute("data-efs-target") || button.getAttribute("data-efs-copy");
        const successMessage = button.getAttribute("data-toast-success") || "Copied to clipboard.";
        if (!selector) {
          return;
        }
        console.debug("[EFS] Copy button clicked", { selector });
        const target = document.querySelector(selector);
        if (!target) {
          console.warn("[EFS] Copy target not found", { selector });
          return;
        }
        const value = target.value || target.textContent || "";
        try {
          try {
            if (navigator?.clipboard?.writeText) {
              await navigator.clipboard.writeText(value);
              console.debug("[EFS] Clipboard API copy succeeded");
              showToast(successMessage, "success");
              return;
            }
          } catch (error) {
            console.error("Clipboard copy failed", error);
          }
          const fallbackSuccess = fallbackCopy(value);
          if (fallbackSuccess) {
            console.debug("[EFS] Fallback copy succeeded");
            showToast(successMessage, "success");
          } else {
            console.warn("[EFS] Fallback copy failed");
            showToast("Unable to copy to clipboard.", "error");
          }
        } catch (error) {
          console.error("Clipboard copy failed", error);
          showToast("Unable to copy to clipboard.", "error");
        }
      });
    });
  };
  var normalizeSteps = (steps = []) => {
    if (!steps) {
      return [];
    }
    if (Array.isArray(steps)) {
      return steps;
    }
    if (typeof steps === "object") {
      return Object.entries(steps).map(([slug, data]) => ({
        slug,
        label: data?.label || data?.name || slug.replace(/_/g, " "),
        ...data
      }));
    }
    return [];
  };
  var setLoading = (element, isLoading) => {
    if (!element) {
      return;
    }
    element.toggleAttribute("aria-busy", Boolean(isLoading));
    element.disabled = Boolean(isLoading);
  };
  var updateProgress = ({ percentage = 0, status = "", steps = [], items_processed = 0, items_total = 0, items_skipped = 0 }) => {
    const progressRoot = document.querySelector("[data-efs-progress]");
    const progressFill = progressRoot?.querySelector(".efs-progress-fill");
    const currentStep = document.querySelector("[data-efs-current-step]");
    const stepsList = document.querySelector("[data-efs-steps]");
    const itemsCount = document.querySelector("[data-efs-items-count]");
    let displayPercentage;
    if (items_total > 0) {
      displayPercentage = Math.min(100, Math.round(items_processed / items_total * 100));
    } else if (percentage >= 100) {
      displayPercentage = 100;
    } else {
      displayPercentage = null;
    }
    if (displayPercentage !== null) {
      progressRoot?.setAttribute("aria-valuenow", String(displayPercentage));
    } else {
      progressRoot?.removeAttribute("aria-valuenow");
    }
    if (progressFill) {
      if (displayPercentage !== null) {
        progressFill.style.width = `${displayPercentage}%`;
        progressFill.classList.remove("is-indeterminate");
      } else {
        progressFill.style.width = "";
        progressFill.classList.add("is-indeterminate");
      }
    }
    if (itemsCount) {
      if (items_total > 0) {
        if (items_skipped > 0) {
          const migrated = Math.max(0, items_processed - items_skipped);
          itemsCount.textContent = `${migrated} migriert \xB7 ${items_skipped} \xFCbersprungen`;
        } else {
          itemsCount.textContent = `${items_processed} / ${items_total}`;
        }
        itemsCount.hidden = false;
      } else {
        itemsCount.textContent = "";
        itemsCount.hidden = true;
      }
    }
    if (currentStep) {
      currentStep.textContent = status || "";
    }
    if (stepsList) {
      stepsList.innerHTML = "";
      const normalizedSteps = normalizeSteps(steps);
      normalizedSteps.forEach((step) => {
        const li = document.createElement("li");
        li.className = "efs-migration-step";
        if (step.completed) {
          li.classList.add("is-complete");
        }
        if (step.active) {
          li.classList.add("is-active");
        }
        li.textContent = step.label || step.slug || "";
        stepsList.appendChild(li);
      });
    }
  };
  var syncInitialProgress = () => {
    const progress = getInitialData("progress_data");
    if (!progress) {
      return;
    }
    updateProgress({
      percentage: progress.percentage || 0,
      status: progress.current_step || progress.status || "",
      steps: progress.steps || [],
      items_processed: progress.items_processed || 0,
      items_total: progress.items_total || 0,
      items_skipped: progress.items_skipped || 0
    });
  };
  var initUI = () => {
    bindCopyButtons();
    syncInitialProgress();
  };

  // assets/js/admin/settings.js
  var ACTION_SAVE_SETTINGS = "efs_save_settings";
  var ACTION_TEST_CONNECTION = "efs_test_connection";
  var ACTION_GENERATE_KEY = "efs_generate_migration_key";
  var ACTION_SAVE_FEATURE_FLAGS = "efs_save_feature_flags";
  var populateSettingsForm = () => {
    const form = document.querySelector("[data-efs-settings-form]");
    const settings = getInitialData("settings", {});
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
  var syncMigrationKeyForms = () => {
    const settings = getInitialData("settings", {});
    const settingsForm = document.querySelector("[data-efs-settings-form]");
    const formTargetUrl = settingsForm?.querySelector('input[name="target_url"]')?.value?.trim();
    const siteUrl = window.efsData?.site_url || "";
    document.querySelectorAll("[data-efs-generate-key]").forEach((form) => {
      const context = form.querySelector('input[name="context"]')?.value;
      const targetField = form.querySelector('input[name="target_url"]');
      if (context === "bricks") {
        const targetUrl = formTargetUrl || settings.target_url || "";
        if (targetField && targetUrl) {
          targetField.value = targetUrl;
        }
      }
      if (context === "etch" && targetField && siteUrl) {
        targetField.value = siteUrl;
      }
    });
  };
  var handleSaveSettings = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('button[type="submit"]');
    setLoading(submitButton, true);
    try {
      const payload = serializeForm(form);
      if (payload.api_key) {
        delete payload.api_key;
      }
      const data = await post(ACTION_SAVE_SETTINGS, payload);
      showToast(data?.message || "Settings saved.", "success");
      if (data?.settings && typeof window !== "undefined") {
        window.efsData = window.efsData || {};
        window.efsData.settings = { ...window.efsData.settings, ...data.settings };
        populateSettingsForm();
        syncMigrationKeyForms();
      }
    } catch (error) {
      console.error("Save settings failed", error);
      showToast(buildAjaxErrorMessage(error, "Settings save failed."), "error");
    } finally {
      setLoading(submitButton, false);
    }
  };
  var handleTestConnection = async (event) => {
    event.preventDefault();
    const button = event.currentTarget;
    const form = document.querySelector("[data-efs-settings-form]");
    if (!form) {
      return;
    }
    setLoading(button, true);
    try {
      const payload = serializeForm(form);
      if (payload.api_key) {
        delete payload.api_key;
      }
      const data = await post(ACTION_TEST_CONNECTION, payload);
      const successMessage = data?.message || "Connection successful.";
      showToast(successMessage, "success");
    } catch (error) {
      console.error("Test connection failed", error);
      showToast(buildAjaxErrorMessage(error, "Connection test failed."), "error");
    } finally {
      setLoading(button, false);
    }
  };
  var handleGenerateKey = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    setLoading(button, true);
    try {
      const payload = serializeForm(form);
      const context = payload.context || form.querySelector('input[name="context"]')?.value || "";
      if (!payload.target_url) {
        const settingsForm = document.querySelector("[data-efs-settings-form]");
        const settingsPayload = settingsForm ? serializeForm(settingsForm) : {};
        payload.target_url = payload.target_url || settingsPayload.target_url || window.efsData?.site_url || "";
      }
      if (!payload.target_url) {
        showToast("Enter the Etch site URL before generating a key.", "error");
        return;
      }
      if (payload.api_key) {
        delete payload.api_key;
      }
      const data = await post(ACTION_GENERATE_KEY, payload);
      const targetSelector = context === "etch" ? "#efs-generated-key" : "#efs-migration-key";
      const textarea = document.querySelector(targetSelector);
      if (textarea && data?.key) {
        textarea.value = data.key;
      }
      showToast(data?.message || "Migration key generated.", "success");
    } catch (error) {
      console.error("Generate key failed", error);
      showToast(buildAjaxErrorMessage(error, "Migration key generation failed."), "error");
    } finally {
      setLoading(button, false);
    }
  };
  var collectFeatureFlags = (form) => {
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
  var handleSaveFeatureFlags = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('button[type="submit"]');
    setLoading(submitButton, true);
    try {
      const { flags, checkboxes } = collectFeatureFlags(form);
      const payload = new FormData();
      Object.entries(flags).forEach(([key, value]) => {
        payload.append(`feature_flags[${key}]`, value ? "1" : "0");
      });
      const data = await post(ACTION_SAVE_FEATURE_FLAGS, payload);
      showToast(data?.message || "Feature flags saved.", "success");
      if (typeof window !== "undefined") {
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
      console.error("Save feature flags failed", error);
      showToast(buildAjaxErrorMessage(error, "Failed to save feature flags."), "error");
    } finally {
      setLoading(submitButton, false);
    }
  };
  var initFeatureFlagsForm = () => {
    const form = document.querySelector("[data-efs-feature-flags]");
    if (!form) {
      return;
    }
    form.addEventListener("submit", handleSaveFeatureFlags);
  };
  var bindSettings = () => {
    populateSettingsForm();
    syncMigrationKeyForms();
    const settingsForm = document.querySelector("[data-efs-settings-form]");
    const testConnectionButton = document.querySelector("[data-efs-test-connection-trigger]");
    const generateKeyForms = document.querySelectorAll("[data-efs-generate-key]");
    initFeatureFlagsForm();
    settingsForm?.addEventListener("submit", handleSaveSettings);
    testConnectionButton?.addEventListener("click", handleTestConnection);
    generateKeyForms.forEach((form) => {
      form.addEventListener("submit", handleGenerateKey);
    });
    settingsForm?.addEventListener("input", (event) => {
      const name = event.target?.name;
      if (name === "target_url") {
        syncMigrationKeyForms();
      }
    });
  };

  // assets/js/admin/validation.js
  var ACTION_VALIDATE_TOKEN = "efs_validate_migration_token";
  var extractMigrationKey = (rawKey) => rawKey?.trim();
  var handleValidateToken = async (event) => {
    event.preventDefault();
    const button = event.currentTarget;
    const migrationSection = button.closest(".efs-card__section");
    const textarea = migrationSection?.querySelector("#efs-migration-key") || document.querySelector("#efs-migration-key");
    if (!textarea) {
      return;
    }
    const rawKey = extractMigrationKey(textarea.value);
    if (!rawKey) {
      showToast("Please provide a migration key first.", "warning");
      return;
    }
    const settingsForm = document.querySelector("[data-efs-settings-form]");
    const targetUrl = settingsForm?.querySelector('input[name="target_url"]')?.value?.trim();
    setLoading(button, true);
    try {
      const payload = { migration_key: rawKey };
      if (targetUrl) {
        payload.target_url = targetUrl;
      }
      await post(ACTION_VALIDATE_TOKEN, payload);
      showToast("Migration token validated.", "success");
    } catch (error) {
      console.error("Migration token validation failed", error);
      showToast(buildAjaxErrorMessage(error, "Migration token validation failed."), "error");
    } finally {
      setLoading(button, false);
    }
  };
  var bindValidation = () => {
    document.querySelectorAll("[data-efs-validate-migration-key]").forEach((button) => {
      button.addEventListener("click", handleValidateToken);
    });
  };

  // assets/js/admin/migration.js
  var ACTION_START_MIGRATION = "efs_start_migration";
  var ACTION_GET_PROGRESS = "efs_get_migration_progress";
  var ACTION_CANCEL_MIGRATION = "efs_cancel_migration";
  var pollTimer = null;
  var activeMigrationId = window.efsData?.migrationId || null;
  var pollState = null;
  var DEFAULT_POLL_INTERVAL_MS = 3e3;
  var MAX_POLL_INTERVAL_MS = 3e4;
  var MAX_CONSECUTIVE_POLL_FAILURES = 5;
  var DEFAULT_PROGRESS_TIMEOUT_MS = 15e3;
  var setActiveMigrationId = (migrationId) => {
    if (!migrationId) {
      activeMigrationId = null;
      if (window.efsData) {
        delete window.efsData.migrationId;
      }
      return;
    }
    activeMigrationId = migrationId;
    window.efsData = {
      ...window.efsData || {},
      migrationId
    };
  };
  var getActiveMigrationId = () => activeMigrationId || window.efsData?.migrationId || null;
  var requestProgress = async (params = {}, requestOptions = {}) => {
    const migrationId = params?.migrationId || getActiveMigrationId();
    if (!migrationId) {
      console.warn("[EFS] No migration ID available for progress request");
      return {
        progress: { percentage: 0, status: "", current_step: "" },
        steps: [],
        migrationId: null,
        completed: false
      };
    }
    try {
      const data = await post(ACTION_GET_PROGRESS, {
        ...params,
        migrationId
      }, requestOptions);
      if (data?.migrationId) {
        setActiveMigrationId(data.migrationId);
      }
      const progress = data?.progress || { percentage: 0, status: "", current_step: "" };
      const steps = data?.steps || progress.steps || [];
      updateProgress({
        percentage: progress.percentage || 0,
        status: progress.message || progress.status || progress.current_step || "",
        steps,
        items_processed: progress.items_processed || 0,
        items_total: progress.items_total || 0,
        items_skipped: progress.items_skipped || 0
      });
      if (data?.completed) {
        showToast("Migration completed successfully.", "success");
        stopProgressPolling();
        setActiveMigrationId(null);
      }
      return data;
    } catch (error) {
      if (error?.name === "AbortError") {
        return { aborted: true, migrationId };
      }
      console.error("[EFS] Progress request failed:", error);
      return {
        progress: { percentage: 0, status: "error", current_step: "error" },
        steps: [],
        migrationId,
        completed: false,
        error: buildAjaxErrorMessage(error, "Failed to retrieve migration progress."),
        failed: true
      };
    }
  };
  var startMigration = async (payload) => {
    const tokenField = document.querySelector("#efs-migration-token");
    if (tokenField && !payload.migration_token) {
      payload.migration_token = tokenField.value || "";
    }
    const migrationForm = document.querySelector("[data-efs-migration-form]");
    const keyField = migrationForm?.querySelector("#efs-migration-key") || document.querySelector("#efs-migration-key");
    if (keyField && !payload.migration_key) {
      payload.migration_key = keyField.value || "";
    }
    const requestPayload = { ...payload };
    if (!requestPayload.migration_token) {
      delete requestPayload.migration_token;
    }
    const data = await post(ACTION_START_MIGRATION, requestPayload);
    if (data?.token) {
      const tokenField2 = document.querySelector("#efs-migration-token");
      if (tokenField2) {
        tokenField2.value = data.token;
      }
    }
    if (data?.migrationId) {
      setActiveMigrationId(data.migrationId);
    }
    showToast(data?.message || "Migration started.", "success");
    updateProgress({
      percentage: data?.progress?.percentage || 0,
      status: data?.progress?.message || data?.progress?.status || "",
      steps: data?.steps || [],
      items_processed: data?.progress?.items_processed || 0,
      items_total: data?.progress?.items_total || 0,
      items_skipped: data?.progress?.items_skipped || 0
    });
    startProgressPolling({ migrationId: getActiveMigrationId() });
    return data;
  };
  var cancelMigration = async (payload) => {
    const migrationId = payload?.migrationId || getActiveMigrationId();
    const data = await post(ACTION_CANCEL_MIGRATION, {
      ...payload,
      migrationId
    });
    showToast(data?.message || "Migration cancelled.", "info");
    stopProgressPolling();
    setActiveMigrationId(null);
    return data;
  };
  var startProgressPolling = (params = {}, options = {}) => {
    stopProgressPolling();
    const migrationId = params?.migrationId || getActiveMigrationId();
    if (!migrationId) {
      return;
    }
    const pollParams = {
      ...params,
      migrationId
    };
    const initialInterval = options.intervalMs ?? DEFAULT_POLL_INTERVAL_MS;
    const maxInterval = options.maxIntervalMs ?? MAX_POLL_INTERVAL_MS;
    const maxFailures = options.maxFailures ?? MAX_CONSECUTIVE_POLL_FAILURES;
    const timeoutMs = options.timeoutMs ?? DEFAULT_PROGRESS_TIMEOUT_MS;
    pollState = {
      pollParams,
      intervalMs: initialInterval,
      initialInterval,
      maxInterval,
      maxFailures,
      failureCount: 0,
      timeoutMs,
      abortController: null
    };
    const scheduleNextPoll = () => {
      if (!pollState) {
        return;
      }
      pollTimer = window.setTimeout(runPoll, pollState.intervalMs);
    };
    const runPoll = async () => {
      if (!pollState) {
        return;
      }
      if (pollState.abortController) {
        pollState.abortController.abort();
      }
      pollState.abortController = new AbortController();
      let result;
      try {
        result = await requestProgress(pollState.pollParams, {
          signal: pollState.abortController.signal,
          timeoutMs: pollState.timeoutMs
        });
      } catch (error) {
        result = {
          error: buildAjaxErrorMessage(error, "Failed to retrieve migration progress."),
          failed: true
        };
      }
      if (!pollState) {
        return;
      }
      if (result?.aborted) {
        return;
      }
      const hasError = Boolean(result?.error || result?.failed);
      if (hasError) {
        pollState.failureCount += 1;
        pollState.intervalMs = Math.min(pollState.intervalMs * 2, pollState.maxInterval);
        console.warn(
          `[EFS] Progress polling failed (${pollState.failureCount}/${pollState.maxFailures}): ${result?.error}`
        );
        if (pollState.failureCount >= pollState.maxFailures) {
          const message = result?.error || "Migration progress polling failed repeatedly.";
          stopProgressPolling();
          showToast(message, "error");
          return;
        }
      } else {
        pollState.failureCount = 0;
        pollState.intervalMs = pollState.initialInterval;
      }
      scheduleNextPoll();
    };
    runPoll();
  };
  var stopProgressPolling = () => {
    if (pollTimer) {
      window.clearTimeout(pollTimer);
      pollTimer = null;
    }
    if (pollState?.abortController) {
      pollState.abortController.abort();
    }
    pollState = null;
  };

  // assets/js/admin/logs.js
  var ACTION_FETCH_LOGS = "efs_get_logs";
  var ACTION_CLEAR_LOGS = "efs_clear_logs";
  var refreshTimer = null;
  var currentFilter = "all";
  var allSecurityLogs = [];
  var allMigrationRuns = [];
  var pendingHashMigrationId = "";
  var formatTimestamp = (timestamp) => {
    if (!timestamp) {
      return "";
    }
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) {
      return timestamp;
    }
    return date.toLocaleString();
  };
  var formatDuration = (seconds) => {
    if (!seconds || seconds < 0) {
      return "00:00";
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
  };
  var formatContextValue = (value) => {
    if (value === null || value === void 0) {
      return "";
    }
    if (typeof value === "object") {
      try {
        return JSON.stringify(value);
      } catch {
        return String(value);
      }
    }
    return String(value);
  };
  var renderLogs = (logs = []) => {
    const container = document.querySelector("[data-efs-logs-list]");
    if (!container) {
      return;
    }
    Array.from(container.querySelectorAll("article.efs-log-entry:not([data-migration-id])")).forEach((el) => el.remove());
    const emptyState = container.querySelector(".efs-empty-state");
    if (emptyState) {
      emptyState.remove();
    }
    if (!Array.isArray(logs) || logs.length === 0) {
      if (!container.querySelector("article.efs-log-entry")) {
        const empty = document.createElement("p");
        empty.className = "efs-empty-state";
        empty.textContent = "No logs yet. Migration activity will appear here.";
        container.appendChild(empty);
      }
      return;
    }
    logs.forEach((log) => {
      const severity = log.severity || "info";
      const article = document.createElement("article");
      article.className = `efs-log-entry efs-log-entry--${severity}`;
      const header = document.createElement("header");
      header.className = "efs-log-entry__header";
      const meta = document.createElement("div");
      meta.className = "efs-log-entry__meta";
      if (log.timestamp) {
        const time = document.createElement("time");
        time.dateTime = log.timestamp;
        time.textContent = formatTimestamp(log.timestamp);
        meta.appendChild(time);
      }
      if (log.event_type || log.code) {
        const code = document.createElement("span");
        code.className = "efs-log-entry__code";
        code.textContent = log.event_type || log.code;
        meta.appendChild(code);
      }
      header.appendChild(meta);
      const badge = document.createElement("span");
      badge.className = `efs-log-entry__badge efs-log-entry__badge--${severity}`;
      badge.textContent = (severity.charAt(0).toUpperCase() + severity.slice(1)).replace(/_/g, " ");
      header.appendChild(badge);
      article.appendChild(header);
      if (log.message) {
        const message = document.createElement("p");
        message.className = "efs-log-entry__message";
        message.textContent = log.message;
        article.appendChild(message);
      }
      if (log.context && typeof log.context === "object" && Object.keys(log.context).length > 0) {
        const contextList = document.createElement("dl");
        contextList.className = "efs-log-entry__context";
        Object.entries(log.context).forEach(([key, value]) => {
          const item = document.createElement("div");
          item.className = "efs-log-entry__context-item";
          const term = document.createElement("dt");
          term.textContent = key;
          const description = document.createElement("dd");
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
  var renderMigrationRuns = (runs = []) => {
    const container = document.querySelector("[data-efs-logs-list]");
    if (!container) {
      return;
    }
    Array.from(container.querySelectorAll("article.efs-log-entry[data-migration-id]")).forEach((el) => el.remove());
    if (!Array.isArray(runs) || runs.length === 0) {
      return;
    }
    const statusMap = {
      success: { cls: "success", label: "Success" },
      success_with_warnings: { cls: "warning", label: "Success with warnings" },
      failed: { cls: "error", label: "Failed" }
    };
    runs.forEach((run) => {
      const statusInfo = statusMap[run.status] || { cls: "info", label: run.status || "Unknown" };
      const article = document.createElement("article");
      article.className = "efs-log-entry efs-log-entry--migration";
      article.dataset.migrationId = run.migrationId || "";
      const header = document.createElement("header");
      header.className = "efs-log-entry__header";
      const meta = document.createElement("div");
      meta.className = "efs-log-entry__meta";
      if (run.timestamp_started_at) {
        const time = document.createElement("time");
        time.dateTime = run.timestamp_started_at;
        time.textContent = formatTimestamp(run.timestamp_started_at);
        meta.appendChild(time);
      }
      if (run.target_url || run.source_site) {
        const site = document.createElement("span");
        site.className = "efs-log-entry__code";
        site.textContent = run.target_url || run.source_site;
        meta.appendChild(site);
      }
      header.appendChild(meta);
      const badge = document.createElement("span");
      badge.className = `efs-log-entry__badge efs-log-entry__badge--${statusInfo.cls}`;
      badge.textContent = statusInfo.label;
      header.appendChild(badge);
      article.appendChild(header);
      if (run.counts_by_post_type && typeof run.counts_by_post_type === "object") {
        const byTypeEntries = Object.entries(run.counts_by_post_type).filter(([postType]) => postType !== "total" && postType !== "migrated");
        const counts = document.createElement("p");
        counts.className = "efs-log-entry__counts";
        if (byTypeEntries.length > 0) {
          const parts = byTypeEntries.map(([postType, value]) => {
            if (value && typeof value === "object") {
              const total = Number(value.total ?? value.items_total ?? 0);
              const migrated = Number(value.migrated ?? value.items_processed ?? 0);
              return `${postType}: ${migrated} / ${total}`;
            }
            const numericValue = Number(value);
            return `${postType}: ${Number.isFinite(numericValue) ? numericValue : 0}`;
          });
          counts.textContent = parts.join(" | ");
        } else {
          const total = run.counts_by_post_type.total ?? 0;
          const migrated = run.counts_by_post_type.migrated ?? 0;
          counts.textContent = `${migrated} / ${total} items migrated`;
        }
        article.appendChild(counts);
      }
      if (run.duration_sec != null) {
        const dur = document.createElement("p");
        dur.className = "efs-log-entry__duration";
        dur.textContent = `Duration: ${formatDuration(run.duration_sec)}`;
        article.appendChild(dur);
      }
      const hasDetails = run.post_type_mappings || run.errors_summary || run.warnings_summary;
      if (hasDetails) {
        const detailsToggle = document.createElement("button");
        detailsToggle.type = "button";
        detailsToggle.className = "efs-logs-filter__btn efs-log-entry__details-toggle";
        detailsToggle.textContent = "Details";
        const details = document.createElement("div");
        details.className = "efs-log-entry__details";
        if (run.post_type_mappings && typeof run.post_type_mappings === "object" && Object.keys(run.post_type_mappings).length > 0) {
          const mappingsLabel = document.createElement("p");
          mappingsLabel.className = "efs-log-entry__mappings-label";
          mappingsLabel.textContent = "Post type mappings:";
          details.appendChild(mappingsLabel);
          const mappingsList = document.createElement("dl");
          mappingsList.className = "efs-log-entry__mappings";
          Object.entries(run.post_type_mappings).forEach(([src, tgt]) => {
            const item = document.createElement("div");
            item.className = "efs-log-entry__context-item";
            const dt = document.createElement("dt");
            dt.textContent = src;
            const dd = document.createElement("dd");
            dd.textContent = `\u2192 ${tgt}`;
            item.appendChild(dt);
            item.appendChild(dd);
            mappingsList.appendChild(item);
          });
          details.appendChild(mappingsList);
        }
        if (run.target_url) {
          const targetP = document.createElement("p");
          targetP.className = "efs-log-entry__target-url";
          targetP.textContent = `Target: ${run.target_url}`;
          details.appendChild(targetP);
        }
        if (run.source_site) {
          const sourceP = document.createElement("p");
          sourceP.className = "efs-log-entry__source-site";
          sourceP.textContent = `Source: ${run.source_site}`;
          details.appendChild(sourceP);
        }
        if (run.errors_summary) {
          const errP = document.createElement("p");
          errP.className = "efs-log-entry__errors-summary";
          errP.textContent = `Errors: ${run.errors_summary}`;
          details.appendChild(errP);
        }
        if (run.warnings_summary) {
          const warnP = document.createElement("p");
          warnP.className = "efs-log-entry__warnings-summary";
          warnP.textContent = `Warnings: ${run.warnings_summary}`;
          details.appendChild(warnP);
        }
        detailsToggle.addEventListener("click", () => {
          const isOpen = details.classList.toggle("is-open");
          detailsToggle.textContent = isOpen ? "Hide details" : "Details";
        });
        article.appendChild(detailsToggle);
        article.appendChild(details);
      }
      container.appendChild(article);
    });
  };
  var applyFilter = () => {
    const container = document.querySelector("[data-efs-logs-list]");
    if (!container) {
      return;
    }
    const allEntries = Array.from(container.querySelectorAll("article.efs-log-entry"));
    let visibleCount = 0;
    allEntries.forEach((entry) => {
      const isMigration = entry.hasAttribute("data-migration-id");
      if (currentFilter === "all") {
        entry.hidden = false;
        visibleCount++;
      } else if (currentFilter === "migration") {
        entry.hidden = !isMigration;
        if (isMigration) visibleCount++;
      } else if (currentFilter === "security") {
        entry.hidden = isMigration;
        if (!isMigration) visibleCount++;
      }
    });
    let emptyState = container.querySelector(".efs-empty-state");
    if (visibleCount === 0 && allEntries.length > 0) {
      if (!emptyState) {
        emptyState = document.createElement("p");
        emptyState.className = "efs-empty-state";
        container.appendChild(emptyState);
      }
      emptyState.hidden = false;
      emptyState.textContent = currentFilter === "migration" ? "No migration runs for this filter." : "No security logs for this filter.";
    } else if (emptyState) {
      emptyState.hidden = visibleCount > 0;
      if (visibleCount === 0) {
        emptyState.textContent = "No logs yet. Migration activity will appear here.";
      }
    }
  };
  var renderAll = () => {
    renderMigrationRuns(allMigrationRuns);
    renderLogs(allSecurityLogs);
    applyFilter();
  };
  var setFilterButton = (filterValue) => {
    const filterContainer = document.querySelector("[data-efs-logs-filter]");
    if (!filterContainer) {
      return;
    }
    filterContainer.querySelectorAll("[data-efs-filter]").forEach((btn) => {
      btn.classList.toggle("is-active", btn.dataset.efsFilter === filterValue);
    });
  };
  var highlightMigrationFromHash = () => {
    if (!pendingHashMigrationId) {
      return;
    }
    currentFilter = "migration";
    setFilterButton("migration");
    applyFilter();
    window.requestAnimationFrame(() => {
      const target = document.querySelector(`article[data-migration-id="${CSS.escape(pendingHashMigrationId)}"]`);
      if (!target) {
        return;
      }
      target.classList.add("is-highlighted");
      target.scrollIntoView({ behavior: "smooth", block: "center" });
      pendingHashMigrationId = "";
    });
  };
  var fetchLogs = async () => {
    try {
      const data = await post(ACTION_FETCH_LOGS);
      allSecurityLogs = data?.security_logs || [];
      allMigrationRuns = data?.migration_runs || [];
      renderAll();
      highlightMigrationFromHash();
    } catch (err) {
      console.error("Fetch logs failed", err);
      showToast(err.message, "error");
    }
  };
  var clearLogs = async () => {
    try {
      const data = await post(ACTION_CLEAR_LOGS);
      showToast(data?.message || "Logs cleared.", "success");
      allSecurityLogs = [];
      allMigrationRuns = [];
      stopAutoRefreshLogs();
      renderAll();
    } catch (err) {
      console.error("Clear logs failed", err);
      showToast(err.message, "error");
    }
  };
  var startAutoRefreshLogs = (intervalMs = 5e3) => {
    stopAutoRefreshLogs();
    refreshTimer = window.setInterval(fetchLogs, intervalMs);
  };
  var stopAutoRefreshLogs = () => {
    if (refreshTimer) {
      window.clearInterval(refreshTimer);
      refreshTimer = null;
    }
  };
  var bindLogControls = () => {
    const clearButton = document.querySelector("[data-efs-clear-logs]");
    clearButton?.addEventListener("click", clearLogs);
    const filterContainer = document.querySelector("[data-efs-logs-filter]");
    if (filterContainer) {
      filterContainer.querySelectorAll("[data-efs-filter]").forEach((btn) => {
        btn.addEventListener("click", () => {
          currentFilter = btn.dataset.efsFilter || "all";
          filterContainer.querySelectorAll("[data-efs-filter]").forEach((b) => b.classList.remove("is-active"));
          btn.classList.add("is-active");
          applyFilter();
        });
      });
    }
  };
  var hydrateInitialLogs = () => {
    allSecurityLogs = getInitialData("logs", []);
    allMigrationRuns = getInitialData("migration_runs", []);
    renderAll();
  };
  var initLogs = () => {
    hydrateInitialLogs();
    bindLogControls();
    const hash = window.location.hash;
    if (hash.startsWith("#migration-")) {
      pendingHashMigrationId = hash.slice("#migration-".length);
      highlightMigrationFromHash();
    }
    fetchLogs();
  };

  // assets/js/admin/etch-dashboard.js
  var bindPairingCodeButton = () => {
    const btn = document.querySelector("[data-efs-generate-pairing-code]");
    const result = document.querySelector("[data-efs-pairing-code-result]");
    const display = document.querySelector("[data-efs-pairing-code-display]");
    const copyBtn = document.querySelector("[data-efs-copy-pairing-code]");
    const expiry = document.querySelector("[data-efs-pairing-code-expiry]");
    if (!btn) {
      return;
    }
    btn.addEventListener("click", async () => {
      setLoading(btn, true);
      try {
        const data = await post("efs_generate_pairing_code");
        const siteUrl = (window.efsData?.site_url || "").replace(/\/$/, "");
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
          expiry.textContent = `Expires in ${m}:${String(s).padStart(2, "0")}`;
          remaining--;
          setTimeout(tick, 1e3);
        };
        tick();
        showToast("Connection URL generated. Valid for 15 minutes.", "success");
      } catch (err) {
        showToast(err?.message || "Failed to generate connection URL.", "error");
      } finally {
        setLoading(btn, false);
      }
    });
    copyBtn?.addEventListener("click", () => {
      const url = display?.textContent || "";
      if (navigator?.clipboard?.writeText) {
        navigator.clipboard.writeText(url);
      }
      showToast("Copied!", "success");
    });
  };
  var initEtchDashboard = () => {
    const root = document.querySelector("[data-efs-etch-dashboard]");
    if (!root) {
      return;
    }
    bindPairingCodeButton();
  };

  // assets/js/admin/utilities/perf-metrics.js
  var PerfMetrics = class {
    constructor() {
      this.enabled = this.isLocalhost();
      this.reset();
    }
    isLocalhost() {
      const host = window?.location?.hostname || "";
      return host === "localhost" || host === "127.0.0.1";
    }
    reset() {
      this.discoveryStartTime = 0;
      this.discoveryDuration = 0;
      this.migrationStartTime = 0;
      this.migrationDuration = 0;
      this.batchTimes = [];
      this.totalItems = 0;
      this.processedItems = 0;
    }
    startDiscovery() {
      if (!this.enabled) {
        return;
      }
      this.discoveryStartTime = performance.now();
      console.log("[EFS][Perf] Discovery started");
    }
    endDiscovery() {
      if (!this.enabled || !this.discoveryStartTime) {
        return;
      }
      this.discoveryDuration = performance.now() - this.discoveryStartTime;
      console.log("[EFS][Perf] Discovery completed", {
        durationMs: Math.round(this.discoveryDuration)
      });
    }
    startMigration(totalItems = 0) {
      if (!this.enabled) {
        return;
      }
      this.migrationStartTime = performance.now();
      this.migrationDuration = 0;
      this.batchTimes = [];
      this.totalItems = Number(totalItems || 0);
      this.processedItems = 0;
      console.log("[EFS][Perf] Migration started", {
        totalItems: this.totalItems
      });
    }
    recordBatch(itemsProcessed = 0, batchDuration = 0) {
      if (!this.enabled) {
        return;
      }
      const normalizedDuration = Number(batchDuration || 0);
      const normalizedItems = Number(itemsProcessed || 0);
      this.batchTimes.push(normalizedDuration);
      this.processedItems += normalizedItems;
      console.log("[EFS][Perf] Batch processed", {
        itemsProcessed: normalizedItems,
        batchDurationMs: Math.round(normalizedDuration),
        totalProcessed: this.processedItems,
        totalItems: this.totalItems
      });
    }
    endMigration() {
      if (!this.enabled || !this.migrationStartTime) {
        return;
      }
      this.migrationDuration = performance.now() - this.migrationStartTime;
      const averageBatchTime = this.batchTimes.length ? this.batchTimes.reduce((sum, value) => sum + value, 0) / this.batchTimes.length : 0;
      console.log("[EFS][Perf] Migration completed", {
        durationMs: Math.round(this.migrationDuration),
        batches: this.batchTimes.length,
        avgBatchMs: Math.round(averageBatchTime),
        processedItems: this.processedItems,
        totalItems: this.totalItems
      });
    }
    getBottleneckHints() {
      if (!this.enabled || !this.batchTimes.length) {
        return [];
      }
      const hints = [];
      const averageBatchTime = this.batchTimes.reduce((sum, value) => sum + value, 0) / this.batchTimes.length;
      if (averageBatchTime > 5e3) {
        hints.push(`Average batch time is high (${Math.round(averageBatchTime)}ms).`);
      }
      const slowBatchIndexes = [];
      this.batchTimes.forEach((time, index) => {
        if (time > averageBatchTime * 2) {
          slowBatchIndexes.push(index + 1);
        }
      });
      if (slowBatchIndexes.length) {
        hints.push(`Slow batches detected: ${slowBatchIndexes.join(", ")}.`);
      }
      return hints;
    }
  };
  var perfMetrics = new PerfMetrics();

  // assets/js/admin/utilities/tab-title.js
  var updateTabTitle = (progress, status) => {
    if (progress === null || progress === void 0) {
      return;
    }
    const rounded = Math.round(Number(progress) || 0);
    const label = status === null || status === void 0 || status === "" ? "Migration running" : String(status);
    document.title = `${rounded}% \u2013 ${label} \u2013 EtchFusion Suite`;
  };
  var resetTabTitle = () => {
    document.title = "EtchFusion Suite";
  };

  // assets/js/admin/utilities/progress-chip.js
  var createProgressChip = (container) => {
    if (!container) {
      return null;
    }
    const chip = document.createElement("div");
    chip.className = "efs-wizard-progress-chip";
    chip.setAttribute("data-efs-progress-chip", "");
    chip.setAttribute("role", "button");
    chip.setAttribute("tabindex", "0");
    chip.innerHTML = `
		<span class="efs-wizard-progress-chip__icon" aria-hidden="true"></span>
		<span class="efs-wizard-progress-chip__text">Migration running: 0%</span>
	`;
    container.appendChild(chip);
    return chip;
  };
  var updateProgressChip = (chip, progress) => {
    if (!chip) {
      return;
    }
    const text = chip.querySelector(".efs-wizard-progress-chip__text");
    if (text) {
      text.textContent = `Migration running: ${Math.round(Number(progress) || 0)}%`;
    }
  };
  var removeProgressChip = (chip) => {
    chip?.remove();
  };

  // assets/js/admin/utilities/time-format.js
  var formatElapsed = (seconds) => {
    if (seconds == null || typeof seconds !== "number" || seconds < 0 || Number.isNaN(seconds)) {
      return "00:00";
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
  };
  var formatEta = (seconds) => {
    if (seconds == null || seconds === 0 || typeof seconds !== "number" || Number.isNaN(seconds) || seconds < 0) {
      return null;
    }
    if (seconds < 60) {
      return "< 1m remaining";
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    if (secs === 0) {
      return `~${mins}m remaining`;
    }
    return `~${mins}m ${secs}s remaining`;
  };

  // assets/js/admin/bricks-wizard.js
  var ACTION_VALIDATE_URL = "efs_wizard_validate_url";
  var ACTION_VALIDATE_TOKEN2 = "efs_validate_migration_token";
  var ACTION_SAVE_STATE = "efs_wizard_save_state";
  var ACTION_GET_STATE = "efs_wizard_get_state";
  var ACTION_CLEAR_STATE = "efs_wizard_clear_state";
  var ACTION_DISCOVER_CONTENT = "efs_get_bricks_posts";
  var ACTION_GET_TARGET_POST_TYPES = "efs_get_target_post_types";
  var ACTION_START_MIGRATION2 = "efs_start_migration";
  var ACTION_GET_PROGRESS2 = "efs_get_migration_progress";
  var ACTION_CANCEL_MIGRATION2 = "efs_cancel_migration";
  var ACTION_MIGRATE_BATCH = "efs_migrate_batch";
  var ACTION_RESUME_MIGRATION = "efs_resume_migration";
  var ACTION_GET_CSS_PREVIEW = "efs_get_css_preview";
  var ACTION_RUN_PREFLIGHT = "efs_run_preflight_check";
  var ACTION_INVALIDATE_PREFLIGHT = "efs_invalidate_preflight_cache";
  var BATCH_SIZE = 10;
  var POLL_INTERVAL_MS = 3e3;
  var STEP_COUNT = 4;
  var DISCOVERY_PLACEHOLDER_ROW = '<tr><td colspan="5">Discovery has not started yet.</td></tr>';
  var defaultState = () => ({
    currentStep: 1,
    migrationUrl: "",
    migrationKey: "",
    targetUrl: "",
    discoveryData: null,
    /** @type {{ slug: string, label: string }[]} Post types from Etch (target) site for mapping dropdown. */
    targetPostTypes: [],
    selectedPostTypes: [],
    postTypeMappings: {},
    includeMedia: true,
    restrictCssToUsed: true,
    batchSize: 50,
    migrationId: "",
    progressMinimized: false,
    preflight: null,
    preflightConfirmed: false,
    mode: "browser",
    actionSchedulerId: null
  });
  var humanize = (value) => String(value || "").replace(/[_-]+/g, " ").replace(/\b\w/g, (char) => char.toUpperCase()).trim();
  var parseMigrationUrl = (rawUrl) => {
    if (!rawUrl || typeof rawUrl !== "string") {
      throw new Error("Migration key is required.");
    }
    let parsed;
    try {
      parsed = new URL(rawUrl.trim());
    } catch {
      throw new Error("Migration key format is invalid.");
    }
    const token = parsed.searchParams.get("token") || parsed.searchParams.get("migration_key") || parsed.searchParams.get("key") || "";
    if (!token) {
      throw new Error("Migration key does not contain a token.");
    }
    return {
      token,
      origin: parsed.origin,
      normalizedUrl: parsed.toString()
    };
  };
  var normalizeSteps2 = (steps) => {
    if (Array.isArray(steps)) {
      return steps;
    }
    if (steps && typeof steps === "object") {
      return Object.entries(steps).map(([slug, data]) => ({
        slug,
        ...data || {}
      }));
    }
    return [];
  };
  var getDiscoveryPostTypes = (discoveryData) => discoveryData?.postTypes || discoveryData?.post_types || [];
  var getDiscoverySummary = (discoveryData) => discoveryData?.summary || null;
  var createWizard = (root) => {
    const refs = {
      stepNav: Array.from(root.querySelectorAll("[data-efs-step-nav]")),
      stepPanels: Array.from(root.querySelectorAll("[data-efs-step-panel]")),
      backButton: root.querySelector("[data-efs-wizard-back]"),
      nextButton: root.querySelector("[data-efs-wizard-next]"),
      cancelButton: root.querySelector("[data-efs-wizard-cancel]"),
      urlInput: root.querySelector("[data-efs-wizard-url]"),
      pasteButton: root.querySelector("[data-efs-paste-migration-url]"),
      keyInput: root.querySelector("[data-efs-wizard-migration-key]"),
      connectMessage: root.querySelector("[data-efs-connect-message]"),
      selectMessage: root.querySelector("[data-efs-select-message]"),
      discoveryLoading: root.querySelector("[data-efs-discovery-loading]"),
      discoverySummary: root.querySelector("[data-efs-discovery-summary]"),
      summaryGrade: root.querySelector("[data-efs-summary-grade]"),
      summaryBreakdown: root.querySelector("[data-efs-summary-breakdown]"),
      progressChipContainer: root.querySelector("[data-efs-progress-chip-container]"),
      runFullAnalysis: root.querySelector("[data-efs-run-full-analysis]"),
      rowsBody: root.querySelector("[data-efs-post-type-rows]"),
      includeMedia: root.querySelector("[data-efs-include-media]"),
      restrictCssToUsed: root.querySelector("[data-efs-restrict-css]"),
      previewBreakdown: root.querySelector("[data-efs-preview-breakdown]"),
      cssPreview: root.querySelector("[data-efs-css-preview]"),
      previewWarnings: root.querySelector("[data-efs-preview-warnings]"),
      warningList: root.querySelector("[data-efs-warning-list]"),
      progressTakeover: root.querySelector("[data-efs-progress-takeover]"),
      progressPanel: root.querySelector("[data-efs-progress-takeover] .efs-wizard-progress__panel"),
      progressBanner: root.querySelector("[data-efs-progress-banner]"),
      progressFill: root.querySelector("[data-efs-wizard-progress-fill]"),
      progressPercent: root.querySelector("[data-efs-wizard-progress-percent]"),
      progressStatus: root.querySelector("[data-efs-wizard-progress-status]"),
      progressItems: root.querySelector("[data-efs-wizard-items]"),
      progressElapsed: root.querySelector("[data-efs-wizard-elapsed]"),
      progressSteps: root.querySelector("[data-efs-wizard-step-status]"),
      retryButton: root.querySelector("[data-efs-retry-migration]"),
      progressCancelButton: root.querySelector("[data-efs-progress-cancel]"),
      minimizeButtons: Array.from(root.querySelectorAll("[data-efs-minimize-progress]")),
      expandButton: root.querySelector("[data-efs-expand-progress]"),
      bannerText: root.querySelector("[data-efs-banner-text]"),
      result: root.querySelector("[data-efs-wizard-result]"),
      resultIcon: root.querySelector("[data-efs-result-icon]"),
      resultTitle: root.querySelector("[data-efs-result-title]"),
      resultSubtitle: root.querySelector("[data-efs-result-subtitle]"),
      openLogsButton: root.querySelector("[data-efs-open-logs]"),
      startNewButton: root.querySelector("[data-efs-start-new]"),
      presets: Array.from(root.querySelectorAll("[data-efs-preset]")),
      preflightContainer: root.querySelector("[data-efs-preflight]"),
      preflightLoading: root.querySelector("[data-efs-preflight-loading]"),
      preflightResults: root.querySelector("[data-efs-preflight-results]"),
      preflightActions: root.querySelector("[data-efs-preflight-actions]"),
      preflightOverride: root.querySelector("[data-efs-preflight-override]"),
      preflightConfirm: root.querySelector("[data-efs-preflight-confirm]"),
      preflightRecheck: root.querySelector("[data-efs-preflight-recheck]"),
      modeRadios: Array.from(root.querySelectorAll("[data-efs-mode-radio]")),
      cronIndicator: root.querySelector("[data-efs-cron-indicator]"),
      headlessScreen: root.querySelector("[data-efs-headless-screen]"),
      headlessProgressFill: root.querySelector("[data-efs-headless-progress-fill]"),
      headlessProgressPercent: root.querySelector("[data-efs-headless-progress-percent]"),
      headlessStatus: root.querySelector("[data-efs-headless-status]"),
      headlessSource: root.querySelector("[data-efs-headless-source]"),
      headlessItems: root.querySelector("[data-efs-headless-items]"),
      headlessElapsed: root.querySelector("[data-efs-headless-elapsed]"),
      cancelHeadlessButton: root.querySelector("[data-efs-cancel-headless]")
    };
    const wizardNonce = root.getAttribute("data-efs-state-nonce") || window.efsData?.nonce || "";
    const state = defaultState();
    state.migrationUrl = refs.urlInput?.value?.trim() || "";
    state.migrationKey = refs.keyInput?.value?.trim() || "";
    const runtime = {
      discoveryLoaded: false,
      validatedMigrationUrl: "",
      validatingConnect: false,
      pollingTimer: null,
      migrationRunning: false,
      progressChip: null,
      lastProgressPercentage: 0,
      lastProcessedCount: void 0,
      lastPollTime: void 0
    };
    const setMessage = (el, message, level = "info") => {
      if (!el) {
        return;
      }
      if (!message) {
        el.hidden = true;
        el.textContent = "";
        el.classList.remove("is-error", "is-success", "is-warning");
        return;
      }
      el.hidden = false;
      el.textContent = message;
      el.classList.remove("is-error", "is-success", "is-warning");
      if (level === "error") {
        el.classList.add("is-error");
      } else if (level === "success") {
        el.classList.add("is-success");
      } else if (level === "warning") {
        el.classList.add("is-warning");
      }
    };
    const PREFLIGHT_HINTS = {
      memory: "PHP memory_limit is below 64 MB. Contact your hosting provider to increase it.",
      target_reachable: "The target site is not reachable. Check the URL and ensure the site is online.",
      wp_cron: "WP Cron is disabled (DISABLE_WP_CRON). Switch to Browser Mode or enable WP Cron.",
      memory_warning: "Memory is limited. Large migrations may fail. Consider increasing memory_limit.",
      execution_time: "max_execution_time is low. Batch size will be reduced automatically.",
      disk_space: "Target site has less than 500 MB free disk space."
    };
    const renderPreflightUI = (result) => {
      if (refs.preflightLoading) {
        refs.preflightLoading.hidden = true;
      }
      if (refs.preflightResults) {
        refs.preflightResults.hidden = false;
      }
      if (refs.preflightActions) {
        refs.preflightActions.hidden = false;
      }
      if (!result || !Array.isArray(result.checks)) {
        if (refs.preflightResults) {
          refs.preflightResults.innerHTML = "<p>Environment check failed &ndash; please try again.</p>";
        }
        updateNavigationState();
        return;
      }
      const rows = result.checks.map((check) => {
        const hint = check.status === "error" || check.status === "warning" ? PREFLIGHT_HINTS[check.id] || "" : "";
        const hintHtml = hint ? `<span class="efs-preflight__hint">${hint}</span>` : "";
        return `<div class="efs-preflight__row">
				<span class="efs-preflight__badge efs-preflight__badge--${check.status}">${check.status}</span>
				<div class="efs-preflight__row-content">
					<span>${check.message || check.id}</span>
					${hintHtml}
				</div>
			</div>`;
      }).join("");
      const errorCount = result.checks.filter((c) => c.status === "error").length;
      const warnCount = result.checks.filter((c) => c.status === "warning").length;
      let barClass = "";
      let barText = "All checks passed.";
      if (result.has_hard_block) {
        barClass = "efs-preflight__bar--error";
        barText = `${errorCount} error${errorCount !== 1 ? "s" : ""} &ndash; migration blocked.`;
      } else if (result.has_soft_block) {
        barClass = "efs-preflight__bar--warn";
        barText = `${warnCount} warning${warnCount !== 1 ? "s" : ""} detected.`;
      }
      const barHtml = `<div class="efs-preflight__bar ${barClass}">${barText}</div>`;
      if (refs.preflightResults) {
        refs.preflightResults.innerHTML = rows + barHtml;
      }
      if (refs.preflightOverride) {
        refs.preflightOverride.hidden = !(result.has_soft_block && !result.has_hard_block);
      }
      updateNavigationState();
      const wpCronResult = result.checks?.find((c) => c.id === "wp_cron");
      const headlessRadio = refs.modeRadios.find((r) => r instanceof HTMLInputElement && r.value === "headless");
      if (headlessRadio) {
        if (wpCronResult && wpCronResult.status !== "ok") {
          headlessRadio.disabled = true;
          if (state.mode === "headless") {
            state.mode = "browser";
            refs.modeRadios.forEach((r) => {
              if (r instanceof HTMLInputElement) {
                r.checked = r.value === "browser";
              }
              const modeLabel = r?.closest("[data-efs-mode-option]");
              if (modeLabel) {
                modeLabel.classList.toggle("efs-mode-option--selected", r instanceof HTMLInputElement && r.value === "browser");
              }
            });
          }
          if (refs.cronIndicator) {
            refs.cronIndicator.hidden = false;
            refs.cronIndicator.textContent = "\u26A0 WP Cron not available";
          }
        } else {
          headlessRadio.disabled = false;
        }
      }
    };
    const runPreflightCheck = async (targetUrl = "", mode = "browser") => {
      if (refs.preflightLoading) {
        refs.preflightLoading.hidden = false;
      }
      if (refs.preflightResults) {
        refs.preflightResults.hidden = true;
      }
      if (refs.preflightActions) {
        refs.preflightActions.hidden = true;
      }
      try {
        const result = await post(ACTION_RUN_PREFLIGHT, { target_url: targetUrl, mode });
        state.preflight = result;
        renderPreflightUI(result);
      } catch {
        if (refs.preflightLoading) {
          refs.preflightLoading.hidden = true;
        }
        if (refs.preflightResults) {
          refs.preflightResults.hidden = false;
          refs.preflightResults.innerHTML = "<p>Environment check failed &ndash; please try again.</p>";
        }
        updateNavigationState();
      }
    };
    const invalidateAndRecheck = async () => {
      try {
        await post(ACTION_INVALIDATE_PREFLIGHT, {});
      } catch (err) {
        console.warn("[EFS] Preflight cache invalidation failed", err);
      }
      await runPreflightCheck(state.targetUrl || "", state.mode || "browser");
    };
    const hasValidStep2Selection = () => {
      if (!state.discoveryData || !getDiscoveryPostTypes(state.discoveryData).length) {
        return false;
      }
      return state.selectedPostTypes.some((slug) => Boolean(state.postTypeMappings[slug]));
    };
    const validatePostTypeMappings = () => {
      const errors = [];
      const availableTargetSlugs = state.targetPostTypes.map((pt) => pt.slug);
      state.selectedPostTypes.forEach((sourceSlug) => {
        const targetSlug = state.postTypeMappings[sourceSlug];
        if (!targetSlug || targetSlug === "") {
          errors.push(`Missing mapping for "${humanize(sourceSlug)}"`);
          return;
        }
        if (!availableTargetSlugs.includes(targetSlug)) {
          errors.push(`Invalid mapping for "${humanize(sourceSlug)}" -> "${humanize(targetSlug)}" (not available on target)`);
        }
      });
      return errors;
    };
    const updateNavigationState = () => {
      if (refs.backButton) {
        refs.backButton.disabled = state.currentStep <= 1 || state.currentStep >= 5;
      }
      if (refs.nextButton) {
        let disabled = false;
        let label = "Next";
        if (state.currentStep === 1) {
          disabled = !!state.preflight?.has_hard_block;
          if (state.preflight?.has_soft_block && !state.preflight?.has_hard_block && !state.preflightConfirmed) {
            disabled = true;
          }
          label = "Next";
        } else if (state.currentStep === 2) {
          disabled = !state.migrationUrl || runtime.validatingConnect;
          label = runtime.validatingConnect ? "Validating..." : "Next";
          if (state.preflight?.has_hard_block) {
            disabled = true;
          }
          if (state.preflight?.has_soft_block && !state.preflight?.has_hard_block && !state.preflightConfirmed) {
            disabled = true;
          }
        } else if (state.currentStep === 3) {
          disabled = !hasValidStep2Selection();
          label = "Next";
        } else if (state.currentStep === 4) {
          disabled = runtime.migrationRunning;
          label = runtime.migrationRunning ? "Starting migration\u2026" : "Confirm & Start Migration";
        } else {
          disabled = true;
          label = "Migration Running";
        }
        refs.nextButton.disabled = disabled;
        refs.nextButton.textContent = label;
        refs.nextButton.classList.toggle("efs-wizard-next--validating", state.currentStep === 2 && runtime.validatingConnect);
        refs.nextButton.classList.toggle("efs-wizard-next--loading", state.currentStep === 4 && runtime.migrationRunning);
        refs.nextButton.setAttribute("aria-busy", state.currentStep === 4 && runtime.migrationRunning ? "true" : "false");
      }
    };
    const renderStepShell = () => {
      refs.stepNav.forEach((stepButton) => {
        const step = Number(stepButton.getAttribute("data-efs-step-nav") || "1");
        stepButton.classList.toggle("is-active", step === state.currentStep);
        stepButton.classList.toggle("is-complete", step < state.currentStep);
        stepButton.classList.toggle("is-clickable", step < state.currentStep && state.currentStep < 5);
        if (step === state.currentStep) {
          stepButton.setAttribute("aria-current", "step");
        } else {
          stepButton.removeAttribute("aria-current");
        }
      });
      refs.stepPanels.forEach((panel) => {
        const step = Number(panel.getAttribute("data-efs-step-panel") || "1");
        const active = step === state.currentStep;
        panel.classList.toggle("is-active", active);
        panel.hidden = !active;
      });
      updateNavigationState();
    };
    const saveWizardState = async () => {
      try {
        await post(ACTION_SAVE_STATE, {
          wizard_nonce: wizardNonce,
          state: JSON.stringify({
            current_step: state.currentStep,
            migration_url: state.migrationUrl,
            migration_key: state.migrationKey,
            target_url: state.targetUrl,
            discovery_data: state.discoveryData || {},
            selected_post_types: state.selectedPostTypes,
            post_type_mappings: state.postTypeMappings,
            include_media: state.includeMedia,
            restrict_css_to_used: state.restrictCssToUsed,
            batch_size: state.batchSize,
            mode: state.mode || "browser"
          })
        });
      } catch (error) {
        console.warn("[EFS] Failed to persist wizard state", error);
      }
    };
    const clearWizardState = async () => {
      try {
        await post(ACTION_CLEAR_STATE, {
          wizard_nonce: wizardNonce
        });
      } catch (error) {
        console.warn("[EFS] Failed to clear wizard state", error);
      }
    };
    const renderSummary = () => {
      const summary = getDiscoverySummary(state.discoveryData);
      if (!summary || !refs.discoverySummary || !refs.summaryGrade || !refs.summaryBreakdown) {
        return;
      }
      refs.discoverySummary.hidden = false;
      refs.summaryGrade.textContent = summary.label;
      refs.summaryGrade.classList.remove("is-green", "is-yellow", "is-red");
      refs.summaryGrade.classList.add(`is-${summary.grade}`);
      refs.summaryBreakdown.innerHTML = "";
      (summary.breakdown || []).forEach((item) => {
        const entry = document.createElement("div");
        entry.className = "efs-wizard-summary__item";
        entry.innerHTML = `
				<span class="efs-wizard-summary__label">${item.label}</span>
				<span class="efs-wizard-summary__value">${item.value}</span>
			`;
        refs.summaryBreakdown.appendChild(entry);
      });
    };
    const getAvailableMappingOptions = () => {
      const list = state.targetPostTypes && state.targetPostTypes.length ? state.targetPostTypes.map((pt) => pt.slug) : ["post", "page"];
      return Array.from(new Set(list)).filter(Boolean);
    };
    const getTargetPostTypeLabels = () => {
      if (state.targetPostTypes && state.targetPostTypes.length) {
        return Object.fromEntries(state.targetPostTypes.map((pt) => [pt.slug, pt.label]));
      }
      return { post: "Posts", page: "Pages" };
    };
    const renderPostTypeTable = () => {
      if (!refs.rowsBody) {
        return;
      }
      const normalizedPostTypes = getDiscoveryPostTypes(state.discoveryData);
      if (!normalizedPostTypes.length) {
        refs.rowsBody.innerHTML = '<tr><td colspan="5">No content found for migration.</td></tr>';
        return;
      }
      const options = getAvailableMappingOptions();
      const labels = getTargetPostTypeLabels();
      const rowsMarkup = normalizedPostTypes.map((postType) => {
        const checked = state.selectedPostTypes.includes(postType.slug);
        const selectedMapping = state.postTypeMappings[postType.slug] || "";
        const optionsMarkup = options.map((slug) => {
          const selected = slug === selectedMapping ? " selected" : "";
          const label = labels[slug] || humanize(slug);
          return `<option value="${slug}"${selected}>${label}</option>`;
        }).join("");
        const disabled = checked ? "" : " disabled";
        const checkedAttr = checked ? " checked" : "";
        const rowStateClass = checked ? "is-active" : "is-inactive";
        return `
				<tr class="${rowStateClass}" data-efs-post-type-row="${postType.slug}">
					<td><input type="checkbox" data-efs-post-type-check="${postType.slug}"${checkedAttr}></td>
					<td>${postType.label}</td>
					<td>${postType.count}</td>
					<td>${postType.customFields}</td>
					<td>
						<select data-efs-post-type-map="${postType.slug}"${disabled}>
							<option value="">Select post type...</option>
							${optionsMarkup}
						</select>
					</td>
				</tr>
			`;
      }).join("");
      refs.rowsBody.innerHTML = rowsMarkup;
    };
    const resetDiscoveryUi = () => {
      if (refs.discoveryLoading) {
        refs.discoveryLoading.hidden = true;
      }
      if (refs.discoverySummary) {
        refs.discoverySummary.hidden = true;
      }
      if (refs.summaryGrade) {
        refs.summaryGrade.textContent = "";
        refs.summaryGrade.classList.remove("is-green", "is-yellow", "is-red");
      }
      if (refs.summaryBreakdown) {
        refs.summaryBreakdown.innerHTML = "";
      }
      if (refs.rowsBody) {
        refs.rowsBody.innerHTML = DISCOVERY_PLACEHOLDER_ROW;
      }
      if (refs.previewBreakdown) {
        refs.previewBreakdown.innerHTML = "";
      }
      if (refs.previewWarnings) {
        refs.previewWarnings.hidden = true;
      }
      if (refs.warningList) {
        refs.warningList.innerHTML = "";
      }
    };
    const resetDiscoveryState = ({ clearConnection = false } = {}) => {
      state.discoveryData = null;
      state.selectedPostTypes = [];
      state.postTypeMappings = {};
      state.targetPostTypes = [];
      runtime.discoveryLoaded = false;
      setMessage(refs.selectMessage, "");
      resetDiscoveryUi();
      if (clearConnection) {
        state.migrationKey = "";
        state.targetUrl = "";
        state.targetPostTypes = [];
        runtime.validatedMigrationUrl = "";
        if (refs.keyInput) {
          refs.keyInput.value = "";
        }
      }
    };
    const buildDiscoveryData = (response) => {
      const posts = Array.isArray(response?.posts) ? response.posts : [];
      const grouped = /* @__PURE__ */ new Map();
      posts.forEach((item) => {
        const type = String(item?.type || "").trim();
        if (!type || type === "attachment") {
          return;
        }
        const existing = grouped.get(type) || {
          slug: type,
          label: humanize(type),
          count: 0,
          customFields: 0,
          hasBricks: false
        };
        existing.count += 1;
        existing.hasBricks = existing.hasBricks || Boolean(item?.has_bricks);
        grouped.set(type, existing);
      });
      const postTypes = Array.from(grouped.values()).sort((a, b) => b.count - a.count);
      const bricksCount = Number(response?.bricks_count || 0);
      const gutenbergCount = Number(response?.gutenberg_count || 0);
      const mediaCount = Number(response?.media_count || 0);
      const totalContent = Math.max(bricksCount + gutenbergCount, 1);
      const nonBricksRatio = gutenbergCount / totalContent;
      let grade = "green";
      if (nonBricksRatio > 0.35) {
        grade = "red";
      } else if (nonBricksRatio > 0.15) {
        grade = "yellow";
      }
      const gradeLabelMap = {
        green: "High convertibility detected (Green)",
        yellow: "Mixed convertibility detected (Yellow)",
        red: "Low convertibility detected (Red)"
      };
      return {
        postTypes,
        summary: {
          grade,
          label: gradeLabelMap[grade],
          breakdown: [
            { label: "Bricks entries", value: bricksCount },
            { label: "Non-Bricks entries", value: gutenbergCount },
            { label: "Media items", value: mediaCount }
          ]
        },
        raw: {
          bricksCount,
          gutenbergCount,
          mediaCount
        }
      };
    };
    const applyDefaultSelections = () => {
      const postTypes = getDiscoveryPostTypes(state.discoveryData);
      if (!postTypes.length) {
        return;
      }
      if (!state.selectedPostTypes.length) {
        state.selectedPostTypes = postTypes.map((item) => item.slug);
      }
      const etchSlugs = getAvailableMappingOptions();
      const defaultMap = (bricksSlug) => {
        if (etchSlugs.includes(bricksSlug)) {
          return bricksSlug;
        }
        if (bricksSlug === "bricks_template" && etchSlugs.includes("etch_template")) {
          return "etch_template";
        }
        if (etchSlugs.includes("post")) {
          return "post";
        }
        return etchSlugs[0] || "";
      };
      postTypes.forEach((item) => {
        if (!state.postTypeMappings[item.slug]) {
          state.postTypeMappings[item.slug] = defaultMap(item.slug);
        }
      });
    };
    const fetchTargetPostTypes = async () => {
      if (state.targetPostTypes && state.targetPostTypes.length) {
        return;
      }
      if (!state.targetUrl || !state.migrationKey) {
        return;
      }
      try {
        const result = await post(ACTION_GET_TARGET_POST_TYPES, {
          target_url: state.targetUrl,
          migration_key: state.migrationKey
        });
        const list = result?.post_types;
        if (Array.isArray(list) && list.length) {
          state.targetPostTypes = list;
        } else {
          state.targetPostTypes = [{ slug: "post", label: "Posts" }, { slug: "page", label: "Pages" }];
        }
      } catch {
        state.targetPostTypes = [{ slug: "post", label: "Posts" }, { slug: "page", label: "Pages" }];
      }
    };
    const runDiscovery = async () => {
      if (runtime.discoveryLoaded) {
        return;
      }
      perfMetrics.startDiscovery();
      if (refs.discoveryLoading) {
        refs.discoveryLoading.hidden = false;
      }
      setMessage(refs.selectMessage, "");
      try {
        await fetchTargetPostTypes();
        const result = await post(ACTION_DISCOVER_CONTENT, {});
        state.discoveryData = buildDiscoveryData(result);
        applyDefaultSelections();
        renderSummary();
        renderPostTypeTable();
        showToast("Discovery complete", "success");
        runtime.discoveryLoaded = true;
        perfMetrics.endDiscovery();
        if (refs.discoveryLoading) {
          refs.discoveryLoading.hidden = true;
        }
        updateNavigationState();
        await saveWizardState();
      } catch (error) {
        setMessage(refs.selectMessage, error?.message || "Discovery failed. Try again.", "error");
      } finally {
        if (refs.discoveryLoading) {
          refs.discoveryLoading.hidden = true;
        }
      }
    };
    const renderPreview = async () => {
      if (!refs.previewBreakdown) {
        return;
      }
      const postTypes = getDiscoveryPostTypes(state.discoveryData);
      const selected = postTypes.filter((item) => state.selectedPostTypes.includes(item.slug));
      if (!selected.length) {
        refs.previewBreakdown.innerHTML = "<p>No post types selected.</p>";
        if (refs.cssPreview) {
          refs.cssPreview.hidden = true;
        }
        if (refs.previewWarnings) {
          refs.previewWarnings.hidden = true;
        }
        if (refs.warningList) {
          refs.warningList.innerHTML = "";
        }
        return;
      }
      const breakdown = selected.map((item) => {
        const mapped = state.postTypeMappings[item.slug] || "Unmapped";
        return `<li><strong>${item.label}</strong>: ${item.count} -> ${humanize(mapped)}</li>`;
      }).join("");
      const totalSelectedCount = selected.reduce((sum, item) => sum + item.count, 0);
      refs.previewBreakdown.innerHTML = `
			<ul class="efs-wizard-preview-list">${breakdown}</ul>
			<p><strong>Total selected items:</strong> ${totalSelectedCount}</p>
			<p><strong>Media:</strong> ${state.includeMedia ? "Included" : "Excluded"}</p>
			<p><strong>Custom fields summary:</strong> ${selected.reduce((sum, item) => sum + item.customFields, 0)} detected groups.</p>
		`;
      const warnings = [];
      const selectedTargets = selected.map((item) => state.postTypeMappings[item.slug]).filter(Boolean);
      const duplicateTargets = selectedTargets.filter((target, index, all) => all.indexOf(target) !== index);
      if (duplicateTargets.length > 0) {
        warnings.push({
          level: "info",
          text: "More than one source post type maps to the same destination (e.g. Bricks Template and Post \u2192 Posts). This is valid; all selected content will be migrated into that type."
        });
      }
      if (selected.some((item) => item.slug.includes("product") || item.slug.includes("woocommerce"))) {
        warnings.push({
          level: "warning",
          text: "WooCommerce-related post types were selected. Verify compatibility before migration."
        });
      }
      if ((getDiscoverySummary(state.discoveryData)?.grade || "") === "red") {
        warnings.push({
          level: "error",
          text: "Discovery indicates low convertibility. Unconvertible dynamic data may need manual cleanup."
        });
      }
      if (!refs.previewWarnings || !refs.warningList) {
        return;
      }
      if (!warnings.length) {
        refs.previewWarnings.hidden = true;
        refs.warningList.innerHTML = "";
        return;
      }
      refs.previewWarnings.hidden = false;
      refs.warningList.innerHTML = warnings.map((warning) => `<li class="is-${warning.level}">${warning.text}</li>`).join("");
      if (refs.cssPreview) {
        try {
          const cssData = await post(ACTION_GET_CSS_PREVIEW, {
            selected_post_types: state.selectedPostTypes,
            restrict_css_to_used: state.restrictCssToUsed ? "1" : "0"
          });
          const total = cssData?.css_classes_total ?? 0;
          const toMigrate = cssData?.css_classes_to_migrate ?? 0;
          if (state.restrictCssToUsed && toMigrate < total) {
            refs.cssPreview.innerHTML = `<p><strong>CSS Classes:</strong> ${toMigrate.toLocaleString()} <span class="efs-wizard-hint">&#10003; filtered from ${total.toLocaleString()} total</span></p>`;
          } else {
            refs.cssPreview.innerHTML = `<p><strong>CSS Classes:</strong> ${total.toLocaleString()} <span class="efs-wizard-hint">(all classes)</span></p>`;
          }
          refs.cssPreview.hidden = false;
        } catch {
          refs.cssPreview.hidden = true;
        }
      }
    };
    const renderProgress = (payload) => {
      const progress = payload?.progress || {};
      const percentage = Number(progress?.percentage || 0);
      const status = progress?.current_phase_name || progress?.status || progress?.current_step || "Running migration...";
      const itemsProcessed = Number(progress?.items_processed || 0);
      const itemsTotal = Number(progress?.items_total || 0);
      runtime.lastProgressPercentage = percentage;
      updateTabTitle(percentage, status);
      if (runtime.progressChip) {
        updateProgressChip(runtime.progressChip, percentage);
      }
      if (refs.progressFill) {
        refs.progressFill.style.width = `${Math.max(0, Math.min(percentage, 100))}%`;
      }
      if (refs.progressPercent) {
        refs.progressPercent.textContent = `${Math.round(percentage)}%`;
      }
      if (refs.progressStatus) {
        refs.progressStatus.textContent = String(status);
      }
      if (refs.bannerText) {
        refs.bannerText.textContent = `Migration in progress: ${Math.round(percentage)}%`;
      }
      if (refs.progressItems) {
        const currentItemTitle = payload?.current_item?.title || progress?.current_item_title || "";
        if (itemsTotal > 0) {
          let itemsText = `Items processed: ${itemsProcessed}/${itemsTotal}`;
          if (currentItemTitle) {
            itemsText += ` \u2014 ${currentItemTitle}`;
          }
          refs.progressItems.textContent = itemsText;
        } else if (itemsProcessed > 0) {
          refs.progressItems.textContent = `Items processed: ${itemsProcessed}`;
        } else if (currentItemTitle) {
          refs.progressItems.textContent = currentItemTitle;
        } else {
          refs.progressItems.textContent = "";
        }
      }
      if (refs.progressElapsed) {
        const startedAtRaw = progress?.started_at;
        const startedAt = typeof startedAtRaw === "string" && startedAtRaw.trim() !== "" ? startedAtRaw.trim().replace(" ", "T") + "Z" : "";
        const startedMs = startedAt ? new Date(startedAt).getTime() : NaN;
        const elapsedSec = Number.isFinite(startedMs) && startedMs > 0 ? Math.max(0, Math.floor((Date.now() - startedMs) / 1e3)) : null;
        const etaSec = progress?.estimated_time_remaining != null ? Number(progress.estimated_time_remaining) : null;
        const etaStr = formatEta(etaSec);
        if (elapsedSec != null) {
          let text = `Elapsed: ${formatElapsed(elapsedSec)}`;
          if (etaStr) {
            text += `  \u2022  ${etaStr}`;
          }
          refs.progressElapsed.textContent = text;
          refs.progressElapsed.hidden = false;
        } else {
          refs.progressElapsed.textContent = "";
          refs.progressElapsed.hidden = true;
        }
      }
      if (refs.progressSteps) {
        const steps = normalizeSteps2(payload?.steps || progress?.steps || []);
        refs.progressSteps.innerHTML = steps.map((step) => {
          const label = step?.label || humanize(step?.slug || "step");
          const statusClass = step?.completed ? "is-complete" : step?.active ? "is-active" : "is-pending";
          return `<li class="efs-migration-step ${statusClass}">${label}</li>`;
        }).join("");
      }
      if (state.mode === "headless" && refs.headlessScreen && !refs.headlessScreen.hidden) {
        if (refs.headlessProgressFill) {
          refs.headlessProgressFill.style.width = `${Math.max(0, Math.min(percentage, 100))}%`;
        }
        if (refs.headlessProgressPercent) {
          refs.headlessProgressPercent.textContent = `${Math.round(percentage)}%`;
        }
        if (refs.headlessStatus) {
          refs.headlessStatus.textContent = String(status);
          refs.headlessStatus.hidden = !status;
        }
        if (refs.headlessSource) {
          refs.headlessSource.hidden = true;
        }
        if (refs.headlessItems) {
          const currentItemTitle = payload?.current_item?.title || progress?.current_item_title || "";
          if (itemsTotal > 0) {
            let itemsText = `Items: ${itemsProcessed}/${itemsTotal}`;
            if (currentItemTitle) {
              itemsText += ` \u2014 ${currentItemTitle}`;
            }
            refs.headlessItems.textContent = itemsText;
            refs.headlessItems.hidden = false;
          } else if (itemsProcessed > 0) {
            refs.headlessItems.textContent = `Items: ${itemsProcessed}`;
            refs.headlessItems.hidden = false;
          } else if (currentItemTitle) {
            refs.headlessItems.textContent = currentItemTitle;
            refs.headlessItems.hidden = false;
          } else {
            refs.headlessItems.textContent = "";
            refs.headlessItems.hidden = true;
          }
        }
        if (refs.headlessElapsed) {
          const startedAtRaw = progress?.started_at;
          const startedAt = typeof startedAtRaw === "string" && startedAtRaw.trim() !== "" ? startedAtRaw.trim().replace(" ", "T") + "Z" : "";
          const startedMs = startedAt ? new Date(startedAt).getTime() : NaN;
          const elapsedSec = Number.isFinite(startedMs) && startedMs > 0 ? Math.max(0, Math.floor((Date.now() - startedMs) / 1e3)) : null;
          const etaSec = progress?.estimated_time_remaining != null ? Number(progress.estimated_time_remaining) : null;
          const etaStr = formatEta(etaSec);
          if (elapsedSec != null) {
            let text = `Elapsed: ${formatElapsed(elapsedSec)}`;
            if (etaStr) {
              text += `  \u2022  ${etaStr}`;
            }
            refs.headlessElapsed.textContent = text;
            refs.headlessElapsed.hidden = false;
          } else {
            refs.headlessElapsed.textContent = "";
            refs.headlessElapsed.hidden = true;
          }
        }
      }
    };
    const showResult = (type, subtitle) => {
      resetTabTitle();
      removeProgressChip(runtime.progressChip);
      runtime.progressChip = null;
      runtime.migrationRunning = false;
      state.progressMinimized = false;
      root.classList.remove("is-progress-minimized");
      if (refs.progressTakeover) {
        if (refs.progressTakeover.parentNode !== document.body) {
          document.body.appendChild(refs.progressTakeover);
        }
        refs.progressTakeover.hidden = false;
        refs.progressTakeover.classList.add("is-showing-result");
      }
      if (refs.progressPanel) {
        refs.progressPanel.hidden = true;
      }
      if (refs.headlessScreen) {
        refs.headlessScreen.hidden = true;
      }
      if (refs.progressBanner) {
        refs.progressBanner.hidden = true;
      }
      refs.minimizeButtons?.forEach((btn) => {
        btn.hidden = true;
      });
      const isSuccess = type === "success";
      if (refs.resultTitle) {
        refs.resultTitle.textContent = isSuccess ? "Migration complete" : "Migration failed";
      }
      if (refs.resultSubtitle) {
        refs.resultSubtitle.textContent = subtitle || (isSuccess ? "Migration completed successfully." : "Migration failed.");
      }
      if (refs.result) {
        refs.result.classList.toggle("is-success", isSuccess);
        refs.result.classList.toggle("is-error", !isSuccess);
        refs.result.hidden = false;
      }
      showToast(subtitle || (isSuccess ? "Migration complete." : "Migration failed."), isSuccess ? "success" : "error");
    };
    const hideResult = () => {
      if (!refs.result) {
        return;
      }
      refs.result.hidden = true;
      refs.result.classList.remove("is-error", "is-success");
    };
    const reopenProgress = () => {
      state.progressMinimized = false;
      root.classList.remove("is-progress-minimized");
      if (refs.progressTakeover) {
        if (refs.progressTakeover.parentNode !== document.body) {
          document.body.appendChild(refs.progressTakeover);
        }
        refs.progressTakeover.hidden = !runtime.migrationRunning;
        refs.progressTakeover.classList.remove("is-showing-result");
      }
      if (refs.progressPanel) {
        refs.progressPanel.hidden = state.mode === "headless" && refs.headlessScreen && !refs.headlessScreen.hidden;
      }
      refs.minimizeButtons?.forEach((btn) => {
        btn.hidden = false;
      });
      hideResult();
      if (refs.progressBanner) {
        refs.progressBanner.hidden = true;
      }
      removeProgressChip(runtime.progressChip);
      runtime.progressChip = null;
    };
    const dismissProgress = () => {
      state.progressMinimized = true;
      root.classList.add("is-progress-minimized");
      if (refs.progressTakeover) {
        refs.progressTakeover.hidden = true;
      }
      if (refs.progressBanner) {
        refs.progressBanner.hidden = false;
      }
      if (refs.bannerText) {
        refs.bannerText.textContent = `Migration in progress: ${Math.round(runtime.lastProgressPercentage)}%`;
      }
      if (refs.expandButton) {
        refs.expandButton.hidden = false;
      }
      if (!runtime.progressChip && refs.progressChipContainer) {
        runtime.progressChip = createProgressChip(refs.progressChipContainer);
        if (runtime.progressChip) {
          runtime.progressChip.addEventListener("click", reopenProgress);
          runtime.progressChip.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
              event.preventDefault();
              reopenProgress();
            }
          });
        }
      }
      if (runtime.progressChip) {
        updateProgressChip(runtime.progressChip, runtime.lastProgressPercentage);
      }
    };
    const stopPolling = () => {
      if (runtime.pollingTimer) {
        window.clearTimeout(runtime.pollingTimer);
        runtime.pollingTimer = null;
      }
    };
    const runBatchLoop = async (migrationId) => {
      runtime.batchFailCount = 0;
      let currentBatchSize = BATCH_SIZE;
      const processBatch = async () => {
        try {
          const payload = await post(ACTION_MIGRATE_BATCH, {
            migration_id: migrationId,
            batch_size: currentBatchSize
          });
          runtime.batchFailCount = 0;
          const progress = payload?.progress || {};
          const itemsProcessed = Number(progress?.items_processed || 0);
          if (runtime.lastProcessedCount !== void 0 && itemsProcessed > runtime.lastProcessedCount) {
            const batchDiff = itemsProcessed - runtime.lastProcessedCount;
            const batchDuration = performance.now() - (runtime.lastPollTime || performance.now());
            perfMetrics.recordBatch(batchDiff, batchDuration);
          }
          runtime.lastProcessedCount = itemsProcessed;
          runtime.lastPollTime = performance.now();
          renderProgress(payload);
          if (payload?.memory_pressure) {
            currentBatchSize = Math.max(1, Math.floor(currentBatchSize / 2));
            showToast("\u26A1 Batch size adjusted due to memory pressure", "info");
          }
          if (payload?.completed) {
            perfMetrics.endMigration();
            const hints = perfMetrics.getBottleneckHints();
            if (hints && hints.length > 0) {
              console.warn("[EFS][Perf] Bottleneck hints:", hints);
            }
            runtime.migrationRunning = false;
            showResult("success", "Migration finished successfully.");
            return;
          }
          window.setTimeout(processBatch, 100);
        } catch (error) {
          runtime.batchFailCount = (runtime.batchFailCount || 0) + 1;
          if (runtime.batchFailCount >= 3) {
            runtime.migrationRunning = false;
            if (refs.retryButton) {
              refs.retryButton.hidden = false;
            }
            showResult("error", error?.message || "Batch processing failed. Try resuming the migration.");
            return;
          }
          window.setTimeout(processBatch, POLL_INTERVAL_MS);
        }
      };
      await processBatch();
    };
    const resumeMigration = async (migrationId) => {
      try {
        const payload = await post(ACTION_RESUME_MIGRATION, {
          migration_id: migrationId
        });
        if (payload?.resumed) {
          hideResult();
          if (refs.retryButton) {
            refs.retryButton.hidden = true;
          }
          runtime.migrationRunning = true;
          runtime.batchFailCount = 0;
          updateNavigationState();
          renderProgress(payload);
          await runBatchLoop(migrationId);
        } else {
          showResult("error", "No checkpoint found \u2014 restart the migration from the beginning.");
          if (refs.retryButton) {
            refs.retryButton.hidden = false;
          }
        }
      } catch (error) {
        const isNoCheckpoint = error?.code === "no_checkpoint" || String(error?.message || "").toLowerCase().includes("checkpoint");
        const message = isNoCheckpoint ? "No checkpoint found \u2014 you need to restart the migration." : error?.message || "Unable to resume migration.";
        showResult("error", message);
        if (refs.retryButton) {
          refs.retryButton.hidden = false;
        }
      }
    };
    const startPolling = (migrationId) => {
      if (!migrationId) {
        return;
      }
      stopPolling();
      runtime.migrationRunning = true;
      runtime.pollingFailCount = 0;
      const pollingStartTime = Date.now();
      const poll = async () => {
        try {
          const payload = await post(ACTION_GET_PROGRESS2, {
            migration_id: migrationId
          });
          runtime.pollingFailCount = 0;
          const progress = payload?.progress || {};
          const itemsProcessed = Number(progress?.items_processed || 0);
          if (runtime.lastProcessedCount !== void 0 && itemsProcessed > runtime.lastProcessedCount) {
            const batchSize = itemsProcessed - runtime.lastProcessedCount;
            const batchDuration = performance.now() - (runtime.lastPollTime || performance.now());
            perfMetrics.recordBatch(batchSize, batchDuration);
          }
          runtime.lastProcessedCount = itemsProcessed;
          runtime.lastPollTime = performance.now();
          state.migrationId = payload?.migrationId || migrationId;
          if (payload?.progress?.action_scheduler_id) {
            state.actionSchedulerId = payload.progress.action_scheduler_id;
          }
          renderProgress(payload);
          const status = String(payload?.progress?.status || payload?.progress?.current_step || "").toLowerCase();
          const currentStep = String(payload?.progress?.current_step || "").toLowerCase();
          const percentage = Number(payload?.progress?.percentage ?? 0);
          if (payload?.completed || status === "completed" || percentage >= 100) {
            perfMetrics.endMigration();
            const hints = perfMetrics.getBottleneckHints();
            if (hints && hints.length > 0) {
              console.warn("[EFS][Perf] Bottleneck hints:", hints);
            }
            runtime.migrationRunning = false;
            showResult("success", "Migration finished successfully.");
            return;
          }
          if (status === "error" || payload?.error) {
            runtime.migrationRunning = false;
            if (refs.retryButton) {
              refs.retryButton.hidden = false;
            }
            showResult("error", payload?.progress?.message || payload?.message || payload?.error || "Migration stopped due to an error.");
            return;
          }
          const isStale = Boolean(payload?.is_stale || payload?.progress?.is_stale || status === "stale");
          if (isStale && percentage === 0) {
            runtime.migrationRunning = false;
            if (refs.retryButton) {
              refs.retryButton.hidden = false;
            }
            showResult("error", "Migration did not start (e.g. background request could not reach the server). Try again or check server configuration.");
            return;
          }
          const elapsedSeconds = (Date.now() - pollingStartTime) / 1e3;
          if (percentage === 0 && status === "running" && elapsedSeconds > 60) {
            runtime.migrationRunning = false;
            if (refs.retryButton) {
              refs.retryButton.hidden = false;
            }
            showResult("error", "Migration did not start within 60 seconds. The server may not be able to reach itself (loopback request). Check server logs or try again.");
            return;
          }
          if ((currentStep === "media" || currentStep === "posts") && status === "running" && state.mode !== "headless") {
            stopPolling();
            await runBatchLoop(migrationId);
            return;
          }
          runtime.pollingTimer = window.setTimeout(poll, POLL_INTERVAL_MS);
        } catch (error) {
          runtime.pollingFailCount = (runtime.pollingFailCount || 0) + 1;
          if (runtime.pollingFailCount >= 3) {
            runtime.migrationRunning = false;
            if (refs.retryButton) {
              refs.retryButton.hidden = false;
            }
            showResult("error", error?.message || "Progress check failed. Migration may still be running on the server.");
            return;
          }
          runtime.pollingTimer = window.setTimeout(poll, POLL_INTERVAL_MS);
        }
      };
      poll();
    };
    const setStep = async (step, options = {}) => {
      const nextStep = Math.max(1, Math.min(STEP_COUNT, Number(step) || 1));
      state.currentStep = nextStep;
      setMessage(refs.connectMessage, "");
      setMessage(refs.selectMessage, "");
      renderStepShell();
      if (nextStep === 3 && !runtime.discoveryLoaded) {
        await runDiscovery();
      }
      if (nextStep === 4) {
        await renderPreview();
      }
      if (nextStep < 4) {
        reopenProgress();
      }
      if (!options.skipSave) {
        await saveWizardState();
      }
    };
    const applyPreset = async (preset) => {
      const postTypes = getDiscoveryPostTypes(state.discoveryData);
      if (!postTypes.length) {
        return;
      }
      if (preset === "all") {
        state.selectedPostTypes = postTypes.map((item) => item.slug);
      } else if (preset === "posts") {
        state.selectedPostTypes = postTypes.filter((item) => item.slug === "post").map((item) => item.slug);
      } else if (preset === "cpts") {
        state.selectedPostTypes = postTypes.filter((item) => !["post", "page"].includes(item.slug)).map((item) => item.slug);
      } else {
        state.selectedPostTypes = [];
      }
      renderPostTypeTable();
      updateNavigationState();
      await saveWizardState();
    };
    const validateConnectStep = async () => {
      const url = refs.urlInput?.value?.trim() || "";
      state.migrationUrl = url;
      if (!url) {
        throw new Error("Please enter the target site URL or paste a migration key before continuing.");
      }
      runtime.validatingConnect = true;
      updateNavigationState();
      showToast("Connecting to target site\u2026", "info");
      try {
        let token = "";
        let targetUrl = "";
        try {
          const parsed = parseMigrationUrl(url);
          token = parsed.token;
          targetUrl = parsed.origin;
        } catch {
          try {
            targetUrl = new URL(url.includes("://") ? url : `https://${url}`).origin;
          } catch {
            throw new Error("Invalid URL. Enter the target site URL (e.g. https://mysite.com) or paste a migration URL.");
          }
        }
        if (runtime.validatedMigrationUrl && runtime.validatedMigrationUrl !== targetUrl) {
          resetDiscoveryState({ clearConnection: true });
        }
        if (token) {
          const urlResult = await post(ACTION_VALIDATE_URL, {
            wizard_nonce: wizardNonce,
            migration_url: url
          });
          if (urlResult?.warning) {
            setMessage(refs.connectMessage, urlResult.warning, "warning");
          }
          const validation = await post(ACTION_VALIDATE_TOKEN2, {
            migration_key: token,
            target_url: targetUrl
          });
          if (!validation?.valid) {
            throw new Error("The migration token could not be validated.");
          }
        } else {
          let pairingCode = "";
          try {
            const parsedInput = new URL(url.includes("://") ? url : `https://${url}`);
            pairingCode = parsedInput.searchParams.get("_efs_pair") || "";
          } catch {
          }
          if (!pairingCode) {
            throw new Error('Paste the connection URL from the target site (generated via "Generate Connection URL" on the Etch dashboard).');
          }
          const sourceUrl = window.efsData?.site_url || window.location.origin;
          const generateUrl = `${targetUrl}/wp-json/efs/v1/generate-key?source_url=${encodeURIComponent(sourceUrl)}&pairing_code=${encodeURIComponent(pairingCode)}`;
          let tokenRes;
          try {
            tokenRes = await fetch(generateUrl, { credentials: "omit" });
          } catch {
            throw new Error(`Could not reach target site at ${targetUrl}. Check the URL and ensure the plugin is active on the target.`);
          }
          if (!tokenRes.ok) {
            throw new Error(`Target site rejected the connection (HTTP ${tokenRes.status}). Verify the target URL and CORS settings.`);
          }
          const tokenData = await tokenRes.json();
          token = tokenData?.migration_key || tokenData?.token || "";
          if (!token) {
            throw new Error("Target did not return a migration token. Ensure Etch Fusion Suite is active on the target site.");
          }
        }
        state.migrationKey = token;
        state.targetUrl = targetUrl;
        runtime.validatedMigrationUrl = targetUrl;
        runPreflightCheck(state.targetUrl, state.mode || "browser").catch(() => {
        });
        if (refs.keyInput) {
          refs.keyInput.value = token;
        }
        setMessage(refs.connectMessage, "Connection successful.", "success");
        await saveWizardState();
        return true;
      } finally {
        runtime.validatingConnect = false;
        updateNavigationState();
      }
    };
    const startMigration2 = async () => {
      if (runtime.migrationRunning) {
        return;
      }
      if (!state.migrationKey && refs.keyInput?.value) {
        state.migrationKey = refs.keyInput.value.trim();
      }
      if ((!state.targetUrl || !state.migrationKey) && state.migrationUrl) {
        try {
          const parsed = parseMigrationUrl(state.migrationUrl);
          state.migrationKey = state.migrationKey || parsed.token;
          state.targetUrl = state.targetUrl || parsed.origin;
        } catch {
        }
      }
      hideResult();
      if (refs.retryButton) {
        refs.retryButton.hidden = true;
      }
      runtime.migrationRunning = true;
      updateNavigationState();
      try {
        const payload = await post(ACTION_START_MIGRATION2, {
          migration_key: state.migrationKey,
          target_url: state.targetUrl,
          batch_size: state.batchSize,
          selected_post_types: state.selectedPostTypes,
          post_type_mappings: state.postTypeMappings,
          include_media: state.includeMedia ? "1" : "0",
          restrict_css_to_used: state.restrictCssToUsed ? "1" : "0",
          mode: state.mode || "browser"
        });
        state.migrationId = payload?.migrationId ?? payload?.migration_id ?? "";
        if (!state.migrationId) {
          throw new Error("Migration did not return an ID.");
        }
        if (payload?.progress?.action_scheduler_id) {
          state.actionSchedulerId = payload.progress.action_scheduler_id;
        }
        const totalItems = getDiscoveryPostTypes(state.discoveryData).filter((pt) => state.selectedPostTypes.includes(pt.slug)).reduce((sum, pt) => sum + pt.count, 0);
        perfMetrics.startMigration(totalItems);
        runtime.lastProcessedCount = void 0;
        runtime.lastPollTime = void 0;
        await setStep(4);
        if (refs.progressTakeover) {
          if (refs.progressTakeover.parentNode !== document.body) {
            document.body.appendChild(refs.progressTakeover);
          }
          refs.progressTakeover.hidden = false;
        }
        if (payload?.queued === true && state.mode === "headless") {
          if (refs.progressPanel) {
            refs.progressPanel.hidden = true;
          }
          hideResult();
          if (refs.headlessScreen) {
            refs.headlessScreen.hidden = false;
          }
          const pct = Number(payload?.progress?.percentage || 0);
          if (refs.headlessProgressFill) {
            refs.headlessProgressFill.style.width = `${pct}%`;
          }
          if (refs.headlessProgressPercent) {
            refs.headlessProgressPercent.textContent = `${Math.round(pct)}%`;
          }
          await saveWizardState();
          startPolling(state.migrationId);
          return;
        }
        reopenProgress();
        renderProgress(payload);
        await saveWizardState();
        startPolling(state.migrationId);
      } catch (error) {
        runtime.migrationRunning = false;
        throw error;
      } finally {
        updateNavigationState();
      }
    };
    const resetWizard = async () => {
      stopPolling();
      runtime.migrationRunning = false;
      runtime.discoveryLoaded = false;
      runtime.validatedMigrationUrl = "";
      runtime.lastProgressPercentage = 0;
      resetTabTitle();
      removeProgressChip(runtime.progressChip);
      runtime.progressChip = null;
      Object.assign(state, defaultState());
      state.mode = "browser";
      state.actionSchedulerId = null;
      refs.modeRadios.forEach((radio) => {
        if (radio instanceof HTMLInputElement) {
          radio.checked = radio.value === "browser";
          radio.disabled = false;
        }
      });
      if (refs.headlessScreen) {
        refs.headlessScreen.hidden = true;
      }
      if (refs.cronIndicator) {
        refs.cronIndicator.hidden = true;
      }
      if (refs.urlInput) {
        refs.urlInput.value = "";
      }
      if (refs.keyInput) {
        refs.keyInput.value = "";
      }
      resetDiscoveryUi();
      if (refs.includeMedia) {
        refs.includeMedia.checked = true;
      }
      if (refs.restrictCssToUsed) {
        refs.restrictCssToUsed.checked = true;
      }
      state.restrictCssToUsed = true;
      if (refs.progressTakeover) {
        refs.progressTakeover.hidden = true;
      }
      if (refs.progressBanner) {
        refs.progressBanner.hidden = true;
      }
      reopenProgress();
      root.classList.remove("is-progress-minimized");
      hideResult();
      await clearWizardState();
      await setStep(1, { skipSave: true });
    };
    const handleCancel = async () => {
      if (state.currentStep === 5) {
        try {
          const migrationId = state.migrationId || window.efsData?.migrationId || window.efsData?.in_progress_migration?.migrationId || "";
          await post(ACTION_CANCEL_MIGRATION2, {
            migration_id: migrationId
          });
          showToast("Migration cancelled.", "info");
        } catch (error) {
          showToast(error?.message || "Unable to cancel migration.", "error");
        }
      }
      await resetWizard();
    };
    const openLogsTab = () => {
      if (refs.progressTakeover) {
        refs.progressTakeover.hidden = true;
      }
      if (refs.progressBanner) {
        refs.progressBanner.hidden = true;
      }
      const migrationHash = state.migrationId ? `#migration-${state.migrationId}` : "#logs";
      if (state.migrationId) {
        window.history.replaceState(null, "", migrationHash);
      }
      const logsTab = document.querySelector('[data-efs-tab="logs"]');
      if (logsTab) {
        logsTab.click();
        document.querySelector("#efs-tab-logs")?.scrollIntoView({ behavior: "smooth", block: "start" });
        return;
      }
      const logsPanel = document.querySelector("[data-efs-log-panel]");
      if (logsPanel) {
        logsPanel.scrollIntoView({ behavior: "smooth", block: "start" });
        return;
      }
      const fallbackUrl = new URL(window.location.href);
      fallbackUrl.searchParams.set("page", "etch-fusion-suite");
      fallbackUrl.hash = migrationHash;
      window.location.assign(fallbackUrl.toString());
    };
    const bindEvents = () => {
      refs.urlInput?.addEventListener("input", () => {
        const nextUrl = refs.urlInput.value.trim();
        if (nextUrl !== state.migrationUrl) {
          resetDiscoveryState({ clearConnection: true });
        }
        state.migrationUrl = nextUrl;
        setMessage(refs.connectMessage, "");
        updateNavigationState();
      });
      refs.pasteButton?.addEventListener("click", async () => {
        try {
          const text = navigator.clipboard?.readText ? await navigator.clipboard.readText() : "";
          if (!text || typeof text !== "string") {
            showToast("Clipboard is empty or paste is not supported.", "info");
            return;
          }
          const trimmed = text.trim();
          if (refs.urlInput) {
            refs.urlInput.value = trimmed;
            if (trimmed !== state.migrationUrl) {
              resetDiscoveryState({ clearConnection: true });
            }
            state.migrationUrl = trimmed;
            setMessage(refs.connectMessage, "");
            updateNavigationState();
            showToast("Key pasted.", "success");
          }
        } catch {
          showToast("Could not read clipboard. Paste manually.", "error");
        }
      });
      refs.backButton?.addEventListener("click", async () => {
        if (state.currentStep > 1 && state.currentStep < 5) {
          await setStep(state.currentStep - 1);
        }
      });
      refs.nextButton?.addEventListener("click", async () => {
        try {
          if (state.currentStep === 1) {
            await setStep(2);
            return;
          }
          if (state.currentStep === 2) {
            await validateConnectStep();
            await setStep(3);
            return;
          }
          if (state.currentStep === 3) {
            if (!hasValidStep2Selection()) {
              setMessage(refs.selectMessage, "Select at least one post type and mapping to continue.", "error");
              return;
            }
            const mappingErrors = validatePostTypeMappings();
            if (mappingErrors.length > 0) {
              setMessage(refs.selectMessage, mappingErrors.join("; "), "error");
              return;
            }
            await setStep(4);
            return;
          }
          if (state.currentStep === 4) {
            await startMigration2();
          }
        } catch (error) {
          const message = error?.message || "Unable to continue to the next step.";
          if (state.currentStep === 2) {
            setMessage(refs.connectMessage, message, "error");
          } else if (state.currentStep === 3) {
            setMessage(refs.selectMessage, message, "error");
          } else {
            showResult("error", message);
          }
        }
      });
      refs.cancelButton?.addEventListener("click", handleCancel);
      refs.progressCancelButton?.addEventListener("click", handleCancel);
      refs.preflightRecheck?.addEventListener("click", async () => {
        if (refs.preflightRecheck) {
          refs.preflightRecheck.disabled = true;
        }
        try {
          await invalidateAndRecheck();
        } catch (err) {
          console.warn("[EFS] Preflight recheck failed", err);
          showToast(err?.message || "Preflight recheck failed. Please try again.", "error");
        } finally {
          if (refs.preflightRecheck) {
            refs.preflightRecheck.disabled = false;
          }
        }
      });
      refs.preflightConfirm?.addEventListener("change", (e) => {
        state.preflightConfirmed = e.target.checked;
        updateNavigationState();
      });
      refs.retryButton?.addEventListener("click", async () => {
        refs.retryButton.hidden = true;
        try {
          if (state.migrationId) {
            await resumeMigration(state.migrationId);
          } else {
            await startMigration2();
          }
        } catch (error) {
          const message = error?.message || "Unable to restart migration.";
          showResult("error", message);
          refs.retryButton.hidden = false;
        }
      });
      refs.minimizeButtons?.forEach((btn) => btn.addEventListener("click", dismissProgress));
      refs.expandButton?.addEventListener("click", reopenProgress);
      refs.runFullAnalysis?.addEventListener("click", () => {
        showToast("Full analysis endpoint is not available yet. Showing sampled discovery data.", "info");
      });
      refs.rowsBody?.addEventListener("change", async (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        if (target.matches("[data-efs-post-type-check]")) {
          const slug = target.getAttribute("data-efs-post-type-check") || "";
          const checked = target instanceof HTMLInputElement ? target.checked : false;
          if (checked) {
            if (!state.selectedPostTypes.includes(slug)) {
              state.selectedPostTypes.push(slug);
            }
          } else {
            state.selectedPostTypes = state.selectedPostTypes.filter((item) => item !== slug);
          }
          renderPostTypeTable();
          updateNavigationState();
          await saveWizardState();
          return;
        }
        if (target.matches("[data-efs-post-type-map]")) {
          const slug = target.getAttribute("data-efs-post-type-map") || "";
          const value = target instanceof HTMLSelectElement ? target.value : "";
          state.postTypeMappings[slug] = value;
          updateNavigationState();
          await saveWizardState();
        }
      });
      refs.includeMedia?.addEventListener("change", async () => {
        state.includeMedia = Boolean(refs.includeMedia?.checked);
        await saveWizardState();
      });
      refs.restrictCssToUsed?.addEventListener("change", async () => {
        state.restrictCssToUsed = Boolean(refs.restrictCssToUsed?.checked);
        await saveWizardState();
      });
      refs.presets.forEach((button) => {
        button.addEventListener("click", async () => {
          await applyPreset(button.getAttribute("data-efs-preset") || "none");
        });
      });
      refs.stepNav.forEach((button) => {
        button.addEventListener("click", async () => {
          const step = Number(button.getAttribute("data-efs-step-nav") || "1");
          if (step < state.currentStep && state.currentStep < 5) {
            await setStep(step);
          }
        });
      });
      refs.openLogsButton?.addEventListener("click", openLogsTab);
      refs.startNewButton?.addEventListener("click", resetWizard);
      refs.modeRadios.forEach((radio) => {
        radio?.addEventListener("change", async () => {
          if (!(radio instanceof HTMLInputElement) || !radio.checked) {
            return;
          }
          const newMode = radio.value === "headless" ? "headless" : "browser";
          if (newMode === "headless") {
            const wpCronCheck = state.preflight?.checks?.find((c) => c.id === "wp_cron");
            if (wpCronCheck && wpCronCheck.status !== "ok") {
              state.mode = "browser";
              refs.modeRadios.forEach((r) => {
                if (r instanceof HTMLInputElement) {
                  r.checked = r.value === "browser";
                }
                const modeLabel = r?.closest("[data-efs-mode-option]");
                if (modeLabel) {
                  modeLabel.classList.toggle("efs-mode-option--selected", r instanceof HTMLInputElement && r.value === "browser");
                }
              });
              if (refs.cronIndicator) {
                refs.cronIndicator.hidden = false;
                refs.cronIndicator.textContent = "\u26A0 WP Cron not available";
              }
              radio.disabled = true;
              updateNavigationState();
              await saveWizardState();
              return;
            }
          }
          state.mode = newMode;
          refs.modeRadios.forEach((r) => {
            const label = r?.closest("[data-efs-mode-option]");
            if (label) {
              label.classList.toggle("efs-mode-option--selected", r === radio);
            }
          });
          if (refs.cronIndicator) {
            if (newMode === "headless") {
              const wpCronCheck = state.preflight?.checks?.find((c) => c.id === "wp_cron");
              if (wpCronCheck) {
                refs.cronIndicator.hidden = false;
                if (wpCronCheck.status === "ok") {
                  refs.cronIndicator.textContent = "\u2705 WP Cron active";
                } else {
                  refs.cronIndicator.textContent = "\u26A0 WP Cron not available";
                  radio.disabled = true;
                }
              } else {
                refs.cronIndicator.hidden = true;
              }
            } else {
              refs.cronIndicator.hidden = true;
            }
          }
          updateNavigationState();
          await invalidateAndRecheck();
          await saveWizardState();
        });
      });
      refs.cancelHeadlessButton?.addEventListener("click", async () => {
        try {
          const migrationId = state.migrationId || "";
          await post(ACTION_CANCEL_MIGRATION2, { migration_id: migrationId });
          await post("efs_cancel_headless_job", {
            action_scheduler_id: state.actionSchedulerId || 0,
            migration_id: migrationId
          });
          showToast("Headless migration cancelled.", "info");
        } catch (error) {
          showToast(error?.message || "Unable to cancel migration.", "error");
        }
        await resetWizard();
      });
      root.querySelector("[data-efs-view-logs]")?.addEventListener("click", openLogsTab);
    };
    const restoreState = async () => {
      try {
        const result = await post(ACTION_GET_STATE, {
          wizard_nonce: wizardNonce
        });
        const saved = result?.state;
        if (!saved || typeof saved !== "object") {
          return;
        }
        state.currentStep = Number(saved.current_step || state.currentStep);
        state.migrationUrl = String(saved.migration_url || state.migrationUrl || "");
        state.migrationKey = String(saved.migration_key || state.migrationKey || "");
        state.targetUrl = String(saved.target_url || state.targetUrl || "");
        state.discoveryData = saved.discovery_data && typeof saved.discovery_data === "object" ? {
          ...state.discoveryData || {},
          ...saved.discovery_data
        } : state.discoveryData;
        state.selectedPostTypes = Array.isArray(saved.selected_post_types) ? saved.selected_post_types : state.selectedPostTypes;
        state.postTypeMappings = saved.post_type_mappings && typeof saved.post_type_mappings === "object" ? saved.post_type_mappings : state.postTypeMappings;
        state.includeMedia = typeof saved.include_media === "boolean" ? saved.include_media : state.includeMedia;
        state.restrictCssToUsed = typeof saved.restrict_css_to_used === "boolean" ? saved.restrict_css_to_used : state.restrictCssToUsed;
        if (refs.restrictCssToUsed) {
          refs.restrictCssToUsed.checked = state.restrictCssToUsed;
        }
        state.batchSize = Number(saved.batch_size || state.batchSize);
        if (saved.mode && ["browser", "headless"].includes(saved.mode)) {
          state.mode = saved.mode;
        }
        refs.modeRadios.forEach((radio) => {
          if (radio instanceof HTMLInputElement) {
            radio.checked = radio.value === state.mode;
          }
        });
        if (refs.urlInput && state.migrationUrl) {
          refs.urlInput.value = state.migrationUrl;
        }
        if (refs.keyInput && state.migrationKey) {
          refs.keyInput.value = state.migrationKey;
        }
        if (state.migrationUrl && state.migrationKey && state.targetUrl) {
          try {
            runtime.validatedMigrationUrl = parseMigrationUrl(state.migrationUrl).normalizedUrl;
          } catch {
            runtime.validatedMigrationUrl = state.migrationUrl;
          }
        }
        if (getDiscoveryPostTypes(state.discoveryData).length) {
          runtime.discoveryLoaded = true;
          if (refs.discoveryLoading) {
            refs.discoveryLoading.hidden = true;
          }
          await fetchTargetPostTypes();
          renderSummary();
          renderPostTypeTable();
        }
      } catch (error) {
        console.warn("[EFS] Failed to restore wizard state", error);
      }
    };
    const autoResumeMigration = async () => {
      const progress = window.efsData?.progress_data || {};
      const inProgress = window.efsData?.in_progress_migration || {};
      const migrationId = window.efsData?.migrationId || progress?.migrationId || inProgress?.migrationId || "";
      const completed = Boolean(window.efsData?.completed || progress?.completed);
      const status = String(progress?.status || progress?.current_step || "").toLowerCase();
      const percentage = Number(progress?.percentage ?? 0);
      if (!migrationId || completed || status === "completed" || percentage >= 100) {
        return false;
      }
      if (!inProgress?.resumable && !status) {
        return false;
      }
      try {
        const payload = await post(ACTION_GET_PROGRESS2, {
          migration_id: migrationId
        });
        const pollStatus = String(payload?.progress?.status || payload?.progress?.current_step || "").toLowerCase();
        const pollPercentage = Number(payload?.progress?.percentage ?? 0);
        const isRunning = pollStatus === "running" || pollStatus === "receiving";
        const isStale = pollStatus === "stale" || Boolean(payload?.progress?.is_stale || payload?.is_stale);
        const pollCurrentStep = String(payload?.progress?.current_step || "").toLowerCase();
        if (isStale && (pollCurrentStep === "media" || pollCurrentStep === "posts")) {
          state.migrationId = payload?.migrationId || migrationId;
          try {
            const resumed = await post(ACTION_RESUME_MIGRATION, { migration_id: state.migrationId });
            if (resumed?.resumed) {
              await setStep(4, { skipSave: true });
              if (refs.progressTakeover) {
                refs.progressTakeover.hidden = false;
              }
              renderProgress(resumed);
              stopPolling();
              runtime.migrationRunning = true;
              await runBatchLoop(state.migrationId);
              return true;
            }
          } catch {
          }
          return false;
        }
        if (payload?.completed || pollStatus === "completed" || pollStatus === "error" || pollPercentage >= 100 || !isRunning) {
          return false;
        }
        state.migrationId = payload?.migrationId || migrationId;
        await setStep(4, { skipSave: true });
        if (refs.progressTakeover) {
          refs.progressTakeover.hidden = false;
        }
        renderProgress(payload);
        if (payload?.progress?.mode === "headless" || pollStatus === "queued") {
          state.mode = "headless";
          if (payload?.progress?.action_scheduler_id) {
            state.actionSchedulerId = payload.progress.action_scheduler_id;
          }
          if (refs.progressPanel) {
            refs.progressPanel.hidden = true;
          }
          hideResult();
          if (refs.headlessScreen) {
            refs.headlessScreen.hidden = false;
          }
          startPolling(state.migrationId);
          return true;
        }
        const resumeStep = String(payload?.progress?.current_step || "").toLowerCase();
        if ((resumeStep === "media" || resumeStep === "posts") && isRunning) {
          stopPolling();
          runtime.migrationRunning = true;
          await runBatchLoop(state.migrationId);
        } else {
          startPolling(state.migrationId);
        }
        return true;
      } catch {
        return false;
      }
    };
    const init = async () => {
      bindEvents();
      await restoreState();
      runPreflightCheck("", state.mode || "browser").catch(() => {
      });
      const resumed = await autoResumeMigration();
      if (!resumed) {
        if (state.currentStep >= 5) {
          state.currentStep = hasValidStep2Selection() ? 4 : 1;
          resetTabTitle();
          removeProgressChip(runtime.progressChip);
          runtime.progressChip = null;
          if (refs.progressTakeover) {
            refs.progressTakeover.hidden = true;
          }
          if (refs.progressBanner) {
            refs.progressBanner.hidden = true;
          }
        }
        await setStep(state.currentStep, { skipSave: true });
      }
    };
    return { init };
  };
  var initBricksWizard = () => {
    const root = document.querySelector("[data-efs-bricks-wizard]");
    if (!root) {
      return;
    }
    const wizard = createWizard(root);
    wizard.init().catch((error) => {
      console.error("[EFS] Failed to initialize Bricks wizard", error);
    });
  };

  // assets/js/admin/receiving-status.js
  var ACTION_GET_RECEIVING_STATUS = "efs_get_receiving_status";
  var ACTION_DISMISS_MIGRATION_RUN = "efs_dismiss_migration_run";
  var ACTION_GET_DISMISSED_MIGRATION_RUNS = "efs_get_dismissed_migration_runs";
  var POLL_INTERVAL_MS2 = 3e3;
  var DISMISSIBLE_STATUSES = /* @__PURE__ */ new Set(["receiving", "completed", "stale"]);
  var STORAGE_KEY_DISMISSED = "efsReceivingDismissedKeys";
  var SOURCE_FALLBACK = "Unknown source";
  var PHASE_FALLBACK = "Initializing";
  var ACTIVITY_FALLBACK = "Not yet available";
  var extractHost = (value) => {
    if (!value) {
      return SOURCE_FALLBACK;
    }
    try {
      const parsed = new URL(value);
      return parsed.host || value;
    } catch {
      return value;
    }
  };
  var formatStatusCopy = (status, phase, isStale) => {
    if (status === "completed") {
      return "Migration payload received successfully. You can now review imported content.";
    }
    if (status === "stale" || isStale) {
      return "No new payloads detected recently. Confirm the source migration is still running.";
    }
    return `Receiving migration payloads (${phase || PHASE_FALLBACK}).`;
  };
  var formatTitle = (status, isStale) => {
    if (status === "completed") {
      return "Migration Received";
    }
    if (status === "stale" || isStale) {
      return "Migration Stalled";
    }
    return "Receiving Migration";
  };
  var formatSubtitle = (status, isStale) => {
    if (status === "completed") {
      return "Incoming migration completed on this Etch site.";
    }
    if (status === "stale" || isStale) {
      return "Receiving updates paused. Check the source site and retry if needed.";
    }
    return "Incoming data from the source site is being processed.";
  };
  var setRootStateClasses = (root, state) => {
    root.classList.toggle("is-receiving-completed", state === "completed");
    root.classList.toggle("is-receiving-stale", state === "stale");
  };
  var normalizeStatus = (response = {}) => {
    const rawStatus = String(response?.status || "idle").toLowerCase();
    const stale = Boolean(response?.is_stale);
    if (rawStatus === "stale" || stale) {
      return "stale";
    }
    if (rawStatus === "completed") {
      return "completed";
    }
    if (rawStatus === "receiving") {
      return "receiving";
    }
    return "idle";
  };
  var createUiModel = (payload = {}) => {
    const status = normalizeStatus(payload);
    const sourceRaw = String(payload?.source_site || "").trim();
    const source = extractHost(sourceRaw);
    const phase = String(payload?.current_phase || "").trim() || PHASE_FALLBACK;
    const items = Number(payload?.items_received) || 0;
    const lastActivityRaw = String(payload?.last_activity || "").trim();
    const lastActivity = lastActivityRaw || ACTIVITY_FALLBACK;
    const startedAt = String(payload?.started_at || "").trim();
    const migrationId = String(payload?.migration_id || "").trim();
    const hasSignal = migrationId !== "" || sourceRaw !== "" || items > 0 || lastActivityRaw !== "";
    const itemsTotal = Number(payload?.items_total) || 0;
    const etaSec = Number(payload?.estimated_time_remaining) || null;
    return {
      status,
      source,
      phase,
      items,
      lastActivity,
      startedAt,
      migrationId,
      sourceRaw,
      lastActivityRaw,
      hasSignal,
      itemsTotal,
      etaSec,
      isStale: status === "stale",
      title: formatTitle(status, status === "stale"),
      subtitle: formatSubtitle(status, status === "stale"),
      statusCopy: formatStatusCopy(status, phase, status === "stale")
    };
  };
  var initReceivingStatus = () => {
    const root = document.querySelector("[data-efs-etch-dashboard]");
    const takeover = root?.querySelector("[data-efs-receiving-display]");
    const banner = root?.querySelector("[data-efs-receiving-banner]");
    if (!root || !takeover || !banner) {
      return;
    }
    const elements = {
      title: root.querySelector("[data-efs-receiving-title]"),
      subtitle: root.querySelector("[data-efs-receiving-subtitle]"),
      source: root.querySelector("[data-efs-receiving-source]"),
      items: root.querySelector("[data-efs-receiving-items]"),
      elapsed: root.querySelector("[data-efs-receiving-elapsed]"),
      status: root.querySelector("[data-efs-receiving-status]"),
      progressFill: root.querySelector("[data-efs-receiving-progress-fill]"),
      percent: root.querySelector("[data-efs-receiving-percent]"),
      bannerText: root.querySelector("[data-efs-receiving-banner-text]"),
      minimize: root.querySelector("[data-efs-receiving-minimize]"),
      expand: root.querySelector("[data-efs-receiving-expand]"),
      dismiss: root.querySelector("[data-efs-receiving-dismiss]"),
      viewReceivedContent: root.querySelector("[data-efs-view-received-content]")
    };
    let inFlight = false;
    let pollingActive = true;
    let collapsed = true;
    let hasAutoExpanded = false;
    let currentModel = createUiModel();
    const readDismissedKeys = () => {
      try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY_DISMISSED);
        if (!raw) {
          return /* @__PURE__ */ new Set();
        }
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? new Set(parsed.filter(Boolean)) : /* @__PURE__ */ new Set();
      } catch {
        return /* @__PURE__ */ new Set();
      }
    };
    const writeDismissedKeys = (keys) => {
      try {
        window.sessionStorage.setItem(STORAGE_KEY_DISMISSED, JSON.stringify(Array.from(keys)));
      } catch {
      }
    };
    const dismissedKeys = readDismissedKeys();
    const getDismissKey = (model) => {
      if (model.migrationId) {
        return `migration:${model.migrationId}`;
      }
      if (model.sourceRaw) {
        return `source:${model.sourceRaw}`;
      }
      return "";
    };
    const render = (model) => {
      currentModel = model;
      const dismissKey = getDismissKey(model);
      const isVisibleState = model.status !== "idle" && model.hasSignal;
      const isDismissed = dismissKey !== "" && dismissedKeys.has(dismissKey);
      const visualState = !isVisibleState || isDismissed ? "idle" : model.status;
      setRootStateClasses(root, visualState);
      if (elements.title) {
        elements.title.textContent = model.title;
      }
      if (elements.subtitle) {
        elements.subtitle.textContent = model.subtitle;
      }
      if (elements.source) {
        elements.source.textContent = model.source && model.source !== SOURCE_FALLBACK ? `Source: ${model.source}` : "";
        elements.source.hidden = !elements.source.textContent;
      }
      if (elements.items) {
        const total = model.itemsTotal > 0 ? `/${model.itemsTotal}` : "";
        elements.items.textContent = model.items > 0 || total ? `Items: ${model.items}${total}` : "";
        elements.items.hidden = !elements.items.textContent;
      }
      if (elements.progressFill && elements.percent) {
        const total = model.itemsTotal > 0 ? model.itemsTotal : 0;
        const pct = total > 0 ? Math.min(100, Math.round(model.items / total * 100)) : 0;
        elements.progressFill.style.width = `${pct}%`;
        elements.percent.textContent = `${pct}%`;
        elements.percent.hidden = total === 0;
      }
      if (elements.elapsed) {
        const startedAt = model.startedAt;
        const startedMs = startedAt ? new Date(startedAt.replace(" ", "T")).getTime() : NaN;
        const elapsedSec = Number.isFinite(startedMs) && startedMs > 0 ? Math.max(0, Math.floor((Date.now() - startedMs) / 1e3)) : null;
        if (model.status === "receiving" && elapsedSec != null) {
          const etaStr = formatEta(model.etaSec);
          const text = etaStr ? `Elapsed: ${formatElapsed(elapsedSec)} \u2022 ${etaStr}` : `Elapsed: ${formatElapsed(elapsedSec)}`;
          elements.elapsed.textContent = text;
          elements.elapsed.hidden = false;
        } else {
          elements.elapsed.textContent = "";
          elements.elapsed.hidden = true;
        }
      }
      if (elements.status) {
        elements.status.textContent = model.statusCopy;
      }
      if (elements.bannerText) {
        elements.bannerText.textContent = `${model.title}: ${model.source}`;
      }
      if (elements.viewReceivedContent) {
        elements.viewReceivedContent.hidden = model.status !== "completed";
      }
      if (elements.dismiss) {
        elements.dismiss.hidden = !isVisibleState || !DISMISSIBLE_STATUSES.has(model.status);
      }
      if (!isVisibleState || isDismissed) {
        takeover.hidden = true;
        banner.hidden = true;
        return;
      }
      if (!hasAutoExpanded && isVisibleState) {
        collapsed = false;
        hasAutoExpanded = true;
      }
      takeover.hidden = collapsed;
      banner.hidden = !collapsed;
    };
    const schedulePoll = () => {
      if (pollingActive) {
        window.setTimeout(runPoll, POLL_INTERVAL_MS2);
      }
    };
    const runPoll = async () => {
      if (!pollingActive) {
        return;
      }
      if (inFlight) {
        schedulePoll();
        return;
      }
      inFlight = true;
      try {
        const payload = await post(ACTION_GET_RECEIVING_STATUS);
        const model = createUiModel(payload);
        render(model);
      } catch (error) {
        console.warn("[EFS] Receiving status polling failed.", error);
      } finally {
        inFlight = false;
        if (pollingActive) {
          schedulePoll();
        }
      }
    };
    const loadDismissedRuns = async () => {
      try {
        const response = await post(ACTION_GET_DISMISSED_MIGRATION_RUNS);
        const dismissed = Array.isArray(response?.dismissed) ? response.dismissed : [];
        dismissed.forEach((value) => {
          const key = String(value || "").trim();
          if (key) {
            dismissedKeys.add(`migration:${key}`);
          }
        });
        writeDismissedKeys(dismissedKeys);
      } catch (error) {
        console.warn("[EFS] Failed to load dismissed migration runs.", error);
      }
    };
    elements.minimize?.addEventListener("click", () => {
      collapsed = true;
      render(currentModel);
    });
    elements.expand?.addEventListener("click", () => {
      collapsed = false;
      render(currentModel);
    });
    elements.dismiss?.addEventListener("click", () => {
      if (!DISMISSIBLE_STATUSES.has(currentModel.status)) {
        return;
      }
      const dismissKey = getDismissKey(currentModel);
      if (dismissKey) {
        dismissedKeys.add(dismissKey);
        writeDismissedKeys(dismissedKeys);
      }
      pollingActive = false;
      if (currentModel.migrationId) {
        post(ACTION_DISMISS_MIGRATION_RUN, { migration_id: currentModel.migrationId }).catch(() => {
        });
      }
      setRootStateClasses(root, "idle");
      render(currentModel);
    });
    render(currentModel);
    loadDismissedRuns().finally(() => {
      runPoll();
    });
  };

  // assets/js/admin/main.js
  var bindMigrationForm = () => {
    const form = document.querySelector("[data-efs-migration-form]");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const payload = serializeForm(form);
      try {
        await startMigration(payload);
        startProgressPolling();
        startAutoRefreshLogs();
      } catch (error) {
        console.error("Start migration failed", error);
        showToast(error.message, "error");
      }
    });
    document.querySelectorAll("[data-efs-cancel-migration]").forEach((button) => {
      button.addEventListener("click", async () => {
        try {
          await cancelMigration();
          stopProgressPolling();
          stopAutoRefreshLogs();
        } catch (error) {
          console.error("Cancel migration failed", error);
          showToast(error.message, "error");
        }
      });
    });
  };
  var bindTabs = () => {
    const tabsRoot = document.querySelector("[data-efs-tabs]");
    if (!tabsRoot) {
      return;
    }
    const tabs = Array.from(tabsRoot.querySelectorAll("[data-efs-tab]"));
    const panels = Array.from(tabsRoot.querySelectorAll(".efs-tab__panel"));
    const activateTab = (targetKey) => {
      tabs.forEach((tab) => {
        const isTarget = tab.dataset.efsTab === targetKey;
        tab.classList.toggle("is-active", isTarget);
        tab.setAttribute("aria-selected", String(isTarget));
        if (isTarget) {
          tab.removeAttribute("aria-disabled");
        }
      });
      panels.forEach((panel) => {
        const isTarget = panel.id === `efs-tab-${targetKey}`;
        panel.classList.toggle("is-active", isTarget);
        panel.toggleAttribute("hidden", !isTarget);
      });
    };
    tabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        activateTab(tab.dataset.efsTab);
      });
    });
    const initialTab = tabs.find((tab) => tab.classList.contains("is-active"));
    if (initialTab) {
      activateTab(initialTab.dataset.efsTab);
    }
  };
  var bootstrap = () => {
    if (!window.efsData) {
      console.warn("[EFS] efsData not localized. Admin scripts may not function correctly.");
      window.efsData = {
        ajaxUrl: "",
        nonce: "",
        settings: {}
      };
      showToast(
        window.efsStrings?.localizationMissing || "Etch Fusion data not loaded. Some features may be unavailable. Refresh and ensure plugin scripts are localized.",
        "warning"
      );
    }
    if (!window.efsData?.ajaxUrl || !window.efsData?.nonce) {
      console.warn("[EFS] efsData missing required fields.", window.efsData);
      showToast(
        window.efsStrings?.localizationInvalid || "Etch Fusion configuration incomplete. Check your WordPress setup or refresh the page.",
        "warning"
      );
    }
    initUI();
    bindSettings();
    bindValidation();
    bindMigrationForm();
    initLogs();
    bindTabs();
    initEtchDashboard();
    initBricksWizard();
    initReceivingStatus();
    const progress = window.efsData?.progress_data || {};
    const localizedMigrationId = window.efsData?.migrationId || window.efsData?.migration_id || progress?.migrationId;
    const completed = window.efsData?.completed || progress?.completed || false;
    if (localizedMigrationId && !completed) {
      const { percentage = 0, status = "" } = progress;
      const numericProgress = Number(percentage || 0);
      const normalizedStatus = String(status || "").toLowerCase();
      const isRunningStatus = normalizedStatus === "running" || normalizedStatus === "receiving";
      const isRunning = numericProgress > 0 && numericProgress < 100 || isRunningStatus;
      if (isRunning) {
        console.log("[EFS] Resuming migration polling:", localizedMigrationId);
        startProgressPolling({ migrationId: localizedMigrationId });
        startAutoRefreshLogs();
      }
    }
  };
  document.addEventListener("DOMContentLoaded", bootstrap);
})();
