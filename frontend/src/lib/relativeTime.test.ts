import { describe, it, expect } from 'vitest';
import { formatRelativeAge, isStaleReading, STALE_THRESHOLD_MS } from './relativeTime';

const T0 = new Date('2026-07-08T12:00:00Z');
const ago = (ms: number) => new Date(T0.getTime() - ms).toISOString();

describe('formatRelativeAge', () => {
	it('reads "just now" under a minute', () => {
		expect(formatRelativeAge(ago(0), T0)).toBe('just now');
		expect(formatRelativeAge(ago(59_000), T0)).toBe('just now');
	});

	it('reads minutes under an hour', () => {
		expect(formatRelativeAge(ago(60_000), T0)).toBe('1m ago');
		expect(formatRelativeAge(ago(14 * 60_000), T0)).toBe('14m ago');
		expect(formatRelativeAge(ago(59 * 60_000 + 30_000), T0)).toBe('59m ago');
	});

	it('reads hours + minutes under a day', () => {
		expect(formatRelativeAge(ago(60 * 60_000), T0)).toBe('1h ago');
		expect(formatRelativeAge(ago(90 * 60_000), T0)).toBe('1h 30m ago');
		expect(formatRelativeAge(ago(23 * 3_600_000), T0)).toBe('23h ago');
	});

	it('falls back to a date beyond a day', () => {
		const s = formatRelativeAge(ago(26 * 3_600_000), T0);
		expect(s).toMatch(/Jul/);
	});

	it('handles a future/clock-skewed timestamp as "just now"', () => {
		expect(formatRelativeAge(new Date(T0.getTime() + 60_000).toISOString(), T0)).toBe('just now');
	});
});

describe('isStaleReading', () => {
	it('is fresh under the 10-minute threshold and stale past it', () => {
		expect(isStaleReading(ago(STALE_THRESHOLD_MS - 1000), T0)).toBe(false);
		expect(isStaleReading(ago(STALE_THRESHOLD_MS + 1000), T0)).toBe(true);
	});

	it('treats a missing timestamp as not stale (no data ≠ stale data)', () => {
		expect(isStaleReading(undefined, T0)).toBe(false);
	});
});
