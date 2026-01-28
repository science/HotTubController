import { api, type EquipmentStatus, type HealthResponse } from '$lib/api';
import { initFromHealthResponse as initHeatTargetSettings } from './heatTargetSettings.svelte';

export interface EquipmentStatusState {
	heaterOn: boolean;
	pumpOn: boolean;
	heaterLastChangedAt: Date | null;
	pumpLastChangedAt: Date | null;
	blindsEnabled: boolean;
	lastUpdated: Date | null;
	isLoading: boolean;
	error: string | null;
}

// Reactive state using Svelte 5 runes
let heaterOn = $state(false);
let pumpOn = $state(false);
let heaterLastChangedAt = $state<Date | null>(null);
let pumpLastChangedAt = $state<Date | null>(null);
let blindsEnabled = $state(false);
let lastUpdated = $state<Date | null>(null);
let isLoading = $state(false);
let error = $state<string | null>(null);

/**
 * Fetch equipment status from the server
 */
export async function fetchStatus(): Promise<void> {
	isLoading = true;
	error = null;

	try {
		const response = await api.getHealth();

		if (response.equipmentStatus) {
			updateFromResponse(response.equipmentStatus);
		}
		// Update blinds feature flag from health response
		blindsEnabled = response.blindsEnabled ?? false;
		// Initialize heat-target settings from health response
		initHeatTargetSettings(response);
	} catch (e) {
		error = e instanceof Error ? e.message : 'Failed to fetch status';
	} finally {
		isLoading = false;
	}
}

/**
 * Update local state from API response
 */
export function updateFromResponse(status: EquipmentStatus): void {
	heaterOn = status.heater.on;
	pumpOn = status.pump.on;
	heaterLastChangedAt = status.heater.lastChangedAt
		? new Date(status.heater.lastChangedAt)
		: null;
	pumpLastChangedAt = status.pump.lastChangedAt ? new Date(status.pump.lastChangedAt) : null;
	lastUpdated = new Date();
}

/**
 * Set heater on (optimistic update after button press)
 */
export function setHeaterOn(): void {
	heaterOn = true;
	heaterLastChangedAt = new Date();
	lastUpdated = new Date();
}

/**
 * Set heater off (optimistic update after button press)
 * Note: Also turns off pump because the hardware controller
 * stops both when heater-off is triggered.
 */
export function setHeaterOff(): void {
	const now = new Date();
	heaterOn = false;
	heaterLastChangedAt = now;
	pumpOn = false;
	pumpLastChangedAt = now;
	lastUpdated = now;
}

/**
 * Set pump on (optimistic update after button press)
 */
export function setPumpOn(): void {
	pumpOn = true;
	pumpLastChangedAt = new Date();
	lastUpdated = new Date();
}

/**
 * Set pump off (optimistic update)
 */
export function setPumpOff(): void {
	pumpOn = false;
	pumpLastChangedAt = new Date();
	lastUpdated = new Date();
}

// Export reactive getters
export function getEquipmentState(): EquipmentStatusState {
	return {
		heaterOn,
		pumpOn,
		heaterLastChangedAt,
		pumpLastChangedAt,
		blindsEnabled,
		lastUpdated,
		isLoading,
		error
	};
}

export function getHeaterOn(): boolean {
	return heaterOn;
}

export function getPumpOn(): boolean {
	return pumpOn;
}

export function getLastUpdated(): Date | null {
	return lastUpdated;
}

export function getIsLoading(): boolean {
	return isLoading;
}

export function getError(): string | null {
	return error;
}

export function getBlindsEnabled(): boolean {
	return blindsEnabled;
}
