import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/svelte';
import SchedulePanel from './SchedulePanel.svelte';
import { api } from '$lib/api';
import * as autoHeatOff from '$lib/autoHeatOff';

// Mock the api module
vi.mock('$lib/api', () => ({
	api: {
		listScheduledJobs: vi.fn(),
		scheduleJob: vi.fn(),
		cancelScheduledJob: vi.fn(),
	},
}));

// Mock autoHeatOff module
vi.mock('$lib/autoHeatOff', () => ({
	getAutoHeatOffEnabled: vi.fn(() => false),
	setAutoHeatOffEnabled: vi.fn(),
	getAutoHeatOffMinutes: vi.fn(() => 150),
	setAutoHeatOffMinutes: vi.fn(),
	calculateHeatOffTime: vi.fn((time: string, minutes: number) => {
		// Simple mock: just return a fixed time for tests
		return '2024-12-11T08:30:00-05:00';
	}),
	AUTO_HEAT_OFF_DEFAULTS: {
		enabled: false,
		minutes: 150,
		minMinutes: 30,
		maxMinutes: 480
	}
}));

// Mock the config module with test-friendly values
// Using original production values for unit tests (they use fake timers anyway)
vi.mock('$lib/config', () => ({
	schedulerConfig: {
		refreshBufferMs: 60 * 1000, // 60 seconds
		maxTimerWindowMs: 60 * 60 * 1000, // 1 hour
		recheckIntervalMs: 30 * 60 * 1000, // 30 minutes
	},
}));

