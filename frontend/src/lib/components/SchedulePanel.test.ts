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
		skipScheduledJob: vi.fn(),
		unskipScheduledJob: vi.fn(),
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

// Mock heatTargetSettings store
vi.mock('$lib/stores/heatTargetSettings.svelte', () => ({
	getEnabled: vi.fn(() => false),
	getTargetTempF: vi.fn(() => 103)
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
					recurring: false,
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
					recurring: false,
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
					recurring: false,
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
					recurring: false,
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
					recurring: false,
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
					recurring: false,
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
					recurring: false,
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

describe('SchedulePanel recurring job timezone', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.listScheduledJobs).mockResolvedValue({ jobs: [] });
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(false);
	});

	it('sends timezone offset with recurring job time', async () => {
		vi.mocked(api.scheduleJob).mockResolvedValue({
			jobId: 'rec-123',
			action: 'heater-on',
			scheduledTime: '14:30:00+00:00', // UTC time returned from backend
			createdAt: '2024-12-10T10:00:00+00:00',
			recurring: true
		});

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByRole('button', { name: /schedule/i })).toBeTruthy();
		});

		// Check the recurring checkbox
		const recurringCheckbox = screen.getByRole('checkbox', { name: /recurring/i });
		await fireEvent.click(recurringCheckbox);

		// Set time to 06:30
		const timeInput = screen.getByLabelText('Time') as HTMLInputElement;
		await fireEvent.change(timeInput, { target: { value: '06:30' } });

		// Click schedule button
		const scheduleBtn = screen.getByRole('button', { name: /schedule/i });
		await fireEvent.click(scheduleBtn);

		await waitFor(() => {
			expect(api.scheduleJob).toHaveBeenCalledTimes(1);
		});

		// The time should include timezone offset (e.g., "06:30-08:00" for PST)
		// We can't know the exact offset since it depends on the test environment,
		// but we verify the format includes an offset
		const [action, time, recurring] = vi.mocked(api.scheduleJob).mock.calls[0];
		expect(action).toBe('heater-on');
		expect(recurring).toBe(true);
		// Time should match pattern HH:MM+HH:MM or HH:MM-HH:MM
		expect(time).toMatch(/^\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
	});
});

// Auto Heat Off UI tests moved to SettingsPanel.test.ts

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
			createdAt: '2024-12-10T10:00:00-05:00',
			recurring: false
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
			createdAt: '2024-12-10T10:00:00-05:00',
			recurring: false
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

		// First call is heater-on (with recurring=false for one-off job)
		expect(api.scheduleJob).toHaveBeenNthCalledWith(1, 'heater-on', expect.any(String), false);
		// Second call is heater-off with calculated time (with recurring=false for one-off job)
		expect(api.scheduleJob).toHaveBeenNthCalledWith(2, 'heater-off', expect.any(String), false);
	});

	it('creates only single job when auto heat-off is enabled but action is not heater-on', async () => {
		vi.mocked(autoHeatOff.getAutoHeatOffEnabled).mockReturnValue(true);
		vi.mocked(api.scheduleJob).mockResolvedValue({
			jobId: 'job-off',
			action: 'heater-off',
			scheduledTime: '2024-12-11T06:00:00-05:00',
			createdAt: '2024-12-10T10:00:00-05:00',
			recurring: false
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

		expect(api.scheduleJob).toHaveBeenCalledWith('heater-off', expect.any(String), false);
	});
});

describe('SchedulePanel heater-off completion callback', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		vi.clearAllMocks();
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('calls onHeaterOffCompleted callback when heater-off job timer fires', async () => {
		const now = new Date();
		const twoMinutesFromNow = new Date(now.getTime() + 2 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-heater-off',
					action: 'heater-off',
					scheduledTime: twoMinutesFromNow.toISOString(),
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		const onHeaterOffCompleted = vi.fn();

		render(SchedulePanel, { props: { onHeaterOffCompleted } });

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		// Callback should not have been called yet
		expect(onHeaterOffCompleted).not.toHaveBeenCalled();

		// Fast forward past scheduled time + buffer (3 minutes)
		vi.advanceTimersByTime(3 * 60 * 1000);

		// Should have triggered the callback
		await waitFor(() => {
			expect(onHeaterOffCompleted).toHaveBeenCalledTimes(1);
		});
	});

	it('does not call onHeaterOffCompleted for heater-on jobs', async () => {
		const now = new Date();
		const twoMinutesFromNow = new Date(now.getTime() + 2 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-heater-on',
					action: 'heater-on',
					scheduledTime: twoMinutesFromNow.toISOString(),
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		const onHeaterOffCompleted = vi.fn();

		render(SchedulePanel, { props: { onHeaterOffCompleted } });

		// Wait for initial load
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		// Fast forward past scheduled time + buffer
		vi.advanceTimersByTime(3 * 60 * 1000);

		// Callback should NOT be called for heater-on jobs
		expect(onHeaterOffCompleted).not.toHaveBeenCalled();
	});

	it('works without onHeaterOffCompleted prop (optional)', async () => {
		const now = new Date();
		const twoMinutesFromNow = new Date(now.getTime() + 2 * 60 * 1000);

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-heater-off-optional',
					action: 'heater-off',
					scheduledTime: twoMinutesFromNow.toISOString(),
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		// Render without the callback - should not throw
		render(SchedulePanel);

		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		// Fast forward past scheduled time + buffer - should not throw
		vi.advanceTimersByTime(3 * 60 * 1000);

		// If we get here without errors, the test passes
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});
	});
});

