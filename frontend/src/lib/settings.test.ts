import { describe, it, expect, beforeEach } from 'vitest';
import {
	getRefreshTempOnHeaterOff,
	setRefreshTempOnHeaterOff,
	getCachedTemperature,
	setCachedTemperature,
	type CachedTemperature,
	SETTINGS_DEFAULTS
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
				device_name: 'Hot Tub',
				timestamp: '2025-12-11T10:00:00Z'
			});

			setCachedTemperature({
				water_temp_f: 102.0,
				water_temp_c: 38.9,
				ambient_temp_f: 72.0,
				ambient_temp_c: 22.2,
				device_name: 'Hot Tub',
				timestamp: '2025-12-11T12:00:00Z'
			});

			const cached = getCachedTemperature();
			expect(cached!.water_temp_f).toBe(102.0);
		});
	});
});
