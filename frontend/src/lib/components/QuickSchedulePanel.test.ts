import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import QuickSchedulePanel from './QuickSchedulePanel.svelte';
import * as api from '$lib/api';
import * as autoHeatOff from '$lib/autoHeatOff';

// Mock the api module
vi.mock('$lib/api', () => ({
	api: {
		scheduleJob: vi.fn()
	}
}));

// Mock autoHeatOff module
vi.mock('$lib/autoHeatOff', () => ({
	getAutoHeatOffEnabled: vi.fn(() => false),
	getAutoHeatOffMinutes: vi.fn(() => 150),
	calculateHeatOffTime: vi.fn((time: string, minutes: number) => {
		// Simple mock: add 2.5 hours to the time
		return '2025-12-12T08:30:00+00:00';
	})
}));

describe('QuickSchedulePanel', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		vi.setSystemTime(new Date(2025, 11, 11, 10, 0, 0));
		vi.clearAllMocks();
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	describe('rendering', () => {
		it('renders the section header', () => {
			render(QuickSchedulePanel);
			expect(screen.getByText('Quick Heat On')).toBeTruthy();
		});

		it('renders all 6 quick schedule buttons', () => {
			render(QuickSchedulePanel);

			expect(screen.getByRole('button', { name: '+7.5h' })).toBeTruthy();
			expect(screen.getByRole('button', { name: '6am' })).toBeTruthy();
			expect(screen.getByRole('button', { name: '6:30' })).toBeTruthy();
			expect(screen.getByRole('button', { name: '7am' })).toBeTruthy();
			expect(screen.getByRole('button', { name: '7:30' })).toBeTruthy();
			expect(screen.getByRole('button', { name: '8am' })).toBeTruthy();
		});
	});

	describe('scheduling', () => {
		it('calls scheduleJob API with heater-on action when button clicked', async () => {
			vi.mocked(api.api.scheduleJob).mockResolvedValue({
				jobId: 'job-123',
				action: 'heater-on',
				scheduledTime: '2025-12-12T06:00:00+00:00',
				createdAt: '2025-12-11T10:00:00+00:00'
			});

			render(QuickSchedulePanel);
			const button = screen.getByRole('button', { name: '6am' });

			await fireEvent.click(button);

			expect(api.api.scheduleJob).toHaveBeenCalledWith(
				'heater-on',
				expect.stringMatching(/2025-12-12T06:00:00/)
			);
		});

		it('calls scheduleJob with correct time for +7.5h', async () => {
			vi.mocked(api.api.scheduleJob).mockResolvedValue({
				jobId: 'job-123',
				action: 'heater-on',
				scheduledTime: '2025-12-11T17:30:00+00:00',
				createdAt: '2025-12-11T10:00:00+00:00'
			});

			render(QuickSchedulePanel);
			const button = screen.getByRole('button', { name: '+7.5h' });

			await fireEvent.click(button);

			expect(api.api.scheduleJob).toHaveBeenCalledWith(
				'heater-on',
				expect.stringMatching(/2025-12-11T17:30:00/)
			);
		});
	});

	describe('callbacks', () => {
		it('calls onScheduled callback with success details', async () => {
			const onScheduled = vi.fn();
			vi.mocked(api.api.scheduleJob).mockResolvedValue({
				jobId: 'job-123',
				action: 'heater-on',
				scheduledTime: '2025-12-12T06:00:00+00:00',
				createdAt: '2025-12-11T10:00:00+00:00'
			});

			render(QuickSchedulePanel, { props: { onScheduled } });
			const button = screen.getByRole('button', { name: '6am' });

			await fireEvent.click(button);

			await waitFor(() => {
				expect(onScheduled).toHaveBeenCalledWith({
					success: true,
					message: expect.stringContaining('scheduled')
				});
			});
		});

		it('calls onScheduled callback with error on failure', async () => {
			const onScheduled = vi.fn();
			vi.mocked(api.api.scheduleJob).mockRejectedValue(new Error('Network error'));

			render(QuickSchedulePanel, { props: { onScheduled } });
			const button = screen.getByRole('button', { name: '6am' });

			await fireEvent.click(button);

			await waitFor(() => {
				expect(onScheduled).toHaveBeenCalledWith({
					success: false,
					message: expect.stringContaining('Failed')
				});
			});
		});
	});

	describe('loading state', () => {
		it('disables button while scheduling', async () => {
			let resolvePromise: (value: unknown) => void;
			const promise = new Promise((resolve) => {
				resolvePromise = resolve;
			});
			vi.mocked(api.api.scheduleJob).mockReturnValue(promise as Promise<api.ScheduledJob>);

			render(QuickSchedulePanel);
			const button = screen.getByRole('button', { name: '6am' });

			await fireEvent.click(button);

			expect(button).toHaveProperty('disabled', true);

			resolvePromise!({
				jobId: 'job-123',
				action: 'heater-on',
				scheduledTime: '2025-12-12T06:00:00+00:00',
				createdAt: '2025-12-11T10:00:00+00:00'
			});
		});
	});

	describe('auto heat-off integration', () => {
		beforeEach(() => {
			// Reset to real timers for these tests
			vi.useRealTimers();
			vi.clearAllMocks();
		});

		it('creates only heater-on job when auto heat-off is disabled', async () => {
			vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(false);
			vi.mocked(api.api.scheduleJob).mockResolvedValue({
				jobId: 'job-123',
				action: 'heater-on',
				scheduledTime: '2025-12-12T06:00:00+00:00',
				createdAt: '2025-12-11T10:00:00+00:00'
			});

			render(QuickSchedulePanel);
			const button = screen.getByRole('button', { name: '6am' });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(api.api.scheduleJob).toHaveBeenCalledTimes(1);
			});
			expect(api.api.scheduleJob).toHaveBeenCalledWith('heater-on', expect.any(String));
		});

		it('creates both heater-on and heater-off jobs when auto heat-off is enabled', async () => {
			vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
			vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(150);
			vi.mocked(api.api.scheduleJob).mockResolvedValue({
				jobId: 'job-123',
				action: 'heater-on',
				scheduledTime: '2025-12-12T06:00:00+00:00',
				createdAt: '2025-12-11T10:00:00+00:00'
			});

			render(QuickSchedulePanel);
			const button = screen.getByRole('button', { name: '6am' });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(api.api.scheduleJob).toHaveBeenCalledTimes(2);
			});

			// First call is heater-on
			expect(api.api.scheduleJob).toHaveBeenNthCalledWith(1, 'heater-on', expect.any(String));
			// Second call is heater-off
			expect(api.api.scheduleJob).toHaveBeenNthCalledWith(2, 'heater-off', expect.any(String));
		});

		it('calls onScheduled with combined message when auto heat-off creates both jobs', async () => {
			const onScheduled = vi.fn();
			vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
			vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(150);
			vi.mocked(api.api.scheduleJob).mockResolvedValue({
				jobId: 'job-123',
				action: 'heater-on',
				scheduledTime: '2025-12-12T06:00:00+00:00',
				createdAt: '2025-12-11T10:00:00+00:00'
			});

			render(QuickSchedulePanel, { props: { onScheduled } });
			const button = screen.getByRole('button', { name: '6am' });
			await fireEvent.click(button);

			await waitFor(() => {
				expect(onScheduled).toHaveBeenCalledWith({
					success: true,
					message: expect.stringContaining('auto')
				});
			});
		});
	});
});