describe('SchedulePanel UTC timezone handling', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		vi.clearAllMocks();
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('correctly parses UTC times with +00:00 offset', async () => {
		// Set up a known current time
		const now = new Date();
		vi.setSystemTime(now);

		// Create a job scheduled 5 minutes from now in UTC
		const fiveMinutesFromNow = new Date(now.getTime() + 5 * 60 * 1000);
		// API returns UTC time with +00:00 offset
		const utcScheduledTime = fiveMinutesFromNow.toISOString().replace('Z', '+00:00');

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-utc-test',
					action: 'heater-on',
					scheduledTime: utcScheduledTime,
					createdAt: now.toISOString().replace('Z', '+00:00'),
					recurring: false,
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

		// Timer should fire at scheduled time + 60s buffer = 6 minutes total
		vi.advanceTimersByTime(6 * 60 * 1000);

		// Should trigger a refresh proving UTC was parsed correctly
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});
	});

	it('correctly parses UTC times with Z suffix', async () => {
		const now = new Date();
		vi.setSystemTime(now);

		// Create a job scheduled 5 minutes from now
		const fiveMinutesFromNow = new Date(now.getTime() + 5 * 60 * 1000);
		// API returns UTC with Z suffix
		const utcScheduledTime = fiveMinutesFromNow.toISOString(); // ends with Z

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-utc-z-test',
					action: 'heater-off',
					scheduledTime: utcScheduledTime,
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		render(SchedulePanel);

		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		listJobsSpy.mockClear();

		// Timer should fire at scheduled time + 60s buffer = 6 minutes total
		vi.advanceTimersByTime(6 * 60 * 1000);

		// Should trigger a refresh proving UTC (Z suffix) was parsed correctly
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});
	});

	it('correctly calculates timer delay from UTC scheduled time', async () => {
		// Set current time
		const now = new Date();
		vi.setSystemTime(now);

		// Schedule a job 2 minutes from now in UTC
		const twoMinutesFromNowUtc = new Date(now.getTime() + 2 * 60 * 1000);
		const utcTimeString = twoMinutesFromNowUtc.toISOString(); // Returns UTC string with Z suffix

		const mockJobs = {
			jobs: [
				{
					jobId: 'job-timer-calc',
					action: 'heater-on',
					scheduledTime: utcTimeString,
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		};

		const listJobsSpy = vi.mocked(api.listScheduledJobs);
		listJobsSpy.mockResolvedValue(mockJobs);

		render(SchedulePanel);

		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalledTimes(1);
		});

		listJobsSpy.mockClear();

		// Timer should fire at scheduled time + 60s buffer = 3 minutes total
		vi.advanceTimersByTime(3 * 60 * 1000);

		// Should trigger a refresh
		await waitFor(() => {
			expect(listJobsSpy).toHaveBeenCalled();
		});
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
					createdAt: '2024-12-11T10:00:00-05:00',
					recurring: false
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

describe('SchedulePanel action dropdown', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		vi.clearAllMocks();
		vi.mocked(api.listScheduledJobs).mockResolvedValue({ jobs: [] });
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('shows "ready by" label for DTDT jobs', async () => {
		const now = new Date();
		const mockJobs = {
			jobs: [
				{
					jobId: 'rec-abc123',
					action: 'heat-to-target',
					scheduledTime: '14:30:00+00:00',
					createdAt: now.toISOString(),
					recurring: true,
					params: {
						target_temp_f: 103,
						ready_by_time: '06:30-08:00',
					},
				},
			],
		};

		vi.mocked(api.listScheduledJobs).mockResolvedValue(mockJobs);
		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText(/heat to 103°f \(ready by\)/i)).toBeTruthy();
		});
	});

	it('shows standard label for non-DTDT heat-to-target jobs', async () => {
		const now = new Date();
		const mockJobs = {
			jobs: [
				{
					jobId: 'rec-def456',
					action: 'heat-to-target',
					scheduledTime: '14:30:00+00:00',
					createdAt: now.toISOString(),
					recurring: true,
					params: {
						target_temp_f: 103,
					},
				},
			],
		};

		vi.mocked(api.listScheduledJobs).mockResolvedValue(mockJobs);
		render(SchedulePanel);

		await waitFor(() => {
			const label = screen.getByText(/heat to 103°f/i);
			expect(label).toBeTruthy();
			expect(label.textContent).not.toContain('ready by');
		});
	});

	it('does not include heat-to-target as a dropdown option', async () => {
		// Bug #1: The "Heat to Target" dropdown option is broken because it doesn't
		// include the required target_temp_f parameter. When target temp is enabled,
		// "Heater ON" automatically becomes "heat-to-target" with the temp included.
		// Therefore, "Heat to Target" should not be a separate dropdown option.
		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByLabelText(/action/i)).toBeTruthy();
		});

		const actionSelect = screen.getByLabelText(/action/i);
		const options = actionSelect.querySelectorAll('option');
		const optionValues = Array.from(options).map((opt) => opt.value);
		const optionLabels = Array.from(options).map((opt) => opt.textContent);

		// Should have 3 options: heater-on, heater-off, pump-run
		expect(optionValues).toEqual(['heater-on', 'heater-off', 'pump-run']);
		expect(optionLabels).toEqual(['Heater ON', 'Heater OFF', 'Run Pump']);

		// Should NOT include heat-to-target
		expect(optionValues).not.toContain('heat-to-target');
	});
});

