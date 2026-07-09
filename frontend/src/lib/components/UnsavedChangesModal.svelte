<script lang="ts">
	/**
	 * Guard prompt shown when the user tries to leave an editor (switch card / tab) with
	 * unsaved schedule changes. Two explicit choices — Save & continue, or Discard — plus
	 * an implicit "keep editing": Escape or a backdrop tap cancels the move (onStay), so a
	 * stray click never silently discards work.
	 */
	interface Props {
		open: boolean;
		lines?: string[];
		saveLabel?: string;
		busy?: boolean;
		error?: string | null;
		onSave: () => void;
		onDiscard: () => void;
		onStay: () => void;
	}
	let {
		open,
		lines = [],
		saveLabel = 'Save & continue',
		busy = false,
		error = null,
		onSave,
		onDiscard,
		onStay
	}: Props = $props();

	function onKeydown(event: KeyboardEvent) {
		if (open && event.key === 'Escape') {
			event.preventDefault();
			onStay();
		}
	}
</script>

<svelte:window onkeydown={onKeydown} />

{#if open}
	<div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="unsaved-modal">
		<!-- Backdrop: a real button so dismiss-on-tap is keyboard-reachable and needs no role. -->
		<button
			type="button"
			aria-label="Keep editing"
			onclick={onStay}
			class="absolute inset-0 bg-black/60"
		></button>

		<div
			role="dialog"
			aria-modal="true"
			aria-labelledby="unsaved-modal-title"
			class="relative w-full max-w-sm rounded-xl border border-slate-700 bg-slate-800 p-4 shadow-xl"
		>
			<h2 id="unsaved-modal-title" class="text-sm font-semibold text-slate-100">
				Unsaved schedule change
			</h2>
			<p class="mt-1 text-xs text-slate-400">
				Saving rewrites the schedule on the controller — it can take a moment.
			</p>

			{#if lines.length > 0}
				<ul class="mt-3 flex flex-col gap-1 text-sm text-slate-200">
					{#each lines as line}
						<li data-testid="unsaved-modal-line">{line}</li>
					{/each}
				</ul>
			{/if}

			{#if error}
				<p class="mt-2 text-xs text-red-400" data-testid="unsaved-modal-error">{error}</p>
			{/if}

			<div class="mt-4 flex items-center justify-end gap-2">
				<!-- The safe choice is a visible button, not just Escape/backdrop — on a phone
				     those are undiscoverable and the other two options are both commitments. -->
				<button
					type="button"
					onclick={onStay}
					disabled={busy}
					data-testid="unsaved-stay"
					class="mr-auto rounded-lg px-3 py-1.5 text-sm text-slate-300 hover:text-slate-100 disabled:opacity-40"
					>Keep editing</button
				>
				<button
					type="button"
					onclick={onDiscard}
					disabled={busy}
					data-testid="unsaved-discard"
					class="rounded-lg px-3 py-1.5 text-sm text-slate-400 hover:text-red-300 disabled:opacity-40"
					>Discard</button
				>
				<button
					type="button"
					onclick={onSave}
					disabled={busy}
					data-testid="unsaved-save"
					class="rounded-lg bg-orange-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-orange-500 disabled:opacity-50"
					>{busy ? 'Saving…' : saveLabel}</button
				>
			</div>
		</div>
	</div>
{/if}
