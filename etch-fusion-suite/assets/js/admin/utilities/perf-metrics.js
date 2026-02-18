class PerfMetrics {
	constructor() {
		this.enabled = this.isLocalhost();
		this.reset();
	}

	isLocalhost() {
		const host = window?.location?.hostname || '';
		return host === 'localhost' || host === '127.0.0.1';
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
		console.log('[EFS][Perf] Discovery started');
	}

	endDiscovery() {
		if (!this.enabled || !this.discoveryStartTime) {
			return;
		}
		this.discoveryDuration = performance.now() - this.discoveryStartTime;
		console.log('[EFS][Perf] Discovery completed', {
			durationMs: Math.round(this.discoveryDuration),
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
		console.log('[EFS][Perf] Migration started', {
			totalItems: this.totalItems,
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
		console.log('[EFS][Perf] Batch processed', {
			itemsProcessed: normalizedItems,
			batchDurationMs: Math.round(normalizedDuration),
			totalProcessed: this.processedItems,
			totalItems: this.totalItems,
		});
	}

	endMigration() {
		if (!this.enabled || !this.migrationStartTime) {
			return;
		}
		this.migrationDuration = performance.now() - this.migrationStartTime;
		const averageBatchTime = this.batchTimes.length
			? this.batchTimes.reduce((sum, value) => sum + value, 0) / this.batchTimes.length
			: 0;
		console.log('[EFS][Perf] Migration completed', {
			durationMs: Math.round(this.migrationDuration),
			batches: this.batchTimes.length,
			avgBatchMs: Math.round(averageBatchTime),
			processedItems: this.processedItems,
			totalItems: this.totalItems,
		});
	}

	getBottleneckHints() {
		if (!this.enabled || !this.batchTimes.length) {
			return [];
		}

		const hints = [];
		const averageBatchTime = this.batchTimes.reduce((sum, value) => sum + value, 0) / this.batchTimes.length;
		if (averageBatchTime > 5000) {
			hints.push(`Average batch time is high (${Math.round(averageBatchTime)}ms).`);
		}

		const slowBatchIndexes = [];
		this.batchTimes.forEach((time, index) => {
			if (time > averageBatchTime * 2) {
				slowBatchIndexes.push(index + 1);
			}
		});
		if (slowBatchIndexes.length) {
			hints.push(`Slow batches detected: ${slowBatchIndexes.join(', ')}.`);
		}

		return hints;
	}
}

export const perfMetrics = new PerfMetrics();
