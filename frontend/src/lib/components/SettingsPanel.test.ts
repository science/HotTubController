import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/svelte';
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
	getTempSourceSettings: vi.fn(() => ({ esp32Enabled: true, wirelessTagEnabled: true })),
	setEsp32Enabled: vi.fn(),
	setWirelessTagEnabled: vi.fn(),
	getTargetTempEnabled: vi.fn(() => false),
	setTargetTempEnabled: vi.fn(),
	getTargetTempF: vi.fn(() => 103),
	setTargetTempF: vi.fn(),
	SETTINGS_DEFAULTS: {
		refreshTempOnHeaterOff: true,
		esp32Enabled: true,
		wirelessTagEnabled: true
	},
	TARGET_TEMP_DEFAULTS: {
		enabled: false,
		targetTempF: 103,
		minTempF: 80,
		maxTempF: 110
	}
}));

describe('SettingsPanel', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		// Reset mocks to default values
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
		vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(45);
		vi.mocked(settings.getRefreshTempOnHeaterOff).mockReturnValue(true);
		vi.mocked(settings.getTempSourceSettings).mockReturnValue({ esp32Enabled: true, wirelessTagEnabled: true });
		vi.mocked(settings.getTargetTempEnabled).mockReturnValue(false);
		vi.mocked(settings.getTargetTempF).mockReturnValue(103);
	});

	afterEach(() => {
		cleanup();
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

	describe('target temperature settings', () => {
		it('renders target temperature section', () => {
			render(SettingsPanel);
			expect(screen.getByText(/target temperature/i)).toBeTruthy();
		});

		it('renders enable target temp checkbox', () => {
			render(SettingsPanel);
			expect(screen.getByLabelText(/enable heat to target/i)).toBeTruthy();
		});

		it('renders target temperature slider', () => {
			render(SettingsPanel);
			expect(screen.getByLabelText(/target temp/i)).toBeTruthy();
		});

		it('loads target temp enabled state from storage', () => {
			vi.mocked(settings.getTargetTempEnabled).mockReturnValue(true);
			render(SettingsPanel);

			const checkbox = screen.getByLabelText(/enable heat to target/i) as HTMLInputElement;
			expect(checkbox.checked).toBe(true);
		});

		it('saves target temp enabled state when toggled', async () => {
			vi.mocked(settings.getTargetTempEnabled).mockReturnValue(false);
			render(SettingsPanel);

			const checkbox = screen.getByLabelText(/enable heat to target/i);
			await fireEvent.click(checkbox);

			expect(settings.setTargetTempEnabled).toHaveBeenCalledWith(true);
		});

		it('loads target temperature from storage', () => {
			vi.mocked(settings.getTargetTempF).mockReturnValue(105);
			render(SettingsPanel);

			// Check the displayed value instead of the slider (JSDOM range inputs can be quirky)
			expect(screen.getByText(/105°F/)).toBeTruthy();
		});

		it('saves target temperature when slider changes', async () => {
			render(SettingsPanel);

			const slider = screen.getByLabelText(/target temp/i);
			await fireEvent.input(slider, { target: { value: '100' } });

			expect(settings.setTargetTempF).toHaveBeenCalledWith(100);
		});

		it('displays current target temperature value', () => {
			vi.mocked(settings.getTargetTempF).mockReturnValue(103);
			render(SettingsPanel);

			expect(screen.getByText(/103°F/)).toBeTruthy();
		});
	});
});
