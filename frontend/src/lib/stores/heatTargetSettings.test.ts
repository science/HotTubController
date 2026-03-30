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
	getMaxTempF,
	getStallGracePeriodMinutes,
	getStallTimeoutMinutes,
	getLastStallEvent,
	getDynamicMode,
	getCalibrationPoints
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
					timezone: 'America/Los_Angeles',
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
					timezone: 'America/Los_Angeles',
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
					timezone: 'America/Los_Angeles',
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
					timezone: 'America/Los_Angeles',
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
					timezone: 'America/Los_Angeles',
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
					timezone: 'America/Los_Angeles',
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
					timezone: 'America/Los_Angeles',
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
			const mockResponse = { enabled: true, target_temp_f: 106, timezone: 'America/Los_Angeles', message: 'Settings updated' };
			vi.mocked(api.updateHeatTargetSettings).mockResolvedValue(mockResponse);

			await updateSettings(true, 106);

			expect(api.updateHeatTargetSettings).toHaveBeenCalledWith(true, 106, undefined, undefined, undefined, undefined, undefined, undefined);
		});

		it('updates local state on success', async () => {
			const mockResponse = { enabled: true, target_temp_f: 107, timezone: 'America/Los_Angeles', message: 'Settings updated' };
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

	describe('stall detection settings', () => {
		it('returns default stall grace period', () => {
			expect(getStallGracePeriodMinutes()).toBe(15);
		});

		it('returns default stall timeout', () => {
			expect(getStallTimeoutMinutes()).toBe(5);
		});

		it('initializes stall settings from health response', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 105,
					timezone: 'America/Los_Angeles',
					stall_grace_period_minutes: 20,
					stall_timeout_minutes: 10
				}
			});

			expect(getStallGracePeriodMinutes()).toBe(20);
			expect(getStallTimeoutMinutes()).toBe(10);
		});

		it('returns null lastStallEvent by default', () => {
			expect(getLastStallEvent()).toBeNull();
		});

		it('initializes lastStallEvent from health response', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				lastStallEvent: {
					timestamp: '2026-02-11T12:00:00Z',
					current_temp_f: 92.3,
					target_temp_f: 103,
					reason: 'Temperature stalled'
				}
			});

			const event = getLastStallEvent();
			expect(event).not.toBeNull();
			expect(event?.current_temp_f).toBe(92.3);
			expect(event?.target_temp_f).toBe(103);
		});

		it('clears lastStallEvent when health response has none', () => {
			// First set a stall event
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				lastStallEvent: {
					timestamp: '2026-02-11T12:00:00Z',
					current_temp_f: 92.3,
					target_temp_f: 103,
					reason: 'Temperature stalled'
				}
			});
			expect(getLastStallEvent()).not.toBeNull();

			// Then reset without stall event
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				}
			});
			expect(getLastStallEvent()).toBeNull();
		});
	});

	describe('dynamic mode settings', () => {
		it('returns false dynamic mode by default', () => {
			expect(getDynamicMode()).toBe(false);
		});

		it('returns default calibration points', () => {
			const points = getCalibrationPoints();
			expect(points.cold.ambient_f).toBe(45.0);
			expect(points.cold.water_target_f).toBe(104.0);
			expect(points.comfort.ambient_f).toBe(60.0);
			expect(points.comfort.water_target_f).toBe(102.0);
			expect(points.hot.ambient_f).toBe(75.0);
			expect(points.hot.water_target_f).toBe(100.5);
		});

		it('initializes dynamic mode from health response', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 102,
					timezone: 'America/Los_Angeles',
					dynamic_mode: true,
					calibration_points: {
						cold:    { ambient_f: 40, water_target_f: 105 },
						comfort: { ambient_f: 55, water_target_f: 103 },
						hot:     { ambient_f: 70, water_target_f: 101 }
					}
				}
			});

			expect(getDynamicMode()).toBe(true);
			const points = getCalibrationPoints();
			expect(points.cold.ambient_f).toBe(40);
			expect(points.cold.water_target_f).toBe(105);
		});

		it('returns "Heat (Dynamic)" label when dynamic mode is on', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 102,
					timezone: 'America/Los_Angeles',
					dynamic_mode: true
				}
			});

			expect(getHeatButtonLabel()).toBe('Heat (Dynamic)');
		});

		it('returns dynamic tooltip when dynamic mode is on', () => {
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 102,
					timezone: 'America/Los_Angeles',
					dynamic_mode: true
				}
			});

			expect(getHeatButtonTooltip()).toBe('Heat to dynamic target based on ambient temperature');
		});

		it('updates dynamic mode via updateSettings', async () => {
			const mockResponse = {
				enabled: true,
				target_temp_f: 102,
				timezone: 'America/Los_Angeles',
				dynamic_mode: true,
				calibration_points: {
					cold:    { ambient_f: 40, water_target_f: 105 },
					comfort: { ambient_f: 55, water_target_f: 103 },
					hot:     { ambient_f: 70, water_target_f: 101 }
				},
				message: 'Settings updated'
			};
			vi.mocked(api.updateHeatTargetSettings).mockResolvedValue(mockResponse);

			await updateSettings(true, 102, undefined, undefined, undefined, undefined, true, {
				cold:    { ambient_f: 40, water_target_f: 105 },
				comfort: { ambient_f: 55, water_target_f: 103 },
				hot:     { ambient_f: 70, water_target_f: 101 }
			});

			expect(getDynamicMode()).toBe(true);
			expect(getCalibrationPoints().cold.ambient_f).toBe(40);
		});

		it('resets dynamic mode to defaults when health response has no settings', () => {
			// First set dynamic mode
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				},
				heatTargetSettings: {
					enabled: true,
					target_temp_f: 102,
					timezone: 'America/Los_Angeles',
					dynamic_mode: true
				}
			});
			expect(getDynamicMode()).toBe(true);

			// Then reset
			initFromHealthResponse({
				status: 'ok',
				ifttt_mode: 'stub',
				equipmentStatus: {
					heater: { on: false, lastChangedAt: null },
					pump: { on: false, lastChangedAt: null }
				}
			});
			expect(getDynamicMode()).toBe(false);
		});
	});
});
