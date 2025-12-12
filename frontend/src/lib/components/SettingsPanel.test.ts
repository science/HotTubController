import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import SettingsPanel from './SettingsPanel.svelte';
import * as autoHeatOff from '$lib/autoHeatOff';
import * as settings from '$lib/settings';

// Mock the modules
vi.mock('$lib/autoHeatOff', () => ({
	getAutoHeatOffEnabled: vi.fn(() => true),
	setAutoHeatOffEnabled: vi.fn(),
	getAutoHeatOffMinutes: vi.fn(() => 45),
	setAutoHeatOffMinutes: vi.fn(),
	AUTO_HEAT_OFF_DEFAULTS: {
		enabled: true,
		minutes: 45,
		minMinutes: 30,
		maxMinutes: 480
	}
}));

vi.mock('$lib/settings', () => ({
	getRefreshTempOnHeaterOff: vi.fn(() => true),
	setRefreshTempOnHeaterOff: vi.fn(),
	SETTINGS_DEFAULTS: {
		refreshTempOnHeaterOff: true
	}
}));

describe('SettingsPanel', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	describe('rendering', () => {
		it('renders the Settings header', () => {
			render(SettingsPanel);
			expect(screen.getByRole('heading', { name: 'Settings' })).toBeTruthy();
		});

		it('renders auto heat-off checkbox', () => {
			render(SettingsPanel);
			expect(screen.getByLabelText(/enable auto heat-off/i)).toBeTruthy();
		});

		it('renders auto heat-off minutes input', () => {
			render(SettingsPanel);
			expect(screen.getByLabelText(/turn off after/i)).toBeTruthy();
		});

		it('renders refresh temp on heater-off checkbox', () => {
			render(SettingsPanel);
			expect(screen.getByLabelText(/refresh temperature when heater turns off/i)).toBeTruthy();
		});
	});

	describe('auto heat-off settings', () => {
		it('loads auto heat-off enabled state from storage', () => {
			vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(false);
			render(SettingsPanel);

			const checkbox = screen.getByLabelText(/enable auto heat-off/i) as HTMLInputElement;
			expect(checkbox.checked).toBe(false);
		});

		it('saves auto heat-off enabled state when toggled', async () => {
			// Start with disabled state
			vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(false);
			render(SettingsPanel);

			const checkbox = screen.getByLabelText(/enable auto heat-off/i);
			await fireEvent.click(checkbox);

			// Clicking unchecked checkbox enables it
			expect(autoHeatOff.setAutoHeatOffEnabled).toHaveBeenCalledWith(true);
		});

		it('loads auto heat-off minutes from storage', () => {
			vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(60);
			render(SettingsPanel);

			const input = screen.getByLabelText(/turn off after/i) as HTMLInputElement;
			expect(input.value).toBe('60');
		});

		it('saves auto heat-off minutes when changed', async () => {
			render(SettingsPanel);

			const input = screen.getByLabelText(/turn off after/i);
			await fireEvent.change(input, { target: { value: '90' } });
			await fireEvent.blur(input);

			expect(autoHeatOff.setAutoHeatOffMinutes).toHaveBeenCalledWith(90);
		});
	});

	describe('refresh temp on heater-off setting', () => {
		it('loads refresh temp setting from storage', () => {
			vi.mocked(settings.getRefreshTempOnHeaterOff).mockReturnValue(false);
			render(SettingsPanel);

			const checkbox = screen.getByLabelText(/refresh temperature when heater turns off/i) as HTMLInputElement;
			expect(checkbox.checked).toBe(false);
		});

		it('saves refresh temp setting when toggled', async () => {
			// Start with disabled state
			vi.mocked(settings.getRefreshTempOnHeaterOff).mockReturnValue(false);
			render(SettingsPanel);

			const checkbox = screen.getByLabelText(/refresh temperature when heater turns off/i);
			await fireEvent.click(checkbox);

			// Clicking unchecked checkbox enables it
			expect(settings.setRefreshTempOnHeaterOff).toHaveBeenCalledWith(true);
		});
	});
});
