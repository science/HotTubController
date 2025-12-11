/**
 * Auto Heat Off configuration utilities
 *
 * Manages localStorage persistence for auto heat-off settings and
 * provides time calculation for paired heater-off scheduling.
 */

const STORAGE_KEY_ENABLED = 'hotTubAutoHeatOff';
const STORAGE_KEY_MINUTES = 'hotTubAutoHeatOffMinutes';

export const AUTO_HEAT_OFF_DEFAULTS = {
	enabled: true,
	minutes: 45, // 45 minutes typical heating time
	minMinutes: 30,
	maxMinutes: 480 // 8 hours max
} as const;

/**
 * Get auto heat-off enabled state from localStorage
 */
export function getAutoHeatOffEnabled(): boolean {
	const stored = localStorage.getItem(STORAGE_KEY_ENABLED);
	if (stored === null) {
		return AUTO_HEAT_OFF_DEFAULTS.enabled;
	}
	return stored === 'true';
}

/**
 * Set auto heat-off enabled state to localStorage
 */
export function setAutoHeatOffEnabled(enabled: boolean): void {
	localStorage.setItem(STORAGE_KEY_ENABLED, enabled ? 'true' : 'false');
}

/**
 * Get auto heat-off duration in minutes from localStorage
 */
export function getAutoHeatOffMinutes(): number {
	const stored = localStorage.getItem(STORAGE_KEY_MINUTES);
	if (stored === null) {
		return AUTO_HEAT_OFF_DEFAULTS.minutes;
	}
	const parsed = parseInt(stored, 10);
	if (isNaN(parsed)) {
		return AUTO_HEAT_OFF_DEFAULTS.minutes;
	}
	return parsed;
}

/**
 * Set auto heat-off duration in minutes to localStorage
 * Clamps value between min (30) and max (480)
 */
export function setAutoHeatOffMinutes(minutes: number): void {
	const clamped = Math.max(
		AUTO_HEAT_OFF_DEFAULTS.minMinutes,
		Math.min(AUTO_HEAT_OFF_DEFAULTS.maxMinutes, minutes)
	);
	localStorage.setItem(STORAGE_KEY_MINUTES, clamped.toString());
}

/**
 * Calculate the heater-off time based on heater-on time plus duration
 *
 * @param heaterOnTime - ISO 8601 timestamp with timezone (e.g., "2024-12-11T06:00:00-05:00")
 * @param minutes - Duration in minutes to add
 * @returns ISO 8601 timestamp for heater-off time, preserving timezone offset
 */
export function calculateHeatOffTime(heaterOnTime: string, minutes: number): string {
	// Parse the timezone offset from the input string
	const tzMatch = heaterOnTime.match(/([+-]\d{2}:\d{2})$/);
	if (!tzMatch) {
		throw new Error(`Invalid timestamp format: ${heaterOnTime}`);
	}
	const tzOffset = tzMatch[1];

	// Parse the datetime (removing the timezone for Date parsing)
	const dateTimePart = heaterOnTime.slice(0, -6);
	const date = new Date(dateTimePart);

	// Add minutes
	date.setMinutes(date.getMinutes() + minutes);

	// Format as ISO 8601 with original timezone offset
	const year = date.getFullYear();
	const month = (date.getMonth() + 1).toString().padStart(2, '0');
	const day = date.getDate().toString().padStart(2, '0');
	const hours = date.getHours().toString().padStart(2, '0');
	const mins = date.getMinutes().toString().padStart(2, '0');
	const secs = date.getSeconds().toString().padStart(2, '0');

	return `${year}-${month}-${day}T${hours}:${mins}:${secs}${tzOffset}`;
}
