import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { HealthResponse } from '$lib/api';

// Mock the api module before importing the store
vi.mock('$lib/api', () => ({
	api: {
		updateHeatTargetSettings: vi.fn()
	}
}));

// Import after mocking
import {
	initFromHealthResponse,
	updateSettings,
	getEnabled,
	getTargetTempF,
	getHeatButtonLabel,
	getHeatButtonTooltip,
	getMinTempF,
	getMaxTempF
} from './heatTargetSettings.svelte';
import { api } from '$lib/api';

describe('heatTargetSettings store', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		// Reset to defaults by initializing with empty response
		initFromHealthResponse({
			status: 'ok',
			ifttt_mode: 'stub',
			equipmentStatus: {
				heater: { on: false, lastChangedAt: null },
				pump: { on: false, lastChangedAt: null }
			}
		});
	});

	describe('initFromHealthResponse', () => {
		it('initializes from health response with settings', () => {
			const response: HealthResponse = {
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 105.5
				}
			};

			initFromHealthResponse(response);

			expect(getEnabled()).toBe(true);
			expect(getTargetTempF()).toBe(105.5);
		});

		it('keeps defaults when health response has no settings', () => {
			const response: HealthResponse = {
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				}
			};

			initFromHealthResponse(response);

			expect(getEnabled()).toBe(false);
			expect(getTargetTempF()).toBe(103.0);
		});
	});

	describe('getHeatButtonLabel', () => {
		it('returns "Heat On" when disabled', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: false,
					target_temp_f: 103
				}
			});

			expect(getHeatButtonLabel()).toBe('Heat On');
		});

		it('returns "Heat to XXX°F" when enabled', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 105
				}
			});

			expect(getHeatButtonLabel()).toBe('Heat to 105°F');
		});

		it('formats quarter degrees correctly', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 103.25
				}
			});

			expect(getHeatButtonLabel()).toBe('Heat to 103.25°F');
		});

		it('formats half degrees correctly', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 103.5
				}
			});

			expect(getHeatButtonLabel()).toBe('Heat to 103.5°F');
		});
	});

	describe('getHeatButtonTooltip', () => {
		it('returns standard tooltip when disabled', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: false,
					target_temp_f: 103
				}
			});

			expect(getHeatButtonTooltip()).toBe('Turn on the hot tub heater');
		});

		it('returns target-specific tooltip when enabled', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 105
				}
			});

			expect(getHeatButtonTooltip()).toBe(
				'Heat water to 105°F and automatically turn off'
			);
		});
	});

	describe('updateSettings', () => {
		it('calls API with correct parameters', async () => {
			const mockResponse = { enabled: true, target_temp_f: 106, message: 'Settings updated' };
			vi.mocked(api.updateHeatTargetSettings).mockResolvedValue(mockResponse);

			await updateSettings(true, 106);

			expect(api.updateHeatTargetSettings).toHaveBeenCalledWith(true, 106);
		});

		it('updates local state on success', async () => {
			const mockResponse = { enabled: true, target_temp_f: 107, message: 'Settings updated' };
			vi.mocked(api.updateHeatTargetSettings).mockResolvedValue(mockResponse);

			await updateSettings(true, 107);

			expect(getEnabled()).toBe(true);
			expect(getTargetTempF()).toBe(107);
		});

		it('throws and sets error on API failure', async () => {
			vi.mocked(api.updateHeatTargetSettings).mockRejectedValue(new Error('Forbidden'));

			await expect(updateSettings(true, 105)).rejects.toThrow('Forbidden');
		});
	});

	describe('constants', () => {
		it('returns correct min/max temperatures', () => {
			expect(getMinTempF()).toBe(80);
			expect(getMaxTempF()).toBe(110);
		});
	});
});
