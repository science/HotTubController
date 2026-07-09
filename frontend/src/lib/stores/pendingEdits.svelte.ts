/**
 * Registry of unsaved schedule edits ("dirty" cards).
 *
 * Why this exists: changing a scheduled job on the backend is expensive — it rewrites
 * the crontab (delete + recreate), and the override-next composition writes a skip *and*
 * a one-off. On the shared cpanel host each crontab install costs real wall-clock time,
 * so we never auto-send on every ± tap. Instead a card stages its edits locally and
 * registers here while dirty; an explicit Save collapses the whole adjustment into ONE
 * backend call, and a navigation guard (v2 layout) can prompt before edits are lost.
 *
 * An editor (EventCard, Home's next-run bar) registers a {save, discard, describe} entry
 * keyed by a stable id while it has unsaved changes, and clears it once clean again.
 */
import { untrack } from 'svelte';

export interface PendingEdit {
	/** Stable id for the editor (e.g. `card:<jobId>` or `home-next-run`). */
	id: string;
	/** Current human description of the pending change, for the guard modal. */
	describe: () => string;
	/** Commit the staged change to the backend. May reject. */
	save: () => Promise<void> | void;
	/** Drop the staged change, reverting the editor to server state. */
	discard: () => void;
}

// Module-level reactive state: a plain array, reassigned on every mutation so that
// `$derived`/`$effect` readers in components re-run. Getter functions keep the read
// tracked (same pattern as the other v2 stores).
//
// Mutations are wrapped in `untrack` because editors register/clear from inside an
// `$effect` that watches their dirty flag. Rebuilding the array READS `edits` to write
// it; without untrack the calling effect would take `edits` as a dependency and a fresh
// array reference each run would re-trigger it forever (effect_update_depth_exceeded).
// untrack stops the dependency capture; writes still notify readers (the layout guard).
let edits = $state<PendingEdit[]>([]);

/** Register (or replace) the dirty editor for `edit.id`. */
export function registerPendingEdit(edit: PendingEdit): void {
	untrack(() => {
		edits = [...edits.filter((e) => e.id !== edit.id), edit];
	});
}

/** Remove the editor for `id` (called when it goes clean or unmounts). */
export function clearPendingEdit(id: string): void {
	untrack(() => {
		edits = edits.filter((e) => e.id !== id);
	});
}

/** Drop every registered editor without saving or discarding (test/reset helper). */
export function clearAllPendingEdits(): void {
	untrack(() => {
		edits = [];
	});
}

/** The currently dirty editors. */
export function pendingEdits(): PendingEdit[] {
	return edits;
}

/** Whether any editor has unsaved changes. */
export function hasPendingEdits(): boolean {
	return edits.length > 0;
}

/**
 * Save every pending edit, one at a time. Sequential on purpose: concurrent crontab
 * rewrites can race each other on the host. A successful save clears its entry; if one
 * throws, it propagates (the caller keeps the modal open) and later entries remain.
 */
export async function saveAllPendingEdits(): Promise<void> {
	for (const e of [...edits]) {
		await e.save();
		edits = edits.filter((x) => x.id !== e.id);
	}
}

/** Discard every pending edit, reverting each editor to server state. */
export function discardAllPendingEdits(): void {
	for (const e of [...edits]) e.discard();
	edits = [];
}
