<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { goto } from '$app/navigation';
	import { base } from '$app/paths';
	import UnsavedChangesModal from '$lib/components/UnsavedChangesModal.svelte';
	import { registerPendingEdit, clearPendingEdit } from '$lib/stores/pendingEdits.svelte';
	import CompactControlButton from '$lib/components/CompactControlButton.svelte';
	import EquipmentStatusBar from '$lib/components/EquipmentStatusBar.svelte';
	import TempHero from '$lib/components/TempHero.svelte';
	import { api, type TargetTemperatureState, type ScheduledJob } from '$lib/api';
	import { canControl, canSchedule, canTuneTarget } from '$lib/roles';
	import { foldScheduledEvents, formatNextFire, type LogicalEvent } from '$lib/scheduleUtils';
	import {
		fetchStatus,
		getHeaterOn,
		getPumpOn,
		getBlindsEnabled,
		setHeaterOn,
		setHeaterOff,
		setPumpOn
	} from '$lib/stores/equipmentStatus.svelte';
	import {
		getEnabled as getTargetTempEnabled,
		getTargetTempF,
		getHeatButtonLabel,
		getHeatButtonTooltip,
		getLastStallEvent,
		getDynamicMode,
		getMinTempF,
		getMaxTempF,
		setTargetTempF,
		persistDefaultTargetTemp
	} from '$lib/stores/heatTargetSettings.svelte';

	let { data } = $props();

	// Guest/User/Owner can all act on hardware; read-only never reaches the UI.
	// Gate defensively so the control grid is honest about who may act.
	const canAct = $derived(canControl(data.user?.role));

	// The "Heat now" target dial. Shown only when heat-to-target mode is on and not
	// dynamic (an absolute dial is meaningless against an ambient-derived target).
	// Owner/User only: the dial rewrites the *persistent household default* — a Guest
	// heats to it but doesn't redefine it (roles.canTuneTarget).
	const TEMP_STEP_F = 0.5;
	let targetEnabled = $derived(getTargetTempEnabled());
	let dynamicMode = $derived(getDynamicMode());
	let showDial = $derived(canTuneTarget(data.user?.role) && targetEnabled && !dynamicMode);
	let targetTempF = $derived(getTargetTempF());
	let targetDisplay = $derived(
		Number.isInteger(targetTempF) ? `${targetTempF}` : targetTempF.toFixed(2).replace(/\.?0+$/, '')
	);
	let persistTimer: ReturnType<typeof setTimeout> | null = null;

	// Re-fetch the projected ETA so the "ready by" line reflects the dialed target.
	// computeProjectedEta() reads the saved default, which persistDefaultTargetTemp just wrote.
	function refreshEta() {
		if (!getTargetTempEnabled()) return;
		api
			.getTargetTempStatus()
			.then((s) => (heatTargetEta = s))
			.catch(() => {});
	}

	// Nudge the target: update locally at once (label/ETA/handleHeatOn all read the store),
	// then debounce-persist the saved default and refresh the ETA.
	function nudgeTarget(deltaF: number) {
		const next = setTargetTempF(getTargetTempF() + deltaF);
		if (persistTimer) clearTimeout(persistTimer);
		persistTimer = setTimeout(() => {
			persistDefaultTargetTemp(next)
				.then(refreshEta)
				.catch(() => {
					status = {
						message: "Couldn't save the target — it still applies to the next heat.",
						type: 'error'
					};
					setTimeout(() => (status = null), 3000);
				});
		}, 500);
	}

	let heaterOn = $derived(getHeaterOn());
	let pumpOn = $derived(getPumpOn());
	let blindsEnabled = $derived(getBlindsEnabled());

	// ── Next up ────────────────────────────────────────────────────────────────
	// Upcoming events, folded so each recurring event (with or without an override)
	// is ONE card. Display is for everyone; the adjust controls are User/Owner only.
	let scheduledJobs = $state<ScheduledJob[]>([]);
	const events = $derived(foldScheduledEvents(scheduledJobs));
	const canSched = $derived(canSchedule(data.user?.role));

	// Selection is keyed by the *stable* logical-event key (the recurring parent id),
	// so it survives the override churn instead of re-binding to whatever sorts to the top.
	let selectedKey = $state<string | null>(null);
	const selected = $derived(events.find((e) => e.key === selectedKey) ?? null);
	let adjusting = $state(false);

	// Keep a valid selection: if the selected card disappears, fall back to the soonest
	// adjustable event so the common case ("tweak the next run") needs no extra tap.
	$effect(() => {
		if (!canSched) return;
		if (selectedKey && events.some((e) => e.key === selectedKey)) return;
		const firstAdjustable = events.find((e) => e.adjustable);
		selectedKey = firstAdjustable?.key ?? events[0]?.key ?? null;
	});

	const TEMP_MIN = 80;
	const TEMP_MAX = 110;
	const clampTemp = (t: number) => Math.min(TEMP_MAX, Math.max(TEMP_MIN, t));
	const pad2 = (n: number) => String(n).padStart(2, '0');

	function formatTemp(t: number): string {
		return Number.isInteger(t) ? `${t}` : t.toFixed(2).replace(/\.?0+$/, '');
	}
	function formatClock(hhmm: string): string {
		const [h, m] = hhmm.split(':').map(Number);
		const d = new Date();
		d.setHours(h, m, 0, 0);
		return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
	}
	function shiftClock(hhmm: string, deltaMin: number): string {
		const [h, m] = hhmm.split(':').map(Number);
		const total = (h * 60 + m + deltaMin + 1440) % 1440;
		return `${pad2(Math.floor(total / 60))}:${pad2(total % 60)}`;
	}
	// Recompose a one-off's instant from its existing calendar date + a new local HH:MM, so a
	// Home nudge moves it in place via rescheduleOneOff. Nudges stay within the day; a larger
	// move belongs on the Schedule tab.
	function oneOffIso(job: ScheduledJob, clock: string): string {
		const d = new Date(job.scheduledTime);
		const [h, m] = clock.split(':').map(Number);
		d.setHours(h, m, 0, 0);
		return d.toISOString();
	}

	const ACTION_LABELS: Record<string, string> = {
		'heater-on': 'Heater on',
		'heater-off': 'Heater off',
		'pump-run': 'Run pump'
	};
	function jobTitle(job: ScheduledJob): string {
		if (job.action === 'heat-to-target' && job.params?.target_temp_f != null) {
			const tilde = getDynamicMode() ? '~' : '';
			return `Heat to ${tilde}${formatTemp(job.params.target_temp_f)}°F`;
		}
		return ACTION_LABELS[job.action] ?? job.action;
	}

	// Clock time of a job: ready-by time, the recurring HH:MM, else the one-off's local clock.
	function jobClock(job: ScheduledJob): string {
		if (job.params?.ready_by_time) return job.params.ready_by_time;
		if (job.recurring) return job.scheduledTime;
		const d = new Date(job.scheduledTime);
		return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
	}
	const eventTemp = (e: LogicalEvent) => e.job.params?.target_temp_f ?? getTargetTempF();

	// "6:55 AM · 102.25°F" — the everyday default that an override leaves untouched.
	function baseSummary(e: LogicalEvent): string {
		const parts = [formatClock(jobClock(e.baseJob))];
		if (e.baseJob.params?.target_temp_f != null) {
			parts.push(`${formatTemp(e.baseJob.params.target_temp_f)}°F`);
		}
		return parts.join(' · ');
	}
	function resumeLabel(iso?: string): string {
		if (!iso) return '';
		// Use the date part — skip/resume timestamps carry a misleading +00:00 offset.
		const [y, mo, d] = iso.slice(0, 10).split('-').map(Number);
		return new Date(y, mo - 1, d).toLocaleDateString(undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric'
		});
	}
	const skipLabel = (e: LogicalEvent) => resumeLabel(e.baseJob.skipDate);

	async function reloadJobs() {
		try {
			const r = await api.listScheduledJobs();
			scheduledJobs = r.jobs;
		} catch {
			/* keep the last list */
		}
	}

	function failToast(message: string) {
		status = { message, type: 'error' };
		setTimeout(() => (status = null), 3000);
	}

	// Staged next-run edit for the selected card. The ± steppers mutate this draft; an
	// explicit Save commits it as ONE override (skip + one-off) — we never auto-send each
	// tap, since an override rewrites the crontab (slow on the host). See pendingEdits.svelte.
	let draftClock = $state<string | null>(null);
	let draftTemp = $state<number | null>(null);

	// Re-seed the draft whenever the selection (or its underlying job) changes, so switching
	// cards or reloading after Save starts clean. Reads `selected` only — mutating the draft
	// can't feed back into this effect.
	$effect(() => {
		const e = selected;
		draftClock = e ? jobClock(e.job) : null;
		draftTemp = e ? eventTemp(e) : null;
	});

	const homeDirty = $derived(
		!!selected &&
			selected.adjustable &&
			!selected.skipped &&
			(draftClock !== jobClock(selected.job) || draftTemp !== eventTemp(selected))
	);

	function draftSummary(): string {
		const e = selected;
		if (!e) return 'Next run change';
		const tail = draftTemp != null ? ` · ${formatTemp(draftTemp)}°F` : '';
		return `${jobTitle(e.job)} → ${formatClock(draftClock ?? jobClock(e.job))}${tail}`;
	}

	// All actions operate on the SELECTED event's stable key — never a sort position.
	// ± taps mutate the local draft only; nothing hits the backend until Save.
	function adjustSelected(deltaMin: number, deltaTemp: number) {
		const e = selected;
		if (!e || !e.adjustable || e.skipped) return;
		if (deltaMin) draftClock = shiftClock(draftClock ?? jobClock(e.job), deltaMin);
		if (deltaTemp) draftTemp = clampTemp((draftTemp ?? eventTemp(e)) + deltaTemp);
	}

	async function saveSelected() {
		const e = selected;
		if (!e || !homeDirty || adjusting) return;
		adjusting = true;
		try {
			if (e.recurring) {
				// Skip the next recurrence + write a one-off at the new time/temp (atomic server-side).
				await api.overrideNextOccurrence(e.key, draftClock!, draftTemp!);
			} else {
				// A one-off has no recurrence to override — move it in place, preserving its id.
				await api.rescheduleOneOff(e.key, oneOffIso(e.job, draftClock!), draftTemp ?? undefined);
			}
			await reloadJobs(); // reload → reseed effect → draft goes clean
		} catch (err) {
			failToast("Couldn't adjust the next run. Try again.");
			throw err; // let the guard's Save-all halt navigation when a save fails
		} finally {
			adjusting = false;
		}
	}

	function discardSelected() {
		const e = selected;
		draftClock = e ? jobClock(e.job) : null;
		draftTemp = e ? eventTemp(e) : null;
	}

	// Register the next-run draft while dirty so the tab-switch guard can prompt before it's
	// lost. Tracks `homeDirty` (a boolean edge), not every tap.
	$effect(() => {
		if (homeDirty) {
			registerPendingEdit({
				id: 'home-next-run',
				describe: draftSummary,
				save: saveSelected,
				discard: discardSelected
			});
		} else {
			clearPendingEdit('home-next-run');
		}
	});
	onDestroy(() => clearPendingEdit('home-next-run'));

	// Switching the selected card while the draft is dirty would lose it — prompt first.
	let switchModalOpen = $state(false);
	let switchBusy = $state(false);
	let switchError = $state<string | null>(null);
	let pendingKey = $state<string | null>(null);
	const switchLines = $derived(homeDirty ? [draftSummary()] : []);

	function requestSelect(key: string) {
		if (key === selectedKey) return;
		if (homeDirty) {
			pendingKey = key;
			switchError = null;
			switchModalOpen = true;
			return;
		}
		selectedKey = key;
	}

	async function switchSave() {
		switchBusy = true;
		switchError = null;
		try {
			await saveSelected();
			selectedKey = pendingKey;
			switchModalOpen = false;
			pendingKey = null;
		} catch {
			switchError = "Couldn't save the change. Try again.";
		} finally {
			switchBusy = false;
		}
	}

	function switchDiscard() {
		discardSelected();
		selectedKey = pendingKey;
		switchModalOpen = false;
		pendingKey = null;
	}

	function switchStay() {
		switchModalOpen = false;
		pendingKey = null;
		switchError = null;
	}

	async function skipSelected() {
		const e = selected;
		// Skip applies to a recurrence only; a one-off has nothing to skip to.
		if (!e || !e.adjustable || !e.recurring || adjusting) return;
		adjusting = true;
		try {
			await api.clearOverrideNext(e.key).catch(() => {}); // drop any override (also unskips)
			await api.skipScheduledJob(e.key); // then skip the next run
			await reloadJobs();
		} catch {
			failToast("Couldn't skip the next run. Try again.");
		} finally {
			adjusting = false;
		}
	}

	async function resetSelected() {
		const e = selected;
		if (!e || !e.overridden || adjusting) return;
		adjusting = true;
		try {
			await api.clearOverrideNext(e.key);
			await reloadJobs();
		} catch {
			failToast("Couldn't reset to the daily default. Try again.");
		} finally {
			adjusting = false;
		}
	}

	async function resumeSelected() {
		const e = selected;
		if (!e || !e.skipped || adjusting) return;
		adjusting = true;
		try {
			await api.unskipScheduledJob(e.key);
			await reloadJobs();
		} catch {
			failToast("Couldn't resume the next run. Try again.");
		} finally {
			adjusting = false;
		}
	}

	let status = $state<{ message: string; type: 'success' | 'error' } | null>(null);
	let stallBannerDismissed = $state(false);
	let lastStallEvent = $derived(stallBannerDismissed ? null : getLastStallEvent());

	// Estimated time to reach the heat-to-target temperature (projected or active).
	let heatTargetEta = $state<TargetTemperatureState | null>(null);
	let etaDisplay = $derived.by(() => {
		if (!heatTargetEta?.eta) return null;
		const eta = heatTargetEta.eta;
		const etaDate = new Date(eta.eta_timestamp);
		const timeStr = etaDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
		return {
			targetTempF: eta.target_temp_f,
			time: timeStr,
			minutesRemaining: Math.round(eta.minutes_remaining),
			projected: eta.projected
		};
	});

	onMount(() => {
		fetchStatus();
		reloadJobs();
	});

	// Poll heat-to-target status while target mode is enabled. Reading heaterOn makes the
	// effect re-fire on heater changes so the ETA flips between projected and active.
	$effect(() => {
		const _heaterState = heaterOn; // tracked dependency
		if (!getTargetTempEnabled()) {
			heatTargetEta = null;
			return;
		}
		api
			.getTargetTempStatus()
			.then((s) => (heatTargetEta = s))
			.catch(() => {});
		const interval = setInterval(() => {
			api
				.getTargetTempStatus()
				.then((s) => (heatTargetEta = s))
				.catch(() => {});
		}, 60_000);
		return () => clearInterval(interval);
	});

	async function handleAction(
		action: () => Promise<unknown>,
		successMsg: string,
		onSuccess?: () => void
	) {
		try {
			await action();
			status = { message: successMsg, type: 'success' };
			onSuccess?.();
		} catch (e) {
			if (e instanceof Error && e.message === 'Unauthorized') {
				goto(`${base}/login`);
				return;
			}
			status = { message: 'Action failed. Try again.', type: 'error' };
		}
		setTimeout(() => (status = null), 3000);
	}

	async function handleHeatOn() {
		if (getTargetTempEnabled()) {
			const targetTempF = getTargetTempF();
			await handleAction(
				() => api.heatToTarget(targetTempF),
				`Heating to ${targetTempF}°F`,
				setHeaterOn
			);
		} else {
			await handleAction(api.heaterOn, 'Heater on', setHeaterOn);
		}
	}
