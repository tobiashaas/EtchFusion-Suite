export const createProgressChip = (container) => {
	if (!container) {
		return null;
	}

	const chip = document.createElement('div');
	chip.className = 'efs-wizard-progress-chip';
	chip.setAttribute('data-efs-progress-chip', '');
	chip.setAttribute('role', 'button');
	chip.setAttribute('tabindex', '0');
	chip.innerHTML = `
		<span class="efs-wizard-progress-chip__icon" aria-hidden="true"></span>
		<span class="efs-wizard-progress-chip__text">Migration running: 0%</span>
	`;
	container.appendChild(chip);
	return chip;
};

export const updateProgressChip = (chip, progress) => {
	if (!chip) {
		return;
	}

	const text = chip.querySelector('.efs-wizard-progress-chip__text');
	if (text) {
		text.textContent = `Migration running: ${Math.round(Number(progress) || 0)}%`;
	}
};

export const removeProgressChip = (chip) => {
	chip?.remove();
};
