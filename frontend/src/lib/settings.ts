/**
 * Application settings utilities
 *
 * Manages localStorage persistence for user preferences.
 */

import type { TemperatureData } from './api';

const STORAGE_KEY_REFRESH_TEMP_ON_HEATER_OFF = 'hotTubRefreshTempOnHeaterOff';
const STORAGE_KEY_TEMPERATURE_CACHE = 'hotTubTemperatureCache';

export interface CachedTemperature extends TemperatureData {
	cachedAt: number;
}

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

/**
 * Get cached temperature data from localStorage
 */
export function getCachedTemperature(): CachedTemperature | null {
	const stored = localStorage.getItem(STORAGE_KEY_TEMPERATURE_CACHE);
	if (stored === null) {
		return null;
	}
	try {
		return JSON.parse(stored) as CachedTemperature;
	} catch {
		return null;
	}
}

/**
 * Save temperature data to localStorage cache
 */
export function setCachedTemperature(data: TemperatureData): void {
	const cached: CachedTemperature = {
		...data,
		cachedAt: Date.now()
	};
	localStorage.setItem(STORAGE_KEY_TEMPERATURE_CACHE, JSON.stringify(cached));
}
