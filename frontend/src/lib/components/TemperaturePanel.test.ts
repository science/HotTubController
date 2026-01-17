import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import TemperaturePanel from './TemperaturePanel.svelte';
import * as api from '$lib/api';

// Mock the api module
vi.mock('$lib/api', () => ({
	api: {
		getTemperature: vi.fn(),
		getAllTemperatures: vi.fn()
	}
}));

const mockEsp32Data: api.TemperatureData = {
	water_temp_f: 99.1,
	water_temp_c: 37.3,
	ambient_temp_f: 65.0,
	ambient_temp_c: 18.3,
	device_name: 'ESP32 Temperature Sensor',
	timestamp: '2025-12-11T10:30:00Z',
	source: 'esp32',
	device_id: 'AA:BB:CC:DD:EE:FF',
	uptime_seconds: 3600
};

const mockAllTempsResponse: api.AllTemperaturesResponse = {
	esp32: mockEsp32Data
};

const mockAllTempsEmpty: api.AllTemperaturesResponse = {
	esp32: null
};

describe('TemperaturePanel', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.api.getAllTemperatures).mockResolvedValue(mockAllTempsResponse);
	});

	describe('rendering', () => {
		it('renders the section header', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByText('Temperature')).toBeTruthy();
			});
		});

		it('displays water temperature when data is loaded', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByText(/99\.1/)).toBeTruthy();
			});
		});

		it('displays ambient temperature when data is loaded', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByText(/65/)).toBeTruthy();
			});
		});

		it('displays temperature labels', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByText(/Water/)).toBeTruthy();
				expect(screen.getByText(/Ambient/)).toBeTruthy();
			});
		});

		it('shows ESP32 indicator', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByText('ESP32')).toBeTruthy();
			});
		});
	});

	describe('refresh button', () => {
		it('renders refresh button', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByRole('button', { name: /refresh/i })).toBeTruthy();
			});
		});

		it('calls getAllTemperatures API when refresh clicked', async () => {
			render(TemperaturePanel);

			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(1);
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(2);
			});
		});
	});

	describe('loading state', () => {
		it('shows loading indicator while fetching', async () => {
			let resolvePromise: (value: api.AllTemperaturesResponse) => void;
			const promise = new Promise<api.AllTemperaturesResponse>((resolve) => {
				resolvePromise = resolve;
			});
			vi.mocked(api.api.getAllTemperatures).mockReturnValue(promise);

			render(TemperaturePanel);

			// Should show loading state
			expect(screen.getByText(/fetching/i)).toBeTruthy();

			// Resolve the promise
			resolvePromise!(mockAllTempsResponse);

			await waitFor(() => {
				expect(screen.getByText(/99\.1/)).toBeTruthy();
			});
		});
	});

	describe('error state', () => {
		it('shows error message when API fails', async () => {
			vi.mocked(api.api.getAllTemperatures).mockRejectedValue(new Error('Network error'));

			render(TemperaturePanel);

			await waitFor(() => {
				// Should display the actual error message
				expect(screen.getByText(/Network error/i)).toBeTruthy();
			});
		});
	});

	describe('no data state', () => {
		it('shows message when no ESP32 data is available', async () => {
			vi.mocked(api.api.getAllTemperatures).mockResolvedValue(mockAllTempsEmpty);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/No temperature data available/i)).toBeTruthy();
			});
		});
	});

	describe('exported loadTemperature function', () => {
		it('exposes loadTemperature for external refresh triggering', async () => {
			const { component } = render(TemperaturePanel);

			// Wait for initial load
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(1);
			});

			vi.mocked(api.api.getAllTemperatures).mockClear();

			// Call the exported function - cast to access exports
			const panel = component as unknown as { loadTemperature: () => Promise<void> };
			await panel.loadTemperature();

			// Should have called the API again
			expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(1);
		});
	});

	describe('inline layout', () => {
		it('renders temperatures in a flex container with wrap', async () => {
			const { container } = render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/99\.1/)).toBeTruthy();
			});

			// Find the esp32 readings container
			const tempContainer = container.querySelector('[data-testid="esp32-readings"]');
			expect(tempContainer).toBeTruthy();
			expect(tempContainer?.className).toContain('flex');
			expect(tempContainer?.className).toContain('flex-wrap');
		});

		it('renders water and ambient as separate flex items', async () => {
			const { container } = render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/99\.1/)).toBeTruthy();
			});

			// In the new structure, temperature readings are in the esp32 container
			const tempContainer = container.querySelector('[data-testid="esp32-readings"]');
			expect(tempContainer).toBeTruthy();

			// Each temperature reading should be in a whitespace-nowrap div
			const readings = tempContainer?.querySelectorAll('.whitespace-nowrap');
			expect(readings?.length).toBeGreaterThanOrEqual(2); // Water and ambient
		});
	});

	describe('last reading time display', () => {
		it('displays ESP32 timestamp from API response', async () => {
			render(TemperaturePanel);

			await waitFor(() => {
				// Should display the timestamp from the API response
				expect(screen.getByTestId('esp32-timestamp')).toBeTruthy();
			});

			// Verify it shows "Last reading:" label with the API timestamp
			const timestampElement = screen.getByTestId('esp32-timestamp');
			expect(timestampElement.textContent).toContain('Last reading:');
		});

		it('shows timestamp from API response not current time', async () => {
			render(TemperaturePanel);

			// Wait for temperature to load
			await waitFor(() => {
				expect(screen.getByTestId('esp32-timestamp')).toBeTruthy();
			});

			// The timestamp should contain the date from the mock (Dec 11)
			const timestampElement = screen.getByTestId('esp32-timestamp');
			expect(timestampElement.textContent).toContain('Dec');
		});

		it('groups timestamp and refresh button together', async () => {
			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByTestId('esp32-timestamp')).toBeTruthy();
			});

			// The time and button should share a common parent
			const timeElement = screen.getByTestId('esp32-timestamp');
			const refreshButton = screen.getByTestId('esp32-refresh');

			// Both should be in the same parent container
			expect(timeElement.parentElement).toBe(refreshButton.parentElement);
		});
	});

	describe('data fetching', () => {
		it('calls API on mount', async () => {
			render(TemperaturePanel);

			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(1);
			});

			// Should display API temperature
			await waitFor(() => {
				expect(screen.getByText(/99\.1/)).toBeTruthy();
			});
		});

		it('calls API when refresh button clicked', async () => {
			render(TemperaturePanel);

			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(1);
			});

			// Click refresh button
			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// API should be called again
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(2);
			});
		});

		it('calls API when loadTemperature is called externally', async () => {
			const { component } = render(TemperaturePanel);

			// Wait for initial load
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(1);
			});

			// Call exported loadTemperature
			const panel = component as unknown as { loadTemperature: () => Promise<void> };
			await panel.loadTemperature();

			// Should have called API again
			expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(2);
		});
	});
});
