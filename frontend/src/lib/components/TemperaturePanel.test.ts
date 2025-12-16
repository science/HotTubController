import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import TemperaturePanel from './TemperaturePanel.svelte';
import * as api from '$lib/api';
import * as settings from '$lib/settings';

// Mock the api module
vi.mock('$lib/api', () => ({
	api: {
		getTemperature: vi.fn(),
		refreshTemperature: vi.fn()
	}
}));

// Mock the settings module
vi.mock('$lib/settings', () => ({
	getCachedTemperature: vi.fn(),
	setCachedTemperature: vi.fn()
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

describe('TemperaturePanel', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.api.getTemperature).mockResolvedValue(mockTemperatureData);
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
				expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(2);
			});
		});
	});

	describe('loading state', () => {
		it('shows loading indicator while fetching', async () => {
			let resolvePromise: (value: api.TemperatureData) => void;
			const promise = new Promise<api.TemperatureData>((resolve) => {
				resolvePromise = resolve;
			});
			vi.mocked(api.api.getTemperature).mockReturnValue(promise);

			render(TemperaturePanel);

			// Should show loading state
			expect(screen.getByText(/fetching/i)).toBeTruthy();

			// Resolve the promise
			resolvePromise!(mockTemperatureData);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});
		});
	});

	describe('error state', () => {
		it('shows error message when API fails', async () => {
			vi.mocked(api.api.getTemperature).mockRejectedValue(new Error('Network error'));

			render(TemperaturePanel);

			await waitFor(() => {
				// Should display the actual error message
				expect(screen.getByText(/Network error/i)).toBeTruthy();
			});
		});

		it('keeps showing error after failed refresh', async () => {
			vi.mocked(api.api.getTemperature).mockRejectedValue(new Error('Connection failed'));

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/Connection failed/i)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(screen.getByText(/Connection failed/i)).toBeTruthy();
			});
		});

		it('shows configuration error message from backend', async () => {
			vi.mocked(api.api.getTemperature).mockRejectedValue(
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
				expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			});

			vi.mocked(api.api.getTemperature).mockClear();

			// Call the exported function - cast to access exports
			const panel = component as unknown as { loadTemperature: () => Promise<void> };
			await panel.loadTemperature();

			// Should have called the API again
			expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
		});
	});

	describe('inline layout', () => {
		it('renders temperatures in a flex container with wrap', async () => {
			const { container } = render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			// Find the temperature readings container by data-testid
			const tempContainer = container.querySelector('[data-testid="temperature-readings"]');
			expect(tempContainer).toBeTruthy();
			expect(tempContainer?.className).toContain('flex');
			expect(tempContainer?.className).toContain('flex-wrap');
		});

		it('renders water and ambient as separate flex items', async () => {
			const { container } = render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			// Each temperature reading should be a flex item that won't break internally
			const waterReading = container.querySelector('[data-testid="water-temp"]');
			const ambientReading = container.querySelector('[data-testid="ambient-temp"]');

			expect(waterReading).toBeTruthy();
			expect(ambientReading).toBeTruthy();

			// Both should have whitespace-nowrap to prevent internal breaking
			expect(waterReading?.className).toContain('whitespace-nowrap');
			expect(ambientReading?.className).toContain('whitespace-nowrap');
		});
	});

	describe('last refreshed time display', () => {
		it('displays last refreshed time when cached data exists', async () => {
			const fixedTime = new Date('2025-12-11T10:30:00').getTime();
			vi.mocked(settings.getCachedTemperature).mockReturnValue({
				...mockCachedData,
				cachedAt: fixedTime
			});

			render(TemperaturePanel);

			await waitFor(() => {
				// Should display some form of the time
				expect(screen.getByTestId('last-refreshed')).toBeTruthy();
			});
		});

		it('updates last refreshed time when refresh button is clicked', async () => {
			const initialTime = new Date('2025-12-11T08:00:00').getTime();
			const newTime = new Date('2025-12-11T10:30:00').getTime();

			// Start with cached data
			vi.mocked(settings.getCachedTemperature).mockReturnValue({
				...mockCachedData,
				cachedAt: initialTime
			});

			render(TemperaturePanel);

			// Wait for initial display
			await waitFor(() => {
				expect(screen.getByTestId('last-refreshed')).toBeTruthy();
			});

			const initialText = screen.getByTestId('last-refreshed').textContent;

			// Mock the cache to return new time after API call
			vi.mocked(settings.getCachedTemperature).mockReturnValue({
				...mockTemperatureData,
				cachedAt: newTime
			});

			// Click refresh
			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// Wait for API call and update
			await waitFor(() => {
				const newText = screen.getByTestId('last-refreshed').textContent;
				expect(newText).not.toBe(initialText);
			});
		});

		it('displays last refreshed time after fresh API call (no cache)', async () => {
			const newTime = new Date('2025-12-11T10:30:00').getTime();

			// No cache initially
			vi.mocked(settings.getCachedTemperature).mockReturnValue(null);

			render(TemperaturePanel);

			// After API call completes, mock cache to return data
			vi.mocked(settings.getCachedTemperature).mockReturnValue({
				...mockTemperatureData,
				cachedAt: newTime
			});

			// Wait for temperature to load and last-refreshed to appear
			await waitFor(() => {
				expect(screen.getByTestId('last-refreshed')).toBeTruthy();
			});
		});

		it('groups last refreshed time and refresh button together for responsive wrapping', async () => {
			const fixedTime = new Date('2025-12-11T10:30:00').getTime();
			vi.mocked(settings.getCachedTemperature).mockReturnValue({
				...mockCachedData,
				cachedAt: fixedTime
			});

			const { container } = render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByTestId('last-refreshed')).toBeTruthy();
			});

			// The time and button should share a common parent that won't break internally
			const timeElement = screen.getByTestId('last-refreshed');
			const refreshButton = screen.getByRole('button', { name: /refresh/i });

			// Both should be in the same parent container
			expect(timeElement.parentElement).toBe(refreshButton.parentElement);

			// The parent should use shrink-0 to prevent breaking
			expect(timeElement.parentElement?.className).toContain('shrink-0');
		});
	});

	describe('cache behavior', () => {
		it('displays cached data on mount without calling API', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);

			render(TemperaturePanel);

			// Should display cached temperature (95.0) not API temperature (98.4)
			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			// Should NOT have called the API
			expect(api.api.getTemperature).not.toHaveBeenCalled();
		});

		it('calls API on mount when no cache exists', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(null);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			});

			// Should display API temperature
			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});
		});

		it('updates cache when API data is fetched', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(null);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(settings.setCachedTemperature).toHaveBeenCalledWith(mockTemperatureData);
			});
		});

		it('always calls API when refresh button clicked, even with cached data', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);

			render(TemperaturePanel);

			// Wait for cached data to display
			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			// API should NOT have been called yet
			expect(api.api.getTemperature).not.toHaveBeenCalled();

			// Click refresh button
			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// Now API should be called
			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			});

			// Should display fresh API temperature
			await waitFor(() => {
				expect(screen.getByText(/98\.4/)).toBeTruthy();
			});

			// Cache should be updated with new data
			expect(settings.setCachedTemperature).toHaveBeenCalledWith(mockTemperatureData);
		});

		it('updates cache when loadTemperature is called externally', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);

			const { component } = render(TemperaturePanel);

			// Wait for cached data
			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			// Call exported loadTemperature
			const panel = component as unknown as { loadTemperature: () => Promise<void> };
			await panel.loadTemperature();

			// Should have called API and updated cache
			expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			expect(settings.setCachedTemperature).toHaveBeenCalledWith(mockTemperatureData);
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
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);
			vi.mocked(api.api.getTemperature).mockResolvedValue(mockTemperatureData);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(api.api.refreshTemperature).toHaveBeenCalledTimes(1);
			});
		});

		it('polls getTemperature after requesting refresh', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);
			// First poll: still refreshing, second poll: complete
			vi.mocked(api.api.getTemperature)
				.mockResolvedValueOnce(mockTemperatureRefreshing)
				.mockResolvedValueOnce(mockTemperatureData);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// First poll after refresh request
			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			});

			// Advance timer by 3 seconds for second poll
			await vi.advanceTimersByTimeAsync(3000);

			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(2);
			});
		});

		it('shows refreshing state while polling', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);
			vi.mocked(api.api.getTemperature).mockResolvedValue(mockTemperatureRefreshing);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(screen.getByText(/refreshing sensor/i)).toBeTruthy();
			});
		});

		it('stops polling when refresh_in_progress becomes false', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);
			// First call: refreshing, second call: complete
			vi.mocked(api.api.getTemperature)
				.mockResolvedValueOnce(mockTemperatureRefreshing)
				.mockResolvedValueOnce(mockTemperatureData);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// Wait for first poll
			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			});

			// Advance timer
			await vi.advanceTimersByTimeAsync(3000);

			// Second poll shows complete
			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(2);
			});

			// Should not show refreshing anymore
			expect(screen.queryByText(/refreshing sensor/i)).toBeNull();

			// Advance timer more - should NOT poll again
			await vi.advanceTimersByTimeAsync(3000);
			expect(api.api.getTemperature).toHaveBeenCalledTimes(2);
		});

		it('stops polling after max attempts (5 polls = 15 seconds)', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);
			// Always return refreshing state
			vi.mocked(api.api.getTemperature).mockResolvedValue(mockTemperatureRefreshing);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			// Wait for initial poll
			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(1);
			});

			// Advance through 4 more poll cycles (5 total)
			for (let i = 0; i < 4; i++) {
				await vi.advanceTimersByTimeAsync(3000);
			}

			await waitFor(() => {
				expect(api.api.getTemperature).toHaveBeenCalledTimes(5);
			});

			// Advance again - should NOT poll (max attempts reached)
			await vi.advanceTimersByTimeAsync(3000);
			expect(api.api.getTemperature).toHaveBeenCalledTimes(5);
		});

		it('handles refreshTemperature API failure gracefully', async () => {
			vi.mocked(settings.getCachedTemperature).mockReturnValue(mockCachedData);
			vi.mocked(api.api.refreshTemperature).mockRejectedValue(new Error('Network error'));
			vi.mocked(api.api.getTemperature).mockResolvedValue(mockTemperatureData);

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/95\.0/)).toBeTruthy();
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