describe('SchedulePanel cancel pending state', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('shows "Cancelling..." and disables button while cancel is in progress for one-off job', async () => {
		const now = new Date();
		const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);

		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'job-cancel-pending',
					action: 'heater-on',
					scheduledTime: tomorrow.toISOString(),
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		});

		// Make cancelScheduledJob hang (don't resolve yet)
		let resolveCancelPromise!: (value: { success: boolean }) => void;
		vi.mocked(api.cancelScheduledJob).mockImplementation(
			() => new Promise((resolve) => { resolveCancelPromise = resolve; })
		);

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Cancel')).toBeTruthy();
		});

		// Click cancel
		await fireEvent.click(screen.getByText('Cancel'));

		// Should now show "Cancelling..." text
		await waitFor(() => {
			expect(screen.getByText('Cancelling...')).toBeTruthy();
		});

		// The button should be disabled
		const cancelBtn = screen.getByText('Cancelling...');
		expect(cancelBtn).toBeInstanceOf(HTMLButtonElement);
		expect((cancelBtn as HTMLButtonElement).disabled).toBe(true);

		// The job row should have reduced opacity
		const listItem = cancelBtn.closest('li');
		expect(listItem?.className).toContain('opacity-50');

		// Resolve the cancel
		resolveCancelPromise({ success: true });

		// After resolve + reload, the job should be gone
		vi.mocked(api.listScheduledJobs).mockResolvedValue({ jobs: [] });
		await waitFor(() => {
			expect(screen.queryByText('Cancelling...')).toBeNull();
		});
	});

	it('shows "Cancelling..." for recurring job cancel', async () => {
		const now = new Date();

		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'rec-cancel-pending',
					action: 'heater-on',
					scheduledTime: '14:30:00+00:00',
					createdAt: now.toISOString(),
					recurring: true,
					skipped: false,
				},
			],
		});

		let resolveCancelPromise!: (value: { success: boolean }) => void;
		vi.mocked(api.cancelScheduledJob).mockImplementation(
			() => new Promise((resolve) => { resolveCancelPromise = resolve; })
		);

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Cancel')).toBeTruthy();
		});

		await fireEvent.click(screen.getByText('Cancel'));

		await waitFor(() => {
			expect(screen.getByText('Cancelling...')).toBeTruthy();
		});

		// The recurring job row should have reduced opacity
		const cancelBtn = screen.getByText('Cancelling...');
		const listItem = cancelBtn.closest('li');
		expect(listItem?.className).toContain('opacity-50');

		// Skip button should also be hidden/disabled during cancel
		expect(screen.queryByText('Skip next')).toBeNull();

		// Resolve
		resolveCancelPromise({ success: true });
		vi.mocked(api.listScheduledJobs).mockResolvedValue({ jobs: [] });
		await waitFor(() => {
			expect(screen.queryByText('Cancelling...')).toBeNull();
		});
	});

	it('restores cancel button on error', async () => {
		const now = new Date();
		const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);

		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'job-cancel-error',
					action: 'heater-on',
					scheduledTime: tomorrow.toISOString(),
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		});

		let rejectCancelPromise!: (reason: Error) => void;
		vi.mocked(api.cancelScheduledJob).mockImplementation(
			() => new Promise((_, reject) => { rejectCancelPromise = reject; })
		);

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Cancel')).toBeTruthy();
		});

		await fireEvent.click(screen.getByText('Cancel'));

		await waitFor(() => {
			expect(screen.getByText('Cancelling...')).toBeTruthy();
		});

		// Reject the cancel
		rejectCancelPromise(new Error('Network error'));

		// Should restore the Cancel button and remove opacity
		await waitFor(() => {
			expect(screen.getByText('Cancel')).toBeTruthy();
			expect(screen.queryByText('Cancelling...')).toBeNull();
		});

		const listItem = screen.getByText('Cancel').closest('li');
		expect(listItem?.className).not.toContain('opacity-50');
	});
});

