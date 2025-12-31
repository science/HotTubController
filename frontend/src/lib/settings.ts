/**
 * Application settings utilities
 *
 * Manages localStorage persistence for user preferences.
 */

import type { TemperatureData } from './api';

const STORAGE_KEY_REFRESH_TEMP_ON_HEATER_OFF = 'hotTubRefreshTempOnHeaterOff';
const STORAGE_KEY_TEMPERATURE_CACHE = 'hotTubTemperatureCache';
const STORAGE_KEY_TEMP_SOURCE_ESP32 = 'hotTubTempSourceEsp32Enabled';
const STORAGE_KEY_TEMP_SOURCE_WIRELESSTAG = 'hotTubTempSourceWirelessTagEnabled';

export interface CachedTemperature extends TemperatureData {
	cachedAt: number;
}

export interface TempSourceSettings {
	esp32Enabled: boolean;
	wirelessTagEnabled: boolean;
}

export const SETTINGS_DEFAULTS = {
	refreshTempOnHeaterOff: true,
	esp32Enabled: true,
	wirelessTagEnabled: true
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

/**
 * Get temperature source settings from localStorage
 */
export function getTempSourceSettings(): TempSourceSettings {
	const esp32Stored = localStorage.getItem(STORAGE_KEY_TEMP_SOURCE_ESP32);
	const wirelessTagStored = localStorage.getItem(STORAGE_KEY_TEMP_SOURCE_WIRELESSTAG);

	return {
		esp32Enabled: esp32Stored === null ? SETTINGS_DEFAULTS.esp32Enabled : esp32Stored === 'true',
		wirelessTagEnabled:
			wirelessTagStored === null
				? SETTINGS_DEFAULTS.wirelessTagEnabled
				: wirelessTagStored === 'true'
	};
}

/**
 * Set ESP32 temperature source enabled/disabled
 */
export function setEsp32Enabled(enabled: boolean): void {
	localStorage.setItem(STORAGE_KEY_TEMP_SOURCE_ESP32, enabled ? 'true' : 'false');
}

/**
 * Set WirelessTag temperature source enabled/disabled
 */
export function setWirelessTagEnabled(enabled: boolean): void {
	localStorage.setItem(STORAGE_KEY_TEMP_SOURCE_WIRELESSTAG, enabled ? 'true' : 'false');
}
