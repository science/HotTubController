<script lang="ts">
	import SettingsPanel from '$lib/components/SettingsPanel.svelte';
	import SensorConfigPanel from '$lib/components/SensorConfigPanel.svelte';
	import { base } from '$app/paths';
	import { canConfigure } from '$lib/roles';

	/**
	 * Stage 3 (shell): Owner-only Setup tab. Re-homes the existing admin surfaces —
	 * SettingsPanel (heat targets, active job, heating analysis, preferences) and
	 * SensorConfigPanel — under labeled sections, plus the Users page link. The full
	 * decomposition of SettingsPanel into focused sub-sections is staged work
	 * (see docs/redesign/v2-interface.md Stage 3); this ships the owner surface
	 * without forking the components v1 still renders.
	 */
	let { data } = $props();
	const allowed = $derived(canConfigure(data.user?.role));
</script>

<section data-testid="v2-setup" class="flex flex-col gap-3">
	<h2 class="text-sm uppercase tracking-wide text-slate-300">Setup</h2>

	{#if !allowed}
		<p class="text-sm text-slate-500" data-testid="setup-denied">
			Setup is for the Owner. Ask them to change system settings.
		</p>
	{:else}
		<section class="flex flex-col gap-2" data-testid="setup-heat-targets">
			<h3 class="text-xs uppercase tracking-wide text-slate-400">Heat targets &amp; system</h3>
			<SettingsPanel isAdmin={true} />
		</section>

		<section class="flex flex-col gap-2" data-testid="setup-sensors">
			<h3 class="text-xs uppercase tracking-wide text-slate-400">Sensors</h3>
			<SensorConfigPanel />
		</section>

		<section class="flex flex-col gap-2" data-testid="setup-users">
			<h3 class="text-xs uppercase tracking-wide text-slate-400">Users</h3>
			<a
				href="{base}/users"
				data-testid="setup-users-link"
				class="rounded-xl border border-slate-700 bg-slate-800/50 p-3 text-sm text-slate-200 transition-colors hover:border-slate-600"
			>
				Manage users &rarr;
			</a>
		</section>
	{/if}
</section>
