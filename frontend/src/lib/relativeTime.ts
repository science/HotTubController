/**
 * Relative age formatting for sensor readings ("2m ago"), shared by the v2 Home
 * temperature hero. Kept pure (now injectable) for testability.
 */

/** A reading older than this is considered stale (matches TemperaturePanel's rule). */
export const STALE_THRESHOLD_MS = 10 * 60 * 1000;

/**
 * Human age of a timestamp: "just now" (<1 min), "14m ago", "1h 30m ago", then a
 * short date past 24h. A future timestamp (clock skew) reads "just now".
 */
export function formatRelativeAge(timestamp: string, now: Date = new Date()): string {
	const ageMs = now.getTime() - new Date(timestamp).getTime();
	if (ageMs < 60_000) return 'just now';

	const minutes = Math.floor(ageMs / 60_000);
	if (minutes < 60) return `${minutes}m ago`;

	const hours = Math.floor(minutes / 60);
	if (hours < 24) {
		const rem = minutes % 60;
		return rem === 0 ? `${hours}h ago` : `${hours}h ${rem}m ago`;
	}

	return new Date(timestamp).toLocaleString(undefined, {
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit'
	});
}

/** Whether a reading is stale (older than 10 minutes). Missing timestamp ⇒ not stale. */
export function isStaleReading(timestamp: string | undefined, now: Date = new Date()): boolean {
	if (!timestamp) return false;
	return now.getTime() - new Date(timestamp).getTime() > STALE_THRESHOLD_MS;
}
