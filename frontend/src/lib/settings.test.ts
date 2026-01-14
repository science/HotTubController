import { describe, it, expect, beforeEach } from 'vitest';
import {
	getRefreshTempOnHeaterOff,
	setRefreshTempOnHeaterOff,
	getCachedTemperature,
	setCachedTemperature,
	getTargetTempEnabled,
	setTargetTempEnabled,
	getTargetTempF,
	setTargetTempF,
	type CachedTemperature,
	SETTINGS_DEFAULTS,
	TARGET_TEMP_DEFAULTS
} from './settings';

describe('settings', () => {
	beforeEach(() => {
		localStorage.clear();
	});

	describe('getRefreshTempOnHeaterOff', () => {
		it('returns default value when not set', () => {
			expect(getRefreshTempOnHeaterOff()).toBe(SETTINGS_DEFAULTS.refreshTempOnHeaterOff);
		});

		it('returns true when stored as "true"', () => {
			localStorage.setItem('hotTubRefreshTempOnHeaterOff', 'true');
			expect(getRefreshTempOnHeaterOff()).toBe(true);
		});

		it('returns false when stored as "false"', () => {
			localStorage.setItem('hotTubRefreshTempOnHeaterOff', 'false');
			expect(getRefreshTempOnHeaterOff()).toBe(false);
		});
	});

	describe('setRefreshTempOnHeaterOff', () => {
		it('stores true value', () => {
			setRefreshTempOnHeaterOff(true);
			expect(localStorage.getItem('hotTubRefreshTempOnHeaterOff')).toBe('true');
		});

		it('stores false value', () => {
			setRefreshTempOnHeaterOff(false);
			expect(localStorage.getItem('hotTubRefreshTempOnHeaterOff')).toBe('false');
		});

		it('persists value that can be retrieved', () => {
			setRefreshTempOnHeaterOff(false);
			expect(getRefreshTempOnHeaterOff()).toBe(false);

			setRefreshTempOnHeaterOff(true);
			expect(getRefreshTempOnHeaterOff()).toBe(true);
		});
	});

	describe('SETTINGS_DEFAULTS', () => {
		it('has refreshTempOnHeaterOff default as true', () => {
			expect(SETTINGS_DEFAULTS.refreshTempOnHeaterOff).toBe(true);
		});
	});

	describe('getCachedTemperature', () => {
		it('returns null when no cache exists', () => {
			expect(getCachedTemperature()).toBeNull();
		});

		it('returns cached temperature data when set', () => {
			const tempData: CachedTemperature = {
				water_temp_f: 99.5,
				water_temp_c: 37.5,
				ambient_temp_f: 65.0,
				ambient_temp_c: 18.3,
				battery_voltage: 3.5,
				signal_dbm: -60,
				device_name: 'Hot Tub',
				timestamp: '2025-12-11T10:30:00Z',
				cachedAt: Date.now()
			};
			localStorage.setItem('hotTubTemperatureCache', JSON.stringify(tempData));

			const result = getCachedTemperature();
			expect(result).not.toBeNull();
			expect(result!.water_temp_f).toBe(99.5);
			expect(result!.ambient_temp_f).toBe(65.0);
		});

		it('returns null if cached data is malformed JSON', () => {
			localStorage.setItem('hotTubTemperatureCache', 'not valid json');
			expect(getCachedTemperature()).toBeNull();
		});
	});

	describe('setCachedTemperature', () => {
		it('stores temperature data with cachedAt timestamp', () => {
			const tempData = {
				water_temp_f: 100.2,
				water_temp_c: 37.9,
				ambient_temp_f: 70.0,
				ambient_temp_c: 21.1,
				battery_voltage: 3.4,
				signal_dbm: -55,
				device_name: 'Hot Tub',
				timestamp: '2025-12-11T11:00:00Z'
			};

			const beforeSet = Date.now();
			setCachedTemperature(tempData);
			const afterSet = Date.now();

			const cached = getCachedTemperature();
			expect(cached).not.toBeNull();
			expect(cached!.water_temp_f).toBe(100.2);
			expect(cached!.cachedAt).toBeGreaterThanOrEqual(beforeSet);
			expect(cached!.cachedAt).toBeLessThanOrEqual(afterSet);
		});

		it('overwrites previous cache', () => {
			setCachedTemperature({
				water_temp_f: 95.0,
				water_temp_c: 35.0,
				ambient_temp_f: 60.0,
				ambient_temp_c: 15.5,
				battery_voltage: 3.5,
				signal_dbm: -60,
				device_name: 'Hot Tub',
				timestamp: '2025-12-11T10:00:00Z'
			});

			setCachedTemperature({
				water_temp_f: 102.0,
				water_temp_c: 38.9,
				ambient_temp_f: 72.0,
				ambient_temp_c: 22.2,
				battery_voltage: 3.4,
				signal_dbm: -58,
				device_name: 'Hot Tub',
				timestamp: '2025-12-11T12:00:00Z'
			});

			const cached = getCachedTemperature();
			expect(cached!.water_temp_f).toBe(102.0);
		});
	});

	describe('TARGET_TEMP_DEFAULTS', () => {
		it('has enabled default as false', () => {
			expect(TARGET_TEMP_DEFAULTS.enabled).toBe(false);
		});

		it('has targetTempF default as 103', () => {
			expect(TARGET_TEMP_DEFAULTS.targetTempF).toBe(103);
		});

		it('has minTempF as 80', () => {
			expect(TARGET_TEMP_DEFAULTS.minTempF).toBe(80);
		});

		it('has maxTempF as 110', () => {
			expect(TARGET_TEMP_DEFAULTS.maxTempF).toBe(110);
		});
	});

	describe('getTargetTempEnabled', () => {
		it('returns default value when not set', () => {
			expect(getTargetTempEnabled()).toBe(TARGET_TEMP_DEFAULTS.enabled);
		});

		it('returns true when stored as "true"', () => {
			localStorage.setItem('hotTubTargetTempEnabled', 'true');
			expect(getTargetTempEnabled()).toBe(true);
		});

		it('returns false when stored as "false"', () => {
			localStorage.setItem('hotTubTargetTempEnabled', 'false');
			expect(getTargetTempEnabled()).toBe(false);
		});
	});

	describe('setTargetTempEnabled', () => {
		it('stores true value', () => {
			setTargetTempEnabled(true);
			expect(localStorage.getItem('hotTubTargetTempEnabled')).toBe('true');
		});

		it('stores false value', () => {
			setTargetTempEnabled(false);
			expect(localStorage.getItem('hotTubTargetTempEnabled')).toBe('false');
		});

		it('persists value that can be retrieved', () => {
			setTargetTempEnabled(true);
			expect(getTargetTempEnabled()).toBe(true);

			setTargetTempEnabled(false);
			expect(getTargetTempEnabled()).toBe(false);
		});
	});

	describe('getTargetTempF', () => {
		it('returns default value when not set', () => {
			expect(getTargetTempF()).toBe(TARGET_TEMP_DEFAULTS.targetTempF);
		});

		it('returns stored value', () => {
			localStorage.setItem('hotTubTargetTempF', '100');
			expect(getTargetTempF()).toBe(100);
		});

		it('returns default if stored value is not a valid number', () => {
			localStorage.setItem('hotTubTargetTempF', 'invalid');
			expect(getTargetTempF()).toBe(TARGET_TEMP_DEFAULTS.targetTempF);
		});
	});

	describe('setTargetTempF', () => {
		it('stores temperature value', () => {
			setTargetTempF(105);
			expect(localStorage.getItem('hotTubTargetTempF')).toBe('105');
		});

		it('clamps value to minimum', () => {
			setTargetTempF(50);
			expect(getTargetTempF()).toBe(TARGET_TEMP_DEFAULTS.minTempF);
		});

		it('clamps value to maximum', () => {
			setTargetTempF(150);
			expect(getTargetTempF()).toBe(TARGET_TEMP_DEFAULTS.maxTempF);
		});

		it('allows values within range', () => {
			setTargetTempF(95);
			expect(getTargetTempF()).toBe(95);
		});

		it('persists value that can be retrieved', () => {
			setTargetTempF(100);
			expect(getTargetTempF()).toBe(100);
		});
	});
});
