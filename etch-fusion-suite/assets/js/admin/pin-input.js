import { showToast } from './ui.js';

const PIN_LENGTH = 24;
const BLOCK_SIZE = 4;
const BLOCK_COUNT = PIN_LENGTH / BLOCK_SIZE;
const ALLOWED_CHARS_REGEX = /^[a-zA-Z0-9]$/;
const TOAST_ERROR_TYPE = 'error';

const sanitizeValue = (value = '') => value.replace(/\s+/g, '').replace(/[^a-zA-Z0-9]/g, '');

const getBoxValue = (boxes) => boxes.map((box) => box.value).join('');

const clearInvalidState = (boxes) => {
  boxes.forEach((box) => box.classList.remove('is-invalid'));
};

const updateHiddenInputValue = (boxes, hiddenInput) => {
  hiddenInput.value = getBoxValue(boxes);
  hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
  hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
};

const focusBox = (boxes, index) => {
  if (index < 0 || index >= boxes.length) {
    return;
  }

  boxes[index].focus();
  boxes[index].select();
};

const toggleValueState = (box) => {
  box.classList.toggle('has-value', box.value !== '');
};

const clearBoxes = (boxes, hiddenInput) => {
  clearInvalidState(boxes);
  boxes.forEach((box) => {
    box.value = '';
    toggleValueState(box);
  });

  updateHiddenInputValue(boxes, hiddenInput);
  focusBox(boxes, 0);
};

const setBoxValues = (boxes, value, hiddenInput) => {
  const sanitized = sanitizeValue(value).slice(0, PIN_LENGTH);

  boxes.forEach((box, index) => {
    box.value = sanitized[index] ?? '';
    toggleValueState(box);
  });

  updateHiddenInputValue(boxes, hiddenInput);

  const firstEmptyIndex = boxes.findIndex((box) => box.value === '');
  focusBox(boxes, firstEmptyIndex === -1 ? boxes.length - 1 : firstEmptyIndex);
};

const showInvalidPasteFeedback = (wrapper) => {
  wrapper.classList.add('shake');
  window.setTimeout(() => wrapper.classList.remove('shake'), 320);
};

const handleInput = (event, boxes, hiddenInput) => {
  const input = event.target;
  const index = Number.parseInt(input.dataset.pinIndex, 10);
  const value = sanitizeValue(input.value);
  const firstChar = value.charAt(0) ?? '';

  clearInvalidState(boxes);
  input.value = firstChar;
  toggleValueState(input);

  if (firstChar && !ALLOWED_CHARS_REGEX.test(firstChar)) {
    input.value = '';
    toggleValueState(input);
    return;
  }

  updateHiddenInputValue(boxes, hiddenInput);

  if (firstChar && index < boxes.length - 1) {
    focusBox(boxes, index + 1);
  }
};

const handleKeyDown = (event, boxes, hiddenInput) => {
  const { key } = event;
  const input = event.target;
  const index = Number.parseInt(input.dataset.pinIndex, 10);

  switch (key) {
    case 'Backspace': {
      event.preventDefault();
      clearInvalidState(boxes);

      if (input.value === '' && index > 0) {
        focusBox(boxes, index - 1);
        boxes[index - 1].value = '';
        toggleValueState(boxes[index - 1]);
      } else {
        input.value = '';
        toggleValueState(input);
      }

      updateHiddenInputValue(boxes, hiddenInput);
      break;
    }

    case 'Delete': {
      event.preventDefault();
      input.value = '';
      toggleValueState(input);
      clearInvalidState(boxes);
      updateHiddenInputValue(boxes, hiddenInput);
      break;
    }

    case 'ArrowLeft': {
      event.preventDefault();
      focusBox(boxes, index - 1);
      break;
    }

    case 'ArrowRight':
    case ' ': {
      event.preventDefault();
      focusBox(boxes, index + 1);
      break;
    }

    default:
      break;
  }
};

const handlePaste = (event, boxes, hiddenInput, wrapper) => {
  event.preventDefault();

  const clipboardData = event.clipboardData?.getData('text') ?? '';
  const sanitized = sanitizeValue(clipboardData);

  if (sanitized.length === 0) {
    return;
  }

  if (sanitized.length !== PIN_LENGTH) {
    showToast(
      window.efsData?.i18n?.invalidPinPaste ?? 'Application passwords must be 24 characters.',
      TOAST_ERROR_TYPE,
    );
    showInvalidPasteFeedback(wrapper);
    return;
  }

  clearInvalidState(boxes);
  setBoxValues(boxes, sanitized, hiddenInput);
};

const handleFocus = (event) => {
  event.target.classList.add('is-focused');
  event.target.select();
};

const handleBlur = (event) => {
  event.target.classList.remove('is-focused');
};

