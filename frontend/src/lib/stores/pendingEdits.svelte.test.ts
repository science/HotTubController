import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
	registerPendingEdit,
	clearPendingEdit,
	clearAllPendingEdits,
	pendingEdits,
	hasPendingEdits,
	saveAllPendingEdits,
	discardAllPendingEdits,
	type PendingEdit
} from './pendingEdits.svelte';

function makeEdit(
	id: string,
	save: PendingEdit['save'] = vi.fn(),
	discard: PendingEdit['discard'] = vi.fn()
): PendingEdit {
	return { id, describe: () => id, save, discard };
}

describe('pendingEdits registry', () => {
	beforeEach(() => clearAllPendingEdits());

	it('registers a dirty editor', () => {
		expect(hasPendingEdits()).toBe(false);
		registerPendingEdit(makeEdit('a'));
		expect(hasPendingEdits()).toBe(true);
		expect(pendingEdits().map((e) => e.id)).toEqual(['a']);
	});

	it('dedupes by id — re-registering replaces the entry', () => {
		const first = vi.fn();
		const second = vi.fn();
		registerPendingEdit(makeEdit('a', first));
		registerPendingEdit(makeEdit('a', second));
		expect(pendingEdits()).toHaveLength(1);
		expect(pendingEdits()[0].save).toBe(second);
	});

	it('clears a single editor', () => {
		registerPendingEdit(makeEdit('a'));
		registerPendingEdit(makeEdit('b'));
		clearPendingEdit('a');
		expect(pendingEdits().map((e) => e.id)).toEqual(['b']);
	});

	it('saveAll calls each save in registration order, then empties', async () => {
		const order: string[] = [];
		registerPendingEdit(
			makeEdit(
				'a',
				vi.fn(() => {
					order.push('a');
				})
			)
		);
		registerPendingEdit(
			makeEdit(
				'b',
				vi.fn(() => {
					order.push('b');
				})
			)
		);
		await saveAllPendingEdits();
		expect(order).toEqual(['a', 'b']);
		expect(hasPendingEdits()).toBe(false);
	});

	it('saveAll keeps later entries when one save throws', async () => {
		const bSave = vi.fn();
		registerPendingEdit(
			makeEdit(
				'a',
				vi.fn(() => {
					throw new Error('boom');
				})
			)
		);
		registerPendingEdit(makeEdit('b', bSave));
		await expect(saveAllPendingEdits()).rejects.toThrow('boom');
		// 'a' failed and 'b' never ran — both remain for a retry.
		expect(pendingEdits().map((e) => e.id)).toEqual(['a', 'b']);
		expect(bSave).not.toHaveBeenCalled();
	});

	it('discardAll calls each discard and empties', () => {
		const da = vi.fn();
		const db = vi.fn();
		registerPendingEdit(makeEdit('a', vi.fn(), da));
		registerPendingEdit(makeEdit('b', vi.fn(), db));
		discardAllPendingEdits();
		expect(da).toHaveBeenCalledOnce();
		expect(db).toHaveBeenCalledOnce();
		expect(hasPendingEdits()).toBe(false);
	});
});
