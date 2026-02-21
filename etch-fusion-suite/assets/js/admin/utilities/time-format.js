/**
 * Format elapsed seconds as mm:ss. Returns '00:00' for null/negative/invalid.
 *
 * @param {number|null|undefined} seconds
 * @return {string}
 */
export const formatElapsed = (seconds) => {
    if (seconds == null || typeof seconds !== 'number' || seconds < 0 || Number.isNaN(seconds)) {
        return '00:00';
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
};

/**
 * Format ETA seconds for display. Returns null when ETA is not available so callers can hide the ETA.
 *
 * @param {number|null|undefined} seconds
 * @return {string|null}
 */
export const formatEta = (seconds) => {
    if (seconds == null || seconds === 0 || typeof seconds !== 'number' || Number.isNaN(seconds) || seconds < 0) {
        return null;
    }
    if (seconds < 60) {
        return '< 1m remaining';
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    if (secs === 0) {
        return `~${mins}m remaining`;
    }
    return `~${mins}m ${secs}s remaining`;
};
