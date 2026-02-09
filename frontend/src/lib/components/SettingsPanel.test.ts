import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/svelte';
import SettingsPanel from './SettingsPanel.svelte';
import * as autoHeatOff from '$lib/autoHeatOff';
import * as settings from '$lib/settings';
import { api } from '$lib/api';

// Mock the api module
vi.mock('$lib/api', () => ({
	api: {
		getTargetTempStatus: vi.fn(),
		cancelTargetTemp: vi.fn(),
		updateHeatTargetSettings: vi.fn(),
		getHeatingCharacteristics: vi.fn(),
		getTemperature: vi.fn()
	}
}));

// Mock the heatTargetSettings store
vi.mock('$lib/stores/heatTargetSettings.svelte', () => ({
	getEnabled: vi.fn(() => false),
	getTargetTempF: vi.fn(() => 103),
	getTimezone: vi.fn(() => 'America/Los_Angeles'),
	getMinTempF: vi.fn(() => 80),
	getMaxTempF: vi.fn(() => 110),
	updateSettings: vi.fn(),
	getIsLoading: vi.fn(() => false),
	getError: vi.fn(() => null)
}));

// Import the mocked store for manipulation in tests
import * as heatTargetStore from '$lib/stores/heatTargetSettings.svelte';

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
		// Reset mocks to default values
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
		vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(45);
		vi.mocked(settings.getRefreshTempOnHeaterOff).mockReturnValue(true);
		vi.mocked(heatTargetStore.getEnabled).mockReturnValue(false);
		vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(103);
		vi.mocked(api.getTargetTempStatus).mockResolvedValue({ active: false, target_temp_f: null });
		vi.mocked(api.getHeatingCharacteristics).mockResolvedValue({ results: null });
		vi.mocked(api.getTemperature).mockResolvedValue({
			water_temp_f: null,
			water_temp_c: null,
			ambient_temp_f: null,
			ambient_temp_c: null,
			device_name: 'test',
			timestamp: new Date().toISOString()
		});
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

			const checkbox = screen.getByLabelText(
				/refresh temperature when heater turns off/i
			) as HTMLInputElement;
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

	describe('target temperature settings (admin only)', () => {
		it('does not render target temperature section for non-admin', () => {
			render(SettingsPanel, { props: { isAdmin: false } });
			expect(screen.queryByText(/target temperature/i)).toBeNull();
		});

		it('does not render target temperature section by default', () => {
			render(SettingsPanel);
			expect(screen.queryByText(/target temperature/i)).toBeNull();
		});

		it('renders target temperature section for admin', async () => {
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByText(/target temperature/i)).toBeTruthy();
			});
		});

		it('renders enable target temp checkbox for admin', async () => {
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByLabelText(/enable heat to target/i)).toBeTruthy();
			});
		});

		it('renders target temperature slider for admin', async () => {
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByLabelText(/target temp$/i)).toBeTruthy();
			});
		});

		it('loads target temp enabled state from store', async () => {
			vi.mocked(heatTargetStore.getEnabled).mockReturnValue(true);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				const checkbox = screen.getByLabelText(/enable heat to target/i) as HTMLInputElement;
				expect(checkbox.checked).toBe(true);
			});
		});

		it('loads target temperature from store', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(105);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				const input = screen.getByRole('spinbutton', {
					name: /target temp input/i
				}) as HTMLInputElement;
				expect(input.value).toBe('105');
			});
		});

		it('temperature input has numeric keyboard attributes for mobile', async () => {
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				const input = screen.getByRole('spinbutton', {
					name: /target temp input/i
				}) as HTMLInputElement;
				expect(input.inputMode).toBe('decimal');
			});
		});
	});

	describe('target temperature slider sync', () => {
		it('slider value matches the store value on render', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(103);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				const slider = screen.getByLabelText(/target temp$/i) as HTMLInputElement;
				expect(slider.value).toBe('103');
			});
		});

		it('slider updates when number input changes', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(103);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByLabelText(/target temp$/i)).toBeTruthy();
			});

			const numberInput = screen.getByRole('spinbutton', {
				name: /target temp input/i
			}) as HTMLInputElement;
			// bind:value listens to input events
			await fireEvent.input(numberInput, { target: { value: '102.75' } });

			const slider = screen.getByLabelText(/target temp$/i) as HTMLInputElement;
			expect(slider.value).toBe('102.75');
		});
	});

	describe('temperature increment/decrement buttons', () => {
		it('renders a decrease button before the slider', async () => {
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /decrease temperature/i })).toBeTruthy();
			});
		});

		it('renders an increase button after the slider', async () => {
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /increase temperature/i })).toBeTruthy();
			});
		});

		it('decrease button lowers temperature by 0.25', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(103);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /decrease temperature/i })).toBeTruthy();
			});

			await fireEvent.click(screen.getByRole('button', { name: /decrease temperature/i }));

			const numberInput = screen.getByRole('spinbutton', {
				name: /target temp input/i
			}) as HTMLInputElement;
			expect(numberInput.value).toBe('102.75');
		});

		it('increase button raises temperature by 0.25', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(103);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /increase temperature/i })).toBeTruthy();
			});

			await fireEvent.click(screen.getByRole('button', { name: /increase temperature/i }));

			const numberInput = screen.getByRole('spinbutton', {
				name: /target temp input/i
			}) as HTMLInputElement;
			expect(numberInput.value).toBe('103.25');
		});

		it('decrease button does not go below minimum', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(80);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /decrease temperature/i })).toBeTruthy();
			});

			await fireEvent.click(screen.getByRole('button', { name: /decrease temperature/i }));

			const numberInput = screen.getByRole('spinbutton', {
				name: /target temp input/i
			}) as HTMLInputElement;
			expect(numberInput.value).toBe('80');
		});

		it('increase button does not go above maximum', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(110);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /increase temperature/i })).toBeTruthy();
			});

			await fireEvent.click(screen.getByRole('button', { name: /increase temperature/i }));

			const numberInput = screen.getByRole('spinbutton', {
				name: /target temp input/i
			}) as HTMLInputElement;
			expect(numberInput.value).toBe('110');
		});

		it('clicking increment buttons marks settings as dirty', async () => {
			vi.mocked(heatTargetStore.getTargetTempF).mockReturnValue(103);
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /increase temperature/i })).toBeTruthy();
			});

			await fireEvent.click(screen.getByRole('button', { name: /increase temperature/i }));

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /save settings/i })).toBeTruthy();
			});
		});

		it('does not render increment buttons for non-admin', () => {
			render(SettingsPanel, { props: { isAdmin: false } });
			expect(screen.queryByRole('button', { name: /decrease temperature/i })).toBeNull();
			expect(screen.queryByRole('button', { name: /increase temperature/i })).toBeNull();
		});
	});

	describe('admin heat-target job status section', () => {
		beforeEach(() => {
			vi.mocked(api.getTargetTempStatus).mockReset();
			vi.mocked(api.cancelTargetTemp).mockReset();
		});

		it('does not show admin section when isAdmin is false', () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({ active: false, target_temp_f: null });
			render(SettingsPanel, { props: { isAdmin: false } });

			expect(screen.queryByText(/active heat-target job/i)).toBeNull();
		});

		it('does not show admin section by default (isAdmin not passed)', () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({ active: false, target_temp_f: null });
			render(SettingsPanel);

			expect(screen.queryByText(/active heat-target job/i)).toBeNull();
		});

		it('shows admin section when isAdmin is true', async () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({ active: false, target_temp_f: null });
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByText(/active heat-target job/i)).toBeTruthy();
			});
		});

		it('fetches heat-target status on mount when admin', async () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({ active: false, target_temp_f: null });
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(api.getTargetTempStatus).toHaveBeenCalled();
			});
		});

		it('shows inactive state when no active heat-target', async () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({ active: false, target_temp_f: null });
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByText(/no active heat-to-target job/i)).toBeTruthy();
			});
		});

		it('shows active state with target temperature', async () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({
				active: true,
				target_temp_f: 103.5,
				started_at: '2026-01-25T14:30:00Z'
			});
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByText(/heat-to-target active/i)).toBeTruthy();
				expect(screen.getByText(/target: 103.5°f/i)).toBeTruthy();
			});
		});

		it('shows cancel button when heat-target is active', async () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({
				active: true,
				target_temp_f: 103.5
			});
			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /cancel heat-target job/i })).toBeTruthy();
			});
		});

		it('calls cancelTargetTemp API when cancel button clicked', async () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({
				active: true,
				target_temp_f: 103.5
			});
			vi.mocked(api.cancelTargetTemp).mockResolvedValue({ success: true });

			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /cancel heat-target job/i })).toBeTruthy();
			});

			const cancelButton = screen.getByRole('button', { name: /cancel heat-target job/i });
			await fireEvent.click(cancelButton);

			await waitFor(() => {
				expect(api.cancelTargetTemp).toHaveBeenCalled();
			});
		});

		it('updates UI to show inactive after successful cancel', async () => {
			vi.mocked(api.getTargetTempStatus).mockResolvedValue({
				active: true,
				target_temp_f: 103.5
			});
			vi.mocked(api.cancelTargetTemp).mockResolvedValue({ success: true });

			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByRole('button', { name: /cancel heat-target job/i })).toBeTruthy();
			});

			const cancelButton = screen.getByRole('button', { name: /cancel heat-target job/i });
			await fireEvent.click(cancelButton);

			await waitFor(() => {
				expect(screen.getByText(/no active heat-to-target job/i)).toBeTruthy();
			});
		});

		it('shows error message when API fails', async () => {
			vi.mocked(api.getTargetTempStatus).mockRejectedValue(new Error('Network error'));

			render(SettingsPanel, { props: { isAdmin: true } });

			await waitFor(() => {
				expect(screen.getByText(/network error/i)).toBeTruthy();
			});
		});
	});
});
