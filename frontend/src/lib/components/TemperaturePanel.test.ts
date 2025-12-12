import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import TemperaturePanel from './TemperaturePanel.svelte';
import * as api from '$lib/api';

// Mock the api module
vi.mock('$lib/api', () => ({
	api: {
		getTemperature: vi.fn()
	}
}));

const mockTemperatureData: api.TemperatureData = {
	water_temp_f: 98.4,
	water_temp_c: 36.9,
	ambient_temp_f: 62.0,
	ambient_temp_c: 16.7,
	battery_voltage: 3.54,
	signal_dbm: -67,
	device_name: 'Hot Tub',
	timestamp: '2025-12-11T10:30:00Z'
};

describe('TemperaturePanel', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.api.getTemperature).mockResolvedValue(mockTemperatureData);
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
				expect(screen.getByText(/failed/i)).toBeTruthy();
			});
		});

		it('keeps showing error after failed refresh', async () => {
			vi.mocked(api.api.getTemperature).mockRejectedValue(new Error('Network error'));

			render(TemperaturePanel);

			await waitFor(() => {
				expect(screen.getByText(/failed/i)).toBeTruthy();
			});

			const button = screen.getByRole('button', { name: /refresh/i });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(screen.getByText(/failed/i)).toBeTruthy();
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
});