</script>

{#snippet repeatIcon()}
	<svg
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		stroke-width="2"
		stroke-linecap="round"
		stroke-linejoin="round"
		class="h-3.5 w-3.5 shrink-0 text-slate-500"
		aria-hidden="true"
	>
		<path d="m17 2 4 4-4 4" /><path d="M3 11v-1a4 4 0 0 1 4-4h14" /><path d="m7 22-4-4 4-4" /><path
			d="M21 13v1a4 4 0 0 1-4 4H3"
		/>
	</svg>
{/snippet}

{#snippet eventRow(e: LogicalEvent)}
	<div class="flex items-start justify-between gap-3">
		<div class="min-w-0">
			<p
				class="flex items-center gap-1.5 truncate font-semibold text-slate-100"
				data-testid="next-card-title"
			>
				{jobTitle(e.job)}
				{#if e.recurring}<span title="Repeats daily">{@render repeatIcon()}</span>{/if}
			</p>
			<p class="text-sm text-slate-400">{formatNextFire(e.nextFire)}</p>
			{#if e.overridden}
				<p class="mt-0.5 text-xs text-orange-300/80" data-testid="next-card-resets">
					resets to {baseSummary(e)} daily
				</p>
			{:else if e.skipped}
				<p class="mt-0.5 text-xs text-amber-300/80" data-testid="next-card-skip-info">
					skips {skipLabel(e)}
				</p>
			{/if}
		</div>
		{#if e.overridden}
			<span
				class="shrink-0 rounded-full bg-orange-500/15 px-2 py-0.5 text-xs text-orange-300"
				data-testid="next-card-adjusted">adjusted</span
			>
		{:else if e.skipped}
			<span class="shrink-0 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs text-amber-300"
				>skipped</span
			>
		{/if}
	</div>
{/snippet}

<section data-testid="v2-home" class="flex flex-col gap-3">
	<!-- Temperature hero -->
	<TempHero />

	<!-- Primary controls: heat now / off / pump -->
	{#if canAct}
		<div class="grid grid-cols-3 gap-2">
			<CompactControlButton
				label={getHeatButtonLabel()}
				icon="flame"
				variant="primary"
				tooltip={getHeatButtonTooltip()}
				active={heaterOn}
				onClick={handleHeatOn}
			/>
			<CompactControlButton
				label="Heat/Pump Off"
				icon="flame-off"
				variant="secondary"
				tooltip="Turn off the heater and pump"
				active={!heaterOn}
				onClick={() => handleAction(api.heaterOff, 'Heater and pump off', setHeaterOff)}
			/>
			<CompactControlButton
				label="Pump (2h)"
				icon="refresh"
				variant="tertiary"
				tooltip="Run the circulation pump for 2 hours"
				active={pumpOn}
				onClick={() => handleAction(api.pumpRun, 'Pump running for 2 hours', setPumpOn)}
			/>
		</div>
	{/if}

	<!-- Heat-now target dial (heat-to-target mode, non-dynamic) -->
	{#if showDial}
		<div class="flex items-center justify-center gap-3" data-testid="target-dial">
			<span class="text-sm text-slate-400">Target</span>
			<button
				type="button"
				aria-label="Lower target temperature"
				onclick={() => nudgeTarget(-TEMP_STEP_F)}
				disabled={targetTempF <= getMinTempF()}
				class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-800 text-xl leading-none text-slate-200 transition-colors hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800"
			>
				&minus;
			</button>
			<span
				class="min-w-[5.5rem] text-center text-lg font-semibold tabular-nums text-slate-100"
				data-testid="target-value"
			>
				{targetDisplay}&deg;F
			</span>
			<button
				type="button"
				aria-label="Raise target temperature"
				onclick={() => nudgeTarget(TEMP_STEP_F)}
				disabled={targetTempF >= getMaxTempF()}
				class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-800 text-xl leading-none text-slate-200 transition-colors hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800"
			>
				+
			</button>
		</div>
	{/if}

	<!-- ETA to target temperature -->
	{#if etaDisplay}
		{#if etaDisplay.projected}
			<div class="text-center text-sm text-slate-400" data-testid="eta-display">
				Heat now &rarr; {etaDisplay.targetTempF}°F by {etaDisplay.time}
				<span class="text-xs text-slate-500">({etaDisplay.minutesRemaining} min)</span>
			</div>
		{:else}
			<div class="text-center text-sm text-orange-400/80" data-testid="eta-display">
				Target {etaDisplay.targetTempF}°F by {etaDisplay.time}
				<span class="text-xs text-slate-500">({etaDisplay.minutesRemaining} min)</span>
			</div>
		{/if}
	{/if}

	<!-- Dining room blinds (optional feature) -->
	{#if blindsEnabled && canAct}
		<div class="grid grid-cols-2 gap-2">
			<CompactControlButton
				label="Blinds Up"
				icon="blinds-open"
				variant="accent"
				size="compact"
				tooltip="Open the dining room blinds"
				onClick={() => handleAction(api.blindsOpen, 'Blinds opening')}
			/>
			<CompactControlButton
				label="Blinds Down"
				icon="blinds-close"
				variant="accent"
				size="compact"
				tooltip="Close the dining room blinds"
				onClick={() => handleAction(api.blindsClose, 'Blinds closing')}
			/>
		</div>
	{/if}

	<!-- Next up: folded events. Select a card; the control bar above acts on that card. -->
	{#if events.length > 0}
		<section class="flex flex-col gap-2" data-testid="v2-next-up">
			<h2 class="text-xs uppercase tracking-wide text-slate-400">Next up</h2>

			{#if canSched && selected}
				<!-- Control bar — bound to the SELECTED card by its stable key, not its position. -->
				<div
					class="rounded-xl border border-slate-700 bg-slate-800/50 p-3"
					data-testid="next-controls"
				>
					<p class="text-xs text-slate-400">
						{selected.recurring ? 'Adjusting just the next run' : 'Adjusting this one-time event'} ·
						<span class="text-slate-200" data-testid="next-controls-target"
							>{jobTitle(selected.job)} · {formatNextFire(selected.nextFire)}</span
						>
					</p>

					{#if selected.skipped}
						<div class="mt-2 flex items-center gap-3">
							<span class="text-xs text-amber-300"
								>Skipped — resumes {resumeLabel(selected.resumeDate)}</span
							>
							<button
								type="button"
								onclick={resumeSelected}
								disabled={adjusting}
								data-testid="next-resume"
								class="ml-auto rounded-lg border border-amber-600/50 px-3 py-1 text-sm text-amber-300 hover:bg-amber-600/10 disabled:opacity-40"
								>Resume next run</button
							>
						</div>
					{:else if selected.adjustable}
						<div class="mt-2 grid grid-cols-2 gap-2">
							<div
								class="flex items-center justify-between rounded-lg bg-slate-900/50 px-2 py-1.5"
							>
								<button
									type="button"
									aria-label="15 minutes earlier"
									onclick={() => adjustSelected(-15, 0)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>&minus;</button
								>
								<span class="text-xs text-slate-400"
									>{formatClock(draftClock ?? jobClock(selected.job))}</span
								>
								<button
									type="button"
									aria-label="15 minutes later"
									onclick={() => adjustSelected(15, 0)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>+</button
								>
							</div>
							<div
								class="flex items-center justify-between rounded-lg bg-slate-900/50 px-2 py-1.5"
							>
								<button
									type="button"
									aria-label="half a degree cooler"
									onclick={() => adjustSelected(0, -0.5)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>&minus;</button
								>
								<span class="text-xs text-slate-400">{formatTemp(draftTemp ?? eventTemp(selected))}°F</span>
								<button
									type="button"
									aria-label="half a degree warmer"
									onclick={() => adjustSelected(0, 0.5)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>+</button
								>
							</div>
						</div>
						{#if homeDirty}
							<div class="mt-2 flex items-center gap-3 text-xs">
								<span class="text-orange-300/80"
									>{selected.recurring
										? 'Unsaved — Save rewrites the schedule'
										: 'Unsaved — Save moves this event'}</span
								>
								<button
									type="button"
									onclick={discardSelected}
									disabled={adjusting}
									data-testid="next-discard"
									class="ml-auto rounded-lg px-2 py-1 text-slate-400 hover:text-slate-200 disabled:opacity-40"
									>Discard</button
								>
								<button
									type="button"
									onclick={saveSelected}
									disabled={adjusting}
									data-testid="next-save"
									class="rounded-lg bg-orange-600 px-3 py-1 font-medium text-white hover:bg-orange-500 disabled:opacity-50"
									>{adjusting ? 'Saving…' : 'Save'}</button
								>
							</div>
						{:else if selected.recurring}
							<div class="mt-2 flex items-center gap-3 text-xs">
								<button
									type="button"
									onclick={skipSelected}
									disabled={adjusting}
									data-testid="next-skip"
									class="text-slate-400 hover:text-amber-300 disabled:opacity-40"
									>Skip next run</button
								>
								{#if selected.overridden}
									<button
										type="button"
										onclick={resetSelected}
										disabled={adjusting}
										data-testid="next-reset"
										class="text-slate-400 hover:text-slate-200 disabled:opacity-40"
										>Reset to daily</button
									>
								{/if}
								<span class="ml-auto text-slate-500">default stays {baseSummary(selected)}</span>
							</div>
						{:else}
							<p class="mt-2 text-xs text-slate-500" data-testid="next-oneoff-hint">
								One-time event — moves in place when you save.
							</p>
						{/if}
					{:else}
						<p class="mt-1 text-xs text-slate-500">
							One-time event — edit it on the Schedule tab.
						</p>
					{/if}
				</div>
			{/if}

			<!-- Cards: selectable (radio-like) for schedulers, plain display for Guests. -->
			<ul class="flex flex-col gap-2">
				{#each events as e (e.key)}
					<li>
						{#if canSched}
							<button
								type="button"
								onclick={() => requestSelect(e.key)}
								aria-pressed={selectedKey === e.key}
								data-testid="next-card"
								data-key={e.key}
								class="w-full rounded-xl border p-3 text-left transition-colors {selectedKey ===
								e.key
									? 'border-orange-500/70 bg-slate-800'
									: 'border-slate-700 bg-slate-800/40 hover:border-slate-600'}"
							>
								{@render eventRow(e)}
							</button>
						{:else}
							<div
								class="rounded-xl border border-slate-700 bg-slate-800/40 p-3"
								data-testid="next-card"
								data-key={e.key}
							>
								{@render eventRow(e)}
							</div>
						{/if}
					</li>
				{/each}
			</ul>
		</section>
	{/if}

	<!-- Equipment status -->
	<EquipmentStatusBar />

	<!-- Stall warning -->
	{#if lastStallEvent}
		<div
			data-testid="stall-warning-banner"
			class="flex items-start gap-2 rounded-lg border border-red-500/50 bg-red-500/20 p-3"
		>
			<div class="flex-1">
				<p class="text-sm font-medium text-red-400">Heating stalled</p>
				<p class="mt-0.5 text-xs text-red-300">
					Stalled at {lastStallEvent.current_temp_f.toFixed(1)}°F (target: {lastStallEvent.target_temp_f}°F)
					— heater turned off
				</p>
				<p class="mt-0.5 text-xs text-red-400/60">
					{new Date(lastStallEvent.timestamp).toLocaleString()}
				</p>
			</div>
			<button
				type="button"
				aria-label="Dismiss stall warning"
				onclick={() => (stallBannerDismissed = true)}
				class="px-1 text-lg leading-none text-red-400 hover:text-red-300">&times;</button
			>
		</div>
	{/if}

	<!-- Action result: fixed above the tab bar so feedback is visible no matter how
	     far the page has scrolled (the buttons it confirms live at the top). -->
	{#if status}
		<div class="pointer-events-none fixed inset-x-0 bottom-16 z-40 flex justify-center px-4">
			<div
				data-testid="status-toast"
				role="status"
				class="rounded-full border px-4 py-1.5 text-sm font-medium shadow-lg {status.type ===
				'success'
					? 'border-green-500/40 bg-slate-800 text-green-400'
					: 'border-red-500/40 bg-slate-800 text-red-400'}"
			>
				{status.message}
			</div>
		</div>
	{/if}

	<!-- Prompt before switching the selected card abandons an unsaved next-run edit. -->
	<UnsavedChangesModal
		open={switchModalOpen}
		lines={switchLines}
		busy={switchBusy}
		error={switchError}
		onSave={switchSave}
		onDiscard={switchDiscard}
		onStay={switchStay}
	/>
</section>
