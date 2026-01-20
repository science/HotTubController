/**
 * Application settings utilities
 *
 * Manages localStorage persistence for user preferences.
 */

import type { TemperatureData } from './api';

const STORAGE_KEY_REFRESH_TEMP_ON_HEATER_OFF = 'hotTubRefreshTempOnHeaterOff';
const STORAGE_KEY_TEMPERATURE_CACHE = 'hotTubTemperatureCache';
const STORAGE_KEY_TARGET_TEMP_ENABLED = 'hotTubTargetTempEnabled';
const STORAGE_KEY_TARGET_TEMP_F = 'hotTubTargetTempF';

export interface CachedTemperature extends TemperatureData {
	cachedAt: number;
}

export const SETTINGS_DEFAULTS = {
	refreshTempOnHeaterOff: true
} as const;

export const TARGET_TEMP_DEFAULTS = {
	enabled: false, // Off by default (explicit opt-in)
	targetTempF: 103, // Typical hot tub temperature
	minTempF: 80,
	maxTempF: 110
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
 * Get target temperature mode enabled state from localStorage
 */
export function getTargetTempEnabled(): boolean {
	const stored = localStorage.getItem(STORAGE_KEY_TARGET_TEMP_ENABLED);
	if (stored === null) {
		return TARGET_TEMP_DEFAULTS.enabled;
	}
	return stored === 'true';
}

/**
 * Set target temperature mode enabled state to localStorage
 */
export function setTargetTempEnabled(enabled: boolean): void {
	localStorage.setItem(STORAGE_KEY_TARGET_TEMP_ENABLED, enabled ? 'true' : 'false');
}

/**
 * Get target temperature in Fahrenheit from localStorage
 * Supports quarter-degree precision (e.g., 103.25, 103.5, 103.75)
 */
export function getTargetTempF(): number {
	const stored = localStorage.getItem(STORAGE_KEY_TARGET_TEMP_F);
	if (stored === null) {
		return TARGET_TEMP_DEFAULTS.targetTempF;
	}
	const parsed = parseFloat(stored);
	if (isNaN(parsed)) {
		return TARGET_TEMP_DEFAULTS.targetTempF;
	}
	return parsed;
}

/**
 * Round to nearest quarter degree (0.25 increments)
 */
function roundToQuarterDegree(temp: number): number {
	return Math.round(temp * 4) / 4;
}

/**
 * Set target temperature in Fahrenheit to localStorage
 * Rounds to nearest quarter degree and clamps between min (80) and max (110)
 */
export function setTargetTempF(temp: number): void {
	const rounded = roundToQuarterDegree(temp);
	const clamped = Math.max(
		TARGET_TEMP_DEFAULTS.minTempF,
		Math.min(TARGET_TEMP_DEFAULTS.maxTempF, rounded)
	);
	localStorage.setItem(STORAGE_KEY_TARGET_TEMP_F, clamped.toString());
}
