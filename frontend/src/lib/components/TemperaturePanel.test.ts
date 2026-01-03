import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import TemperaturePanel from './TemperaturePanel.svelte';
import * as api from '$lib/api';
import * as settings from '$lib/settings';

// Mock the api module
vi.mock('$lib/api', () => ({
	api: {
		getTemperature: vi.fn(),
		getAllTemperatures: vi.fn(),
		refreshTemperature: vi.fn()
	}
}));

// Mock the settings module
vi.mock('$lib/settings', () => ({
	getCachedTemperature: vi.fn(),
	setCachedTemperature: vi.fn(),
	getTempSourceSettings: vi.fn()
}));

const mockTemperatureData: api.TemperatureData = {
	water_temp_f: 98.4,
	water_temp_c: 36.9,
	ambient_temp_f: 62.0,
	ambient_temp_c: 16.7,
	battery_voltage: 3.54,
	signal_dbm: -67,
	device_name: 'Hot Tub',
	timestamp: '2025-12-11T10:30:00Z',
	refresh_in_progress: false
};

const mockRefreshResponse: api.RefreshResponse = {
	success: true,
	message: 'Temperature refresh requested',
	requested_at: '2025-12-11T10:30:00Z'
};

const mockTemperatureRefreshing: api.TemperatureData = {
	...mockTemperatureData,
	refresh_in_progress: true,
	refresh_requested_at: '2025-12-11T10:30:00Z'
};

const mockCachedData: settings.CachedTemperature = {
	water_temp_f: 95.0,
	water_temp_c: 35.0,
	ambient_temp_f: 58.0,
	ambient_temp_c: 14.4,
	battery_voltage: 3.5,
	signal_dbm: -60,
	device_name: 'Hot Tub',
	timestamp: '2025-12-11T08:00:00Z',
	refresh_in_progress: false,
	cachedAt: Date.now() - 60000 // 1 minute ago
};

// Mock response for getAllTemperatures (dual source)
const mockAllTempsResponse: api.AllTemperaturesResponse = {
	esp32: null,
	wirelesstag: {
		water_temp_f: 98.4,
		water_temp_c: 36.9,
		ambient_temp_f: 62.0,
		ambient_temp_c: 16.7,
		battery_voltage: 3.54,
		signal_dbm: -67,
		device_name: 'Hot Tub',
		timestamp: '2025-12-11T10:30:00Z',
		refresh_in_progress: false,
		source: 'wirelesstag'
	}
};

const mockAllTempsWithEsp32: api.AllTemperaturesResponse = {
	esp32: {
		water_temp_f: 99.1,
		water_temp_c: 37.3,
		ambient_temp_f: 65.0,
		ambient_temp_c: 18.3,
		device_name: 'ESP32 Temperature Sensor',
		timestamp: '2025-12-11T10:30:00Z',
		source: 'esp32'
	},
	wirelesstag: {
		water_temp_f: 98.4,
		water_temp_c: 36.9,
		ambient_temp_f: 62.0,
		ambient_temp_c: 16.7,
		battery_voltage: 3.54,
		signal_dbm: -67,
		device_name: 'Hot Tub',
		timestamp: '2025-12-11T10:30:00Z',
		refresh_in_progress: false,
		source: 'wirelesstag'
	}
};

const mockTempSourceSettings: settings.TempSourceSettings = {
	esp32Enabled: true,
	wirelessTagEnabled: true
};

// Mock response with WirelessTag refresh in progress
const mockAllTempsRefreshing: api.AllTemperaturesResponse = {
	esp32: null,
	wirelesstag: {
		water_temp_f: 98.4,
		water_temp_c: 36.9,
		ambient_temp_f: 62.0,
		ambient_temp_c: 16.7,
		battery_voltage: 3.54,
		signal_dbm: -67,
		device_name: 'Hot Tub',
		timestamp: '2025-12-11T10:30:00Z',
		refresh_in_progress: true,
		refresh_requested_at: '2025-12-11T10:30:00Z',
		source: 'wirelesstag'
	}
};