describe('SchedulePanel auto-refresh', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		vi.clearAllMocks();
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('sets up a refresh timer for jobs scheduled in the near future', async () => {
		// Job scheduled 2 minutes from now
		const now = new Date();
		const twoMinutesFromNow = new Date(now.getTime() + 2 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-123',
					action: 'heater-on',
					scheduledTime: twoMinutesFromNow.toISOString(),
					createdAt: now.toISOString(),
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		render(SchedulePanel);

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		// Reset the mock call count to track subsequent calls
		listJobsSpy.mockClear();

		// Now the job is still pending - should not have refreshed yet
		expect(listJobsSpy).toHaveBeenCalledTimes(0);

		// Fast forward to after the scheduled time + buffer (60 seconds after scheduled time)
		// 2 min + 60 sec = 3 min total
		vi.advanceTimersByTime(3 * 60 * 1000);

		// Should have triggered a refresh
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});
	});

	it('clears timers when component unmounts', async () => {
		const now = new Date();
		const fiveMinutesFromNow = new Date(now.getTime() + 5 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-456',
					action: 'heater-off',
					scheduledTime: fiveMinutesFromNow.toISOString(),
					createdAt: now.toISOString(),
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		const { unmount } = render(SchedulePanel);

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		// Unmount the component
		unmount();

		// Clear mock to track subsequent calls
		listJobsSpy.mockClear();

		// Fast forward past the scheduled time
		vi.advanceTimersByTime(10 * 60 * 1000);

		// Should NOT have called listJobs since component unmounted
		expect(listJobsSpy).toHaveBeenCalledTimes(0);
	});

	it('does not set timer for jobs far in the future', async () => {
		const now = new Date();
		// Job scheduled 2 hours from now
		const twoHoursFromNow = new Date(now.getTime() + 2 * 60 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-789',
					action: 'pump-run',
					scheduledTime: twoHoursFromNow.toISOString(),
					createdAt: now.toISOString(),
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		render(SchedulePanel);

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		listJobsSpy.mockClear();

		// Fast forward 30 minutes - job shouldn't trigger refresh yet
		vi.advanceTimersByTime(30 * 60 * 1000);

		// Should not have refreshed for a job 2 hours away
		expect(listJobsSpy).toHaveBeenCalledTimes(0);
	});

	it('sets timer for job scheduled 1 minute from now', async () => {
		// Test shorter time window (1 minute) to verify timer precision
		const now = new Date();
		const oneMinuteFromNow = new Date(now.getTime() + 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-short',
					action: 'heater-on',
					scheduledTime: oneMinuteFromNow.toISOString(),
					createdAt: now.toISOString(),
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		render(SchedulePanel);

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		listJobsSpy.mockClear();

		// Fast forward to just before the scheduled time + buffer (59 seconds after scheduled = 119 sec total)
		vi.advanceTimersByTime(119 * 1000);

		// Should NOT have triggered a refresh yet (buffer not elapsed)
		expect(listJobsSpy).toHaveBeenCalledTimes(0);

		// Fast forward past the buffer (1 more second)
		vi.advanceTimersByTime(2 * 1000);

		// Should have triggered a refresh now (1 min + 60 sec buffer = 2 min total)
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});
	});

	it('sliding window promotes far-future jobs into timer set', async () => {
		// Job scheduled 75 minutes from now (outside initial 60-min window)
		const now = new Date();
		const seventyFiveMinFromNow = new Date(now.getTime() + 75 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-far',
					action: 'heater-on',
					scheduledTime: seventyFiveMinFromNow.toISOString(),
					createdAt: now.toISOString(),
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		render(SchedulePanel);

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		listJobsSpy.mockClear();

		// At T=0: Job is 75 min away, outside 60-min window, no timer set
		// Fast forward 20 min - still no refresh (job 55 min away but recheck hasn't fired)
		vi.advanceTimersByTime(20 * 60 * 1000);
		expect(listJobsSpy).toHaveBeenCalledTimes(0);

		// Fast forward to T=30 (recheck fires)
		// Job is now 45 min away, INSIDE the 60-min window
		// Sliding window recheck promotes it by setting a timer
		vi.advanceTimersByTime(10 * 60 * 1000);

		// Still no API call yet (recheck doesn't call loadJobs, just sets timers)
		expect(listJobsSpy).toHaveBeenCalledTimes(0);

		// Fast forward to after job's scheduled time + buffer
		// Job is at T=75, buffer is 60 sec, so timer fires at T=76
		// We're at T=30, so advance 46 more minutes
		vi.advanceTimersByTime(46 * 60 * 1000);

		// Timer should have fired and called loadJobs
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});
	});

	it('does not create duplicate timers when recheck fires multiple times', async () => {
		// Scenario: Job at 75 min, multiple rechecks at 30 and 60 min
		// Should only call loadJobs ONCE when the single timer fires
		const now = new Date();
		const seventyFiveMinFromNow = new Date(now.getTime() + 75 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-no-dupe',
					action: 'heater-on',
					scheduledTime: seventyFiveMinFromNow.toISOString(),
					createdAt: now.toISOString(),
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		render(SchedulePanel);

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		listJobsSpy.mockClear();

		// T=0: Job is 75 min away, outside 61-min window (60 + 1 buffer), no timer set

		// Advance to T=30 (first recheck fires)
		// Job is now 45 min away, inside window, timer set for T=76 (75 + 1 buffer)
		vi.advanceTimersByTime(30 * 60 * 1000);
		expect(listJobsSpy).toHaveBeenCalledTimes(0); // No API call yet

		// Advance to T=60 (second recheck fires)
		// Job is now 15 min away, still inside window
		// BUT timer already exists for this jobId, so no duplicate should be set
		vi.advanceTimersByTime(30 * 60 * 1000);
		expect(listJobsSpy).toHaveBeenCalledTimes(0); // Still no API call

		// Advance to T=76 (timer fires: 75 min scheduled + 1 min buffer)
		// If there were duplicate timers, we'd see 2 calls
		vi.advanceTimersByTime(16 * 60 * 1000);

		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});

		// Critical assertion: exactly ONE call, not two
		// If duplicates were created, we'd see 2 calls
		expect(listJobsSpy).toHaveBeenCalledTimes(1);
	});

	it('sliding window cleans up on unmount', async () => {
		const now = new Date();
		const twoHoursFromNow = new Date(now.getTime() + 2 * 60 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-unmount-test',
					action: 'pump-run',
					scheduledTime: twoHoursFromNow.toISOString(),
					createdAt: now.toISOString(),
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		const { unmount } = render(SchedulePanel);

		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		unmount();
		listJobsSpy.mockClear();

		// Fast forward past multiple recheck intervals
		// If timers weren't cleaned up, rechecks would still fire
		vi.advanceTimersByTime(2 * 60 * 60 * 1000);

		// No API calls since component unmounted
		expect(listJobsSpy).toHaveBeenCalledTimes(0);
	});
});

