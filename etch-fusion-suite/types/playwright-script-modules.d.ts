declare module '../../scripts/health-check.js' {
  export interface HealthSummary {
    pass: number;
    warning: number;
    fail: number;
    total: number;
    passed: number;
    warnings: number;
    failed: number;
  }

  export interface HealthCheckResult {
    summary: HealthSummary;
  }

  export function runHealthCheck(environmentFilter?: string | null): Promise<HealthCheckResult>;
}

declare module '../../scripts/save-logs.js' {
  export interface SavedLogFile {
    name: string;
    path: string;
    size: string;
  }

  export interface SaveLogsResult {
    timestamp: string;
    files: SavedLogFile[];
    totalSize: string;
    lines: number;
    compressed: boolean;
  }

  export function saveLogs(lines?: number, compress?: boolean): Promise<SaveLogsResult>;
}

