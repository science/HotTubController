import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
	getAutoHeatOffEnabled,
	setAutoHeatOffEnabled,
	getAutoHeatOffMinutes,
	setAutoHeatOffMinutes,
	calculateHeatOffTime,
	AUTO_HEAT_OFF_DEFAULTS
} from './autoHeatOff';

describe('autoHeatOff', () => {
	describe('localStorage persistence', () => {
		beforeEach(() => {
			localStorage.clear();
		});

		describe('getAutoHeatOffEnabled', () => {
			it('returns default value when not set', () => {
				expect(getAutoHeatOffEnabled()).toBe(AUTO_HEAT_OFF_DEFAULTS.enabled);
				expect(getAutoHeatOffEnabled()).toBe(true); // enabled by default
			});

			it('returns true when localStorage is "true"', () => {
				localStorage.setItem('hotTubAutoHeatOff', 'true');
				expect(getAutoHeatOffEnabled()).toBe(true);
			});

			it('returns false when localStorage is "false"', () => {
				localStorage.setItem('hotTubAutoHeatOff', 'false');
				expect(getAutoHeatOffEnabled()).toBe(false);
			});
		});

		describe('setAutoHeatOffEnabled', () => {
			it('writes "true" to localStorage when passed true', () => {
				setAutoHeatOffEnabled(true);
				expect(localStorage.getItem('hotTubAutoHeatOff')).toBe('true');
			});

			it('writes "false" to localStorage when passed false', () => {
				setAutoHeatOffEnabled(false);
				expect(localStorage.getItem('hotTubAutoHeatOff')).toBe('false');
			});
		});

		describe('getAutoHeatOffMinutes', () => {
			it('returns default value when not set', () => {
				expect(getAutoHeatOffMinutes()).toBe(AUTO_HEAT_OFF_DEFAULTS.minutes);
				expect(getAutoHeatOffMinutes()).toBe(45); // 45 minutes default
			});

			it('returns stored value when set', () => {
				localStorage.setItem('hotTubAutoHeatOffMinutes', '180');
				expect(getAutoHeatOffMinutes()).toBe(180);
			});

			it('returns default for invalid non-numeric value', () => {
				localStorage.setItem('hotTubAutoHeatOffMinutes', 'invalid');
				expect(getAutoHeatOffMinutes()).toBe(AUTO_HEAT_OFF_DEFAULTS.minutes);
			});
		});

		describe('setAutoHeatOffMinutes', () => {
			it('writes value to localStorage', () => {
				setAutoHeatOffMinutes(180);
				expect(localStorage.getItem('hotTubAutoHeatOffMinutes')).toBe('180');
			});

			it('clamps below minimum (30)', () => {
				setAutoHeatOffMinutes(10);
				expect(localStorage.getItem('hotTubAutoHeatOffMinutes')).toBe('30');
			});

			it('clamps above maximum (480)', () => {
				setAutoHeatOffMinutes(600);
				expect(localStorage.getItem('hotTubAutoHeatOffMinutes')).toBe('480');
			});
		});
	});

	describe('calculateHeatOffTime', () => {
		beforeEach(() => {
			vi.useFakeTimers();
			vi.setSystemTime(new Date(2025, 11, 11, 10, 0, 0));
		});

		afterEach(() => {
			vi.useRealTimers();
		});

		it('adds minutes to heater-on time', () => {
			const heaterOnTime = '2024-12-11T06:00:00-05:00';
			const result = calculateHeatOffTime(heaterOnTime, 150);

			// 06:00 + 150 min = 08:30
			expect(result).toBe('2024-12-11T08:30:00-05:00');
		});

		it('handles midnight crossing', () => {
			const heaterOnTime = '2024-12-11T23:00:00-05:00';
			const result = calculateHeatOffTime(heaterOnTime, 180);

			// 23:00 + 180 min = 02:00 next day
			expect(result).toBe('2024-12-12T02:00:00-05:00');
		});

		it('preserves timezone offset', () => {
			const heaterOnTime = '2024-12-11T06:00:00+05:30';
			const result = calculateHeatOffTime(heaterOnTime, 150);

			expect(result).toBe('2024-12-11T08:30:00+05:30');
		});

		it('handles 30 minutes (minimum)', () => {
			const heaterOnTime = '2024-12-11T06:00:00-05:00';
			const result = calculateHeatOffTime(heaterOnTime, 30);

			expect(result).toBe('2024-12-11T06:30:00-05:00');
		});

		it('handles 480 minutes (maximum, 8 hours)', () => {
			const heaterOnTime = '2024-12-11T06:00:00-05:00';
			const result = calculateHeatOffTime(heaterOnTime, 480);

			// 06:00 + 480 min = 14:00
			expect(result).toBe('2024-12-11T14:00:00-05:00');
		});
	});
});