describe('SchedulePanel Auto Heat Off UI', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		vi.clearAllMocks();
		// Default mock returns
		vi.mocked(api.listScheduledJobs).mockResolvedValue({ jobs: [] });
		vi.mocked(api.scheduleJob).mockResolvedValue({ jobId: 'job-123', action: 'heater-on', scheduledTime: '2024-12-11T06:00:00-05:00', createdAt: '2024-12-10T10:00:00-05:00' });
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('renders Auto Heat Off section header', async () => {
		render(SchedulePanel);
		await waitFor(() => {
			expect(screen.getByText('Auto Heat Off')).toBeTruthy();
		});
	});

	it('renders checkbox with label "Enable auto heat-off"', async () => {
		render(SchedulePanel);
		await waitFor(() => {
			expect(screen.getByLabelText('Enable auto heat-off')).toBeTruthy();
		});
	});

	it('renders minutes input with label', async () => {
		render(SchedulePanel);
		await waitFor(() => {
			// The label "Turn off after" is associated via htmlFor with the input id
			expect(screen.getByRole('spinbutton')).toBeTruthy();
		});
	});

	it('checkbox reflects getAutoHeatOffEnabled state (false)', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(false);
		render(SchedulePanel);
		await waitFor(() => {
			const checkbox = screen.getByLabelText('Enable auto heat-off') as HTMLInputElement;
			expect(checkbox.checked).toBe(false);
		});
	});

	it('checkbox reflects getAutoHeatOffEnabled state (true)', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
		render(SchedulePanel);
		await waitFor(() => {
			const checkbox = screen.getByLabelText('Enable auto heat-off') as HTMLInputElement;
			expect(checkbox.checked).toBe(true);
		});
	});

	it('minutes input shows getAutoHeatOffMinutes value', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(180);
		render(SchedulePanel);
		await waitFor(() => {
			const input = screen.getByRole('spinbutton') as HTMLInputElement;
			expect(input.value).toBe('180');
		});
	});

	it('clicking checkbox calls setAutoHeatOffEnabled', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(false);
		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByLabelText('Enable auto heat-off')).toBeTruthy();
		});

		const checkbox = screen.getByLabelText('Enable auto heat-off');
		await fireEvent.click(checkbox);

		expect(autoHeatOff.setAutoHeatOffEnabled).toHaveBeenCalledWith(true);
	});

	it('changing minutes input calls setAutoHeatOffMinutes on blur', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(150);
		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByRole('spinbutton')).toBeTruthy();
		});

		const input = screen.getByRole('spinbutton') as HTMLInputElement;
		await fireEvent.input(input, { target: { value: '200' } });
		await fireEvent.blur(input);

		expect(autoHeatOff.setAutoHeatOffMinutes).toHaveBeenCalledWith(200);
	});
});

