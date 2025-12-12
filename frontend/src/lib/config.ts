/**
 * Configuration for the scheduler timing.
 * Values can be overridden via environment variables for testing.
 *
 * Environment variables (set at build time via Vite):
 * - VITE_REFRESH_BUFFER_MS: Buffer after scheduled time before checking (default: 60000 = 1 min)
 * - VITE_MAX_TIMER_WINDOW_MS: Max time ahead to set timers (default: 3600000 = 1 hour)
 * - VITE_RECHECK_INTERVAL_MS: Sliding window recheck interval (default: 1800000 = 30 min)
 */

function getEnvNumber(key: string, defaultValue: number): number {
	const value = import.meta.env[key];
	if (value === undefined || value === '') {
		return defaultValue;
	}
	const parsed = parseInt(value, 10);
	return isNaN(parsed) ? defaultValue : parsed;
}

function getEnvString(key: string, defaultValue: string): string {
	const value = import.meta.env[key];
	return value === undefined || value === '' ? defaultValue : value;
}

export const schedulerConfig = {
	/** Buffer time after scheduled execution to check status (default: 60 seconds) */
	refreshBufferMs: getEnvNumber('VITE_REFRESH_BUFFER_MS', 60 * 1000),

	/** Only set timers for jobs within this window (default: 1 hour) */
	maxTimerWindowMs: getEnvNumber('VITE_MAX_TIMER_WINDOW_MS', 60 * 60 * 1000),

	/** Recheck interval for sliding window (default: 30 minutes) */
	recheckIntervalMs: getEnvNumber('VITE_RECHECK_INTERVAL_MS', 30 * 60 * 1000),
};

/**
 * Build information injected at build time via GitHub Actions.
 * Shows "Local Dev" when running locally without env vars.
 */
export const buildInfo = {
	gitSha: getEnvString('VITE_GIT_SHA', 'dev'),
	buildNumber: getEnvString('VITE_BUILD_NUMBER', 'local'),
	buildDate: getEnvString('VITE_BUILD_DATE', 'Local Dev'),
	githubRepo: getEnvString('VITE_GITHUB_REPO', ''),
	get version(): string {
		if (this.gitSha === 'dev') return 'Local Dev';
		return `${this.gitSha} (Build ${this.buildNumber}) • ${this.buildDate}`;
	},
	get commitUrl(): string | null {
		if (this.gitSha === 'dev' || !this.githubRepo) return null;
		return `https://github.com/${this.githubRepo}/commit/${this.gitSha}`;
	}
};
