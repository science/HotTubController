import { describe, it, expect, vi, beforeEach } from 'vitest';
import { api } from './api';

describe('API Client', () => {
	beforeEach(() => {
		vi.resetAllMocks();
	});

	it('heaterOn calls correct endpoint', async () => {
		const mockFetch = vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({ success: true, action: 'heater_on', timestamp: '2024-01-01T00:00:00Z' }),
		});
		vi.stubGlobal('fetch', mockFetch);

		const result = await api.heaterOn();

		expect(mockFetch).toHaveBeenCalledWith('/backend/public/api/equipment/heater/on', { method: 'POST', credentials: 'include' });
		expect(result.success).toBe(true);
		expect(result.action).toBe('heater_on');
	});

	it('heaterOff calls correct endpoint', async () => {
		const mockFetch = vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({ success: true, action: 'heater_off', timestamp: '2024-01-01T00:00:00Z' }),
		});
		vi.stubGlobal('fetch', mockFetch);

		const result = await api.heaterOff();

		expect(mockFetch).toHaveBeenCalledWith('/backend/public/api/equipment/heater/off', { method: 'POST', credentials: 'include' });
		expect(result.success).toBe(true);
		expect(result.action).toBe('heater_off');
	});

	it('pumpRun calls correct endpoint', async () => {
		const mockFetch = vi.fn().mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({ success: true, action: 'pump_run', duration: 7200, timestamp: '2024-01-01T00:00:00Z' }),
		});
		vi.stubGlobal('fetch', mockFetch);

		const result = await api.pumpRun();

		expect(mockFetch).toHaveBeenCalledWith('/backend/public/api/equipment/pump/run', { method: 'POST', credentials: 'include' });
		expect(result.success).toBe(true);
		expect(result.action).toBe('pump_run');
		expect(result.duration).toBe(7200);
	});

	it('throws error when request fails', async () => {
		const mockFetch = vi.fn().mockResolvedValue({
			ok: false,
			status: 500,
		});
		vi.stubGlobal('fetch', mockFetch);

		await expect(api.heaterOn()).rejects.toThrow('Request failed');
	});
});