describe('TemperaturePanel', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.api.getAllTemperatures).mockResolvedValue(mockAllTempsResponse);
		vi.mocked(settings.getTempSourceSettings).mockReturnValue(mockTempSourceSettings);
		vi.mocked(settings.getCachedTemperature).mockReturnValue(null);
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
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});
		});

		it('displays ambient temperature when data is loaded', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByText(/62/)).toBeTruthy();
			});
		});

		it('displays temperature labels', async () => {
			render(TemperaturePanel);
			await waitFor(() => {
				expect(screen.getByText(/Water/)).toBeTruthy();
				expect(screen.getByText(/Ambient/)).toBeTruthy();
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

		it('calls getTemperature API when refresh clicked', async () => {
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
				expect(screen.getByText(/98\.4/)).toBeTruthy();
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

		it('keeps showing data but adds error message after refresh fails', async () => {
			// First load succeeds
			vi.mocked(api.api.getAllTemperatures).mockResolvedValueOnce(mockAllTempsResponse);
			// Refresh request fails
			vi.mocked(api.api.refreshTemperature).mockRejectedValueOnce(new Error('Refresh failed'));

			render(TemperaturePanel);

			// Wait for initial load to complete
			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			// Click refresh button
			const button = screen.getByTestId('wirelesstag-refresh');
			await fireEvent.click(button);

			// Should show error message but keep displaying temperature data
			await waitFor(() => {
				expect(screen.getByText(/Failed to refresh/i)).toBeTruthy();
			});
			// Temperature data should still be visible
			expect(screen.getByText(/98\.4/)).toBeTruthy();
		});

		it('shows configuration error message from backend', async () => {
			vi.mocked(api.api.getAllTemperatures).mockRejectedValue(
				new Error('Temperature sensor not configured: WIRELESSTAG_OAUTH_TOKEN is missing')
			);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/sensor not configured/i)).toBeTruthy();
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
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			// Find the wirelesstag readings container (new structure shows sources separately)
			const tempContainer = container.querySelector('[data-testid="wirelesstag-readings"]');
			expect(tempContainer).toBeTruthy();
			expect(tempContainer?.className).toContain('flex');
			expect(tempContainer?.className).toContain('flex-wrap');
		});

		it('renders water and ambient as separate flex items', async () => {
			const { container } = render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			// In the new structure, temperature readings are in the wirelesstag container
			const tempContainer = container.querySelector('[data-testid="wirelesstag-readings"]');
			expect(tempContainer).toBeTruthy();

			// Each temperature reading should be in a whitespace-nowrap div
			const readings = tempContainer?.querySelectorAll('.whitespace-nowrap');
			expect(readings?.length).toBeGreaterThanOrEqual(2); // Water and ambient
		});
	});

	describe('last reading time display', () => {
		it('displays WirelessTag timestamp from API response', async () => {
			render(TemperaturePanel);

			await waitFor(() => {
				// Should display the timestamp from the API response
				expect(screen.getByTestId('wirelesstag-timestamp')).toBeTruthy();
			});

			// Verify it shows "Last reading:" label with the API timestamp
			const timestampElement = screen.getByTestId('wirelesstag-timestamp');
			expect(timestampElement.textContent).toContain('Last reading:');
		});

		it('updates timestamp when API returns new data', async () => {
			render(TemperaturePanel);

			// Wait for initial display
			await waitFor(() => {
				expect(screen.getByTestId('wirelesstag-timestamp')).toBeTruthy();
			});

			// Click refresh button
			const button = screen.getByTestId('wirelesstag-refresh');
			await fireEvent.click(button);

			// Wait for API call to complete
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(2);
			});

			// Timestamp element should still exist after refresh
			expect(screen.getByTestId('wirelesstag-timestamp')).toBeTruthy();
		});

		it('shows timestamp from API response not current time', async () => {
			render(TemperaturePanel);

			// Wait for temperature to load
			await waitFor(() => {
				expect(screen.getByTestId('wirelesstag-timestamp')).toBeTruthy();
			});

			// The timestamp should contain the date from the mock (Dec 11)
			const timestampElement = screen.getByTestId('wirelesstag-timestamp');
			expect(timestampElement.textContent).toContain('Dec');
		});

		it('groups timestamp and refresh button together', async () => {
			const { container } = render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByTestId('wirelesstag-timestamp')).toBeTruthy();
			});

			// The time and button should share a common parent
			const timeElement = screen.getByTestId('wirelesstag-timestamp');
			const refreshButton = screen.getByTestId('wirelesstag-refresh');

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
				expect(screen.getByText(/98\.4/)).toBeTruthy();
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

	describe('hardware refresh polling', () => {
		beforeEach(() => {
			vi.useFakeTimers();
			vi.mocked(api.api.refreshTemperature).mockResolvedValue(mockRefreshResponse);
		});

		afterEach(() => {
			vi.useRealTimers();
		});

		it('calls refreshTemperature API when refresh button is clicked', async () => {
			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(api.api.refreshTemperature).toHaveBeenCalledTimes(1);
			});
		});

		it('polls getAllTemperatures after requesting refresh', async () => {
			// First call on mount, then: first poll: still refreshing, second poll: complete
			vi.mocked(api.api.getAllTemperatures)
				.mockResolvedValueOnce(mockAllTempsResponse)
				.mockResolvedValueOnce(mockAllTempsRefreshing)
				.mockResolvedValueOnce(mockAllTempsResponse);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// First poll after refresh request (call 2 total)
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(2);
			});

			// Advance timer by 3 seconds for second poll
			await vi.advanceTimersByTimeAsync(3000);

			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(3);
			});
		});

		it('shows refreshing state while polling', async () => {
			vi.mocked(api.api.getAllTemperatures)
				.mockResolvedValueOnce(mockAllTempsResponse)
				.mockResolvedValue(mockAllTempsRefreshing);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(screen.getByText(/refreshing sensor/i)).toBeTruthy();
			});
		});

		it('stops polling when refresh_in_progress becomes false', async () => {
			// Mount, first poll: refreshing, second poll: complete
			vi.mocked(api.api.getAllTemperatures)
				.mockResolvedValueOnce(mockAllTempsResponse)
				.mockResolvedValueOnce(mockAllTempsRefreshing)
				.mockResolvedValueOnce(mockAllTempsResponse);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// Wait for first poll (call 2)
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(2);
			});

			// Advance timer
			await vi.advanceTimersByTimeAsync(3000);

			// Second poll shows complete (call 3)
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(3);
			});

			// Should not show refreshing anymore
			expect(screen.queryByText(/refreshing sensor/i)).toBeNull();

			// Advance timer more - should NOT poll again
			await vi.advanceTimersByTimeAsync(3000);
			expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(3);
		});

		it('stops polling after max attempts (5 polls = 15 seconds)', async () => {
			// Mount returns normal, then always return refreshing state
			vi.mocked(api.api.getAllTemperatures)
				.mockResolvedValueOnce(mockAllTempsResponse)
				.mockResolvedValue(mockAllTempsRefreshing);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// Wait for initial poll (call 2)
			await waitFor(() => {
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(2);
			});

			// Advance through 4 more poll cycles (5 polls total after mount)
			for (let i = 0; i < 4; i++) {
				await vi.advanceTimersByTimeAsync(3000);
			}

			await waitFor(() => {
				// 1 mount + 5 polls = 6 total calls
				expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(6);
			});

			// Advance again - should NOT poll (max attempts reached)
			await vi.advanceTimersByTimeAsync(3000);
			expect(api.api.getAllTemperatures).toHaveBeenCalledTimes(6);
		});

		it('handles refreshTemperature API failure gracefully', async () => {
			vi.mocked(api.api.refreshTemperature).mockRejectedValue(new Error('Network error'));

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// Should show error state
			await waitFor(() => {
				expect(screen.getByText(/failed to refresh/i)).toBeTruthy();
			});
		});
	});
});