describe('SchedulePanel paired job creation', () => {
	beforeEach(() => {
		// Use real timers for these tests since they're testing scheduling logic, not timer behavior
		vi.clearAllMocks();
		vi.mocked(api.listScheduledJobs).mockResolvedValue({ jobs: [] });
	});

	it('creates only heater-on job when auto heat-off is disabled', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(false);
		vi.mocked(api.scheduleJob).mockResolvedValue({
			jobId: 'job-on',
			action: 'heater-on',
			scheduledTime: '2024-12-11T06:00:00-05:00',
			createdAt: '2024-12-10T10:00:00-05:00'
		});

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByRole('button', { name: /schedule/i })).toBeTruthy();
		});

		// Click schedule button
		const scheduleBtn = screen.getByRole('button', { name: /schedule/i });
		await fireEvent.click(scheduleBtn);

		await waitFor(() => {
			expect(api.scheduleJob).toHaveBeenCalledTimes(1);
		});
	});

	it('creates both heater-on and heater-off jobs when auto heat-off is enabled and action is heater-on', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
		vi.mocked(autoHeatOff.getAutoHeatOffMinutes).mockReturnValue(150);
		vi.mocked(api.scheduleJob).mockResolvedValue({
			jobId: 'job-123',
			action: 'heater-on',
			scheduledTime: '2024-12-11T06:00:00-05:00',
			createdAt: '2024-12-10T10:00:00-05:00'
		});

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByRole('button', { name: /schedule/i })).toBeTruthy();
		});

		// Click schedule button (default action is heater-on)
		const scheduleBtn = screen.getByRole('button', { name: /schedule/i });
		await fireEvent.click(scheduleBtn);

		await waitFor(() => {
			// Should call scheduleJob twice: once for heater-on, once for heater-off
			expect(api.scheduleJob).toHaveBeenCalledTimes(2);
		});

		// First call is heater-on
		expect(api.scheduleJob).toHaveBeenNthCalledWith(1, 'heater-on', expect.any(String));
		// Second call is heater-off with calculated time
		expect(api.scheduleJob).toHaveBeenNthCalledWith(2, 'heater-off', expect.any(String));
	});

	it('creates only single job when auto heat-off is enabled but action is not heater-on', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
		vi.mocked(api.scheduleJob).mockResolvedValue({
			jobId: 'job-off',
			action: 'heater-off',
			scheduledTime: '2024-12-11T06:00:00-05:00',
			createdAt: '2024-12-10T10:00:00-05:00'
		});

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByRole('button', { name: /schedule/i })).toBeTruthy();
		});

		// Select heater-off action
		const actionSelect = screen.getByLabelText('Action') as HTMLSelectElement;
		await fireEvent.change(actionSelect, { target: { value: 'heater-off' } });

		// Click schedule button
		const scheduleBtn = screen.getByRole('button', { name: /schedule/i });
		await fireEvent.click(scheduleBtn);

		await waitFor(() => {
			// Should call scheduleJob only once
			expect(api.scheduleJob).toHaveBeenCalledTimes(1);
		});

		expect(api.scheduleJob).toHaveBeenCalledWith('heater-off', expect.any(String));
	});
});

describe('SchedulePanel manual refresh button', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.listScheduledJobs).mockResolvedValue({ jobs: [] });
	});

	it('renders a refresh button in the Pending Jobs section', async () => {
		render(SchedulePanel);

		await waitFor(() => {
			// Button should have aria-label for accessibility
			expect(screen.getByRole('button', { name: /refresh/i })).toBeTruthy();
		});
	});

	it('calls listScheduledJobs when refresh button is clicked', async () => {
		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'job-123',
					action: 'heater-on',
					scheduledTime: '2024-12-12T06:00:00-05:00',
					createdAt: '2024-12-11T10:00:00-05:00'
				}
			]
		});

		render(SchedulePanel);

		// Wait for initial load
		await waitFor(() => {
			expect(api.listScheduledJobs).toHaveBeenCalledTimes(1);
		});

		// Clear to track the refresh call
		vi.mocked(api.listScheduledJobs).mockClear();

		// Click the refresh button
		const refreshBtn = screen.getByRole('button', { name: /refresh/i });
		await fireEvent.click(refreshBtn);

		// Should call listScheduledJobs again
		await waitFor(() => {
			expect(api.listScheduledJobs).toHaveBeenCalledTimes(1);
		});
	});

	it('shows tooltip on long press', async () => {
		vi.useFakeTimers();
		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByRole('button', { name: /refresh/i })).toBeTruthy();
		});

		const refreshBtn = screen.getByRole('button', { name: /refresh/i });

		// Start press
		await fireEvent.mouseDown(refreshBtn);

		// Tooltip should not be visible yet
		expect(screen.queryByText(/refresh pending jobs/i)).toBeNull();

		// Advance timer to show tooltip (500ms)
		vi.advanceTimersByTime(500);

		// Tooltip should now be visible
		await waitFor(() => {
			expect(screen.getByText(/refresh pending jobs/i)).toBeTruthy();
		});

		// Release press
		await fireEvent.mouseUp(refreshBtn);

		vi.useRealTimers();
	});
});