describe('SchedulePanel skip/unskip', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('shows "Skip next" button for recurring jobs', async () => {
		const now = new Date();
		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'rec-skip1',
					action: 'heater-on',
					scheduledTime: '14:30:00+00:00',
					createdAt: now.toISOString(),
					recurring: true,
					skipped: false,
				},
			],
		});

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Skip next')).toBeTruthy();
		});
	});

	it('does not show "Skip next" button for one-off jobs', async () => {
		const now = new Date();
		const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'job-noskip',
					action: 'heater-on',
					scheduledTime: tomorrow.toISOString(),
					createdAt: now.toISOString(),
					recurring: false,
				},
			],
		});

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Heater ON')).toBeTruthy();
		});

		expect(screen.queryByText('Skip next')).toBeNull();
	});

	it('calls skipScheduledJob API and refreshes when skip is clicked', async () => {
		const now = new Date();
		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'rec-skip2',
					action: 'heater-on',
					scheduledTime: '14:30:00+00:00',
					createdAt: now.toISOString(),
					recurring: true,
					skipped: false,
				},
			],
		});
		vi.mocked(api.skipScheduledJob).mockResolvedValue({ success: true });

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Skip next')).toBeTruthy();
		});

		vi.mocked(api.listScheduledJobs).mockClear();

		await fireEvent.click(screen.getByText('Skip next'));

		await waitFor(() => {
			expect(api.skipScheduledJob).toHaveBeenCalledWith('rec-skip2');
			expect(api.listScheduledJobs).toHaveBeenCalled();
		});
	});

	it('shows amber styling and Unskip button for skipped jobs', async () => {
		const now = new Date();
		const skipDate = new Date(now);
		skipDate.setHours(14, 30, 0, 0);
		const resumeDate = new Date(skipDate);
		resumeDate.setDate(resumeDate.getDate() + 1);

		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'rec-skipped1',
					action: 'heater-on',
					scheduledTime: '14:30:00+00:00',
					createdAt: now.toISOString(),
					recurring: true,
					skipped: true,
					skipDate: skipDate.toISOString(),
					resumeDate: resumeDate.toISOString(),
				},
			],
		});

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Unskip')).toBeTruthy();
		});

		// Should show Unskip and Cancel, not Skip next
		expect(screen.queryByText('Skip next')).toBeNull();
		expect(screen.getByText('Cancel')).toBeTruthy();

		// Should have amber styling (bg-amber-900/20)
		const listItem = screen.getByText('Unskip').closest('li');
		expect(listItem?.className).toContain('amber');

		// Should show "Skipped" and "resumes" text
		expect(screen.getByText(/Skipped/)).toBeTruthy();
		expect(screen.getByText(/resumes/)).toBeTruthy();
	});

	it('calls unskipScheduledJob API and refreshes when unskip is clicked', async () => {
		const now = new Date();
		vi.mocked(api.listScheduledJobs).mockResolvedValue({
			jobs: [
				{
					jobId: 'rec-unskip1',
					action: 'heater-on',
					scheduledTime: '14:30:00+00:00',
					createdAt: now.toISOString(),
					recurring: true,
					skipped: true,
					skipDate: now.toISOString(),
					resumeDate: new Date(now.getTime() + 24 * 60 * 60 * 1000).toISOString(),
				},
			],
		});
		vi.mocked(api.unskipScheduledJob).mockResolvedValue({ success: true });

		render(SchedulePanel);

		await waitFor(() => {
			expect(screen.getByText('Unskip')).toBeTruthy();
		});

		vi.mocked(api.listScheduledJobs).mockClear();

		await fireEvent.click(screen.getByText('Unskip'));

		await waitFor(() => {
			expect(api.unskipScheduledJob).toHaveBeenCalledWith('rec-unskip1');
			expect(api.listScheduledJobs).toHaveBeenCalled();
		});
	});
});
