import { api, type HeatTargetSettings, type HealthResponse } from '$lib/api';

/**
 * Store for shared heat-target settings from the backend.
 *
 * These settings are admin-configurable and affect all users:
 * - enabled: whether to use heat-to-target mode
 * - target_temp_f: the target temperature in Fahrenheit
 *
 * Settings are initialized from the /api/health response and can be
 * updated via the admin-only PUT endpoint.
 */

// Default values matching backend HeatTargetSettingsService
const DEFAULT_ENABLED = false;
const DEFAULT_TARGET_TEMP_F = 103.0;
const MIN_TEMP_F = 80.0;
const MAX_TEMP_F = 110.0;

// Reactive state using Svelte 5 runes
let enabled = $state(DEFAULT_ENABLED);
let targetTempF = $state(DEFAULT_TARGET_TEMP_F);
let isLoading = $state(false);
let error = $state<string | null>(null);
let initialized = $state(false);

/**
 * Initialize settings from the /api/health response.
 * Called by equipmentStatus store when health response arrives.
 */
export function initFromHealthResponse(response: HealthResponse): void {
	if (response.heatTargetSettings) {
		enabled = response.heatTargetSettings.enabled;
		targetTempF = response.heatTargetSettings.target_temp_f;
	} else {
		// Reset to defaults when backend doesn't provide settings
		enabled = DEFAULT_ENABLED;
		targetTempF = DEFAULT_TARGET_TEMP_F;
	}
	initialized = true;
}

/**
 * Update settings via the admin-only API endpoint.
 *
 * @param newEnabled Whether heat-to-target mode should be enabled
 * @param newTargetTempF Target temperature in Fahrenheit
 * @throws Error if the API call fails or user is not admin
 */
export async function updateSettings(
	newEnabled: boolean,
	newTargetTempF: number
): Promise<void> {
	isLoading = true;
	error = null;

	try {
		const response = await api.updateHeatTargetSettings(newEnabled, newTargetTempF);
		enabled = response.enabled;
		targetTempF = response.target_temp_f;
	} catch (e) {
		error = e instanceof Error ? e.message : 'Failed to update settings';
		throw e;
	} finally {
		isLoading = false;
	}
}

/**
 * Check if heat-to-target mode is enabled.
 */
export function getEnabled(): boolean {
	return enabled;
}

/**
 * Get the target temperature in Fahrenheit.
 */
export function getTargetTempF(): number {
	return targetTempF;
}

/**
 * Get the appropriate button label based on settings.
 *
 * Returns "Heat to XXX°F" when enabled, "Heat On" when disabled.
 */
export function getHeatButtonLabel(): string {
	if (enabled) {
		// Format temperature, removing trailing zeros
		const tempDisplay = Number.isInteger(targetTempF)
			? targetTempF.toString()
			: targetTempF.toFixed(2).replace(/\.?0+$/, '');
		return `Heat to ${tempDisplay}°F`;
	}
	return 'Heat On';
}

/**
 * Get the button tooltip based on settings.
 */
export function getHeatButtonTooltip(): string {
	if (enabled) {
		return `Heat water to ${targetTempF}°F and automatically turn off`;
	}
	return 'Turn on the hot tub heater';
}

/**
 * Check if settings are currently being loaded/saved.
 */
export function getIsLoading(): boolean {
	return isLoading;
}

/**
 * Get any error that occurred during the last operation.
 */
export function getError(): string | null {
	return error;
}

/**
 * Check if settings have been initialized from the backend.
 */
export function getIsInitialized(): boolean {
	return initialized;
}

/**
 * Get the minimum allowed target temperature.
 */
export function getMinTempF(): number {
	return MIN_TEMP_F;
}

/**
 * Get the maximum allowed target temperature.
 */
export function getMaxTempF(): number {
	return MAX_TEMP_F;
}
