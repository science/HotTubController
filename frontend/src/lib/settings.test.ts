import { describe, it, expect, beforeEach } from 'vitest';
import {
	getRefreshTempOnHeaterOff,
	setRefreshTempOnHeaterOff,
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
});
