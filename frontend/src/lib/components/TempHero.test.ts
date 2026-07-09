import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/svelte';
import TempHero from './TempHero.svelte';
import * as api from '$lib/api';

vi.mock('$lib/api', () => ({
	api: {
		getAllTemperatures: vi.fn()
	}
}));

const freshData = (overrides: Partial<api.TemperatureData> = {}): api.AllTemperaturesResponse => ({
	esp32: {
		water_temp_f: 99.1,
		water_temp_c: 37.3,
		ambient_temp_f: 55.0,
		ambient_temp_c: 12.8,
		device_name: 'ESP32 Temperature Sensor',
		timestamp: new Date().toISOString(),
		source: 'esp32',
		device_id: 'AA:BB:CC:DD:EE:FF',
		uptime_seconds: 3600,
		...overrides
	} as api.TemperatureData
});

describe('TempHero', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('shows a big water reading with air temp and relative age', async () => {
		vi.mocked(api.api.getAllTemperatures).mockResolvedValue(freshData());
		render(TempHero);

		await waitFor(() => {
			expect(screen.getByTestId('hero-water').textContent).toContain('99.1');
		});
		expect(screen.getByTestId('hero-subline').textContent).toContain('air 55°');
		expect(screen.getByTestId('hero-subline').textContent).toContain('just now');
		expect(screen.queryByTestId('hero-stale')).toBeNull();
	});

	it('flags a stale reading (≥10 min) without hiding the data', async () => {
		const staleTs = new Date(Date.now() - 14 * 60_000).toISOString();
		vi.mocked(api.api.getAllTemperatures).mockResolvedValue(freshData({ timestamp: staleTs }));
		render(TempHero);

		await waitFor(() => {
			expect(screen.getByTestId('hero-water').textContent).toContain('99.1');
		});
		expect(screen.getByTestId('hero-stale').textContent).toContain('Last reading 14m ago');
	});

	it('says what happened when the tub cannot be reached', async () => {
		vi.mocked(api.api.getAllTemperatures).mockRejectedValue(new Error('boom'));
		render(TempHero);

		await waitFor(() => {
			expect(screen.getByTestId('hero-error').textContent).toContain("Couldn't reach the tub");
		});
	});

	it('handles missing sensor data with a pointer to Setup', async () => {
		vi.mocked(api.api.getAllTemperatures).mockResolvedValue({ esp32: null });
		render(TempHero);

		await waitFor(() => {
			expect(screen.getByTestId('hero-nodata').textContent).toContain('No temperature data');
		});
	});
});