const attachPasteButton = (container, boxes, hiddenInput, wrapper) => {
  const field = container.closest('[data-efs-field]') ?? container.parentElement;
  const pasteButton = field?.querySelector('[data-efs-pin-paste]');

  if (!pasteButton) {
    return;
  }

  pasteButton.addEventListener('click', async () => {
    if (!navigator?.clipboard?.readText) {
      showToast(
        window.efsData?.i18n?.clipboardUnavailable ?? 'Clipboard access is unavailable in this browser.',
        TOAST_ERROR_TYPE,
      );
      showInvalidPasteFeedback(wrapper);
      return;
    }

    try {
      const text = await navigator.clipboard.readText();
      const sanitized = sanitizeValue(text);

      if (sanitized.length !== PIN_LENGTH) {
        showToast(
          window.efsData?.i18n?.invalidPinPaste ?? 'Application passwords must be 24 characters.',
          TOAST_ERROR_TYPE,
        );
        showInvalidPasteFeedback(wrapper);
        return;
      }

      clearInvalidState(boxes);
      setBoxValues(boxes, sanitized, hiddenInput);
    } catch (error) {
      showToast(
        window.efsData?.i18n?.clipboardReadError ?? 'Unable to read from clipboard. Paste manually instead.',
        TOAST_ERROR_TYPE,
      );
      showInvalidPasteFeedback(wrapper);
    }
  });
};

const createPinInput = (container) => {
  const hiddenInput = container.querySelector('input[type="hidden"]');

  if (!hiddenInput) {
    // eslint-disable-next-line no-console
    console.warn('Etch Fusion Suite: Missing hidden input for PIN control.');
    return null;
  }

  const wrapper = document.createElement('div');
  wrapper.className = 'efs-pin-input-wrapper';
  const boxes = [];
  let firstBoxId = '';

  for (let block = 0; block < BLOCK_COUNT; block += 1) {
    const blockEl = document.createElement('div');
    blockEl.className = 'efs-pin-input-block';

    for (let indexInBlock = 0; indexInBlock < BLOCK_SIZE; indexInBlock += 1) {
      const index = block * BLOCK_SIZE + indexInBlock;
      const input = document.createElement('input');
      input.type = 'text';
      input.inputMode = 'latin';
      input.autocomplete = 'off';
      input.spellcheck = false;
      input.maxLength = 1;
      input.className = 'efs-pin-input-box';
      input.dataset.pinIndex = String(index);
      input.setAttribute('aria-label', `Application password character ${index + 1} of ${PIN_LENGTH}`);

      if (index === 0 && hiddenInput.id) {
        firstBoxId = `${hiddenInput.id}-0`;
        input.id = firstBoxId;
      }

      input.addEventListener('input', (event) => handleInput(event, boxes, hiddenInput));
      input.addEventListener('keydown', (event) => handleKeyDown(event, boxes, hiddenInput));
      input.addEventListener('paste', (event) => handlePaste(event, boxes, hiddenInput, wrapper));
      input.addEventListener('focus', handleFocus);
      input.addEventListener('blur', handleBlur);

      blockEl.appendChild(input);
      boxes.push(input);
    }

    wrapper.appendChild(blockEl);
  }

  container.setAttribute('role', 'group');
  container.appendChild(wrapper);

  const controls = container.querySelector('.efs-pin-input-controls');
  if (controls) {
    controls.remove();
  }

  attachPasteButton(container, boxes, hiddenInput, wrapper);

  if (firstBoxId) {
    const field = container.closest('[data-efs-field]') ?? container.parentElement;
    const label = field?.querySelector(`label[for="${hiddenInput.id}"]`);
    if (label) {
      label.setAttribute('for', firstBoxId);
      if (!label.id) {
        label.id = `${firstBoxId}-label`;
      }
      if (!container.getAttribute('aria-labelledby')) {
        container.setAttribute('aria-labelledby', label.id);
      }
    }
  }

  if (hiddenInput.value) {
    setBoxValues(boxes, hiddenInput.value, hiddenInput);
  }

  return {
    getValue: () => getBoxValue(boxes),
    setValue: (value) => setBoxValues(boxes, value, hiddenInput),
    clear: () => clearBoxes(boxes, hiddenInput),
    focus: () => {
      const firstEmptyIndex = boxes.findIndex((box) => box.value === '');
      focusBox(boxes, firstEmptyIndex === -1 ? boxes.length - 1 : firstEmptyIndex);
    },
  };
};

export const initPinInput = () => {
  const containers = Array.from(document.querySelectorAll('[data-efs-pin-input]'));

  if (containers.length === 0) {
    return [];
  }

  const instances = containers
    .map((container) => createPinInput(container))
    .filter((instance) => instance !== null);

  return instances;
};

export default initPinInput;
