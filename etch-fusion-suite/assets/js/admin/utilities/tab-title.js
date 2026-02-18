export const updateTabTitle = (progress, status) => {
	if (progress === null || progress === undefined) {
		return;
	}

	const rounded = Math.round(Number(progress) || 0);
	const label = status === null || status === undefined || status === ''
		? 'Migration running'
		: String(status);
	document.title = `${rounded}% – ${label} – EtchFusion Suite`;
};

export const resetTabTitle = () => {
	document.title = 'EtchFusion Suite';
};
