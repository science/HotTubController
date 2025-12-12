/**
 * Application settings utilities
 *
 * Manages localStorage persistence for user preferences.
 */

const STORAGE_KEY_REFRESH_TEMP_ON_HEATER_OFF = 'hotTubRefreshTempOnHeaterOff';

export const SETTINGS_DEFAULTS = {
	refreshTempOnHeaterOff: true
} as const;

/**
 * Get "refresh temperature on heater-off" setting from localStorage
 */
export function getRefreshTempOnHeaterOff(): boolean {
	const stored = localStorage.getItem(STORAGE_KEY_REFRESH_TEMP_ON_HEATER_OFF);
	if (stored === null) {
		return SETTINGS_DEFAULTS.refreshTempOnHeaterOff;
	}
	return stored === 'true';
}

/**
 * Set "refresh temperature on heater-off" setting to localStorage
 */
export function setRefreshTempOnHeaterOff(enabled: boolean): void {
	localStorage.setItem(STORAGE_KEY_REFRESH_TEMP_ON_HEATER_OFF, enabled ? 'true' : 'false');
}
