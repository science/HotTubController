# v2 — Open questions / decisions for Steve

Accumulated during the overnight 2026-07-08 review-and-build session (instead of pausing
to ask). Nothing here blocks the current work; defaults were chosen where needed and are
called out so they're easy to reverse.

## Q1. Should `basic` (Guest) keep backend write access to the default target temp?
The Home dial is now Owner/User-only in the UI (F7 in `v2-ux-review.md`), but
`PUT /api/settings/heat-target/temp` still accepts any write role, so a Guest token can
change the household default via the API. Options: (a) leave as-is — UI-only scoping,
backend stays "write roles may write" (current default); (b) restrict the endpoint to
`admin`/`user`. (b) is a one-line guard change + tests, but it's a *backend role-semantics*
change, which the plan explicitly avoided ("no backend changes"), so it needs your call.

## Q2. auto-heat-off in v2 — retire or re-home?
The plan deferred this ("decide in Stage 2"). v2 currently has no auto-heat-off UI. When
heat-to-target mode is ON, its stall detection + target-reached auto-off covers the
"forgot the heater" risk. But with target mode OFF, v2's Heat button is a plain
`heater-on` with no v2-side auto-off. Options: (a) retire the feature (target mode is the
normal state; simplest, current default-by-omission); (b) port the v1 auto-heat-off
preference into a ☰/Preferences surface in Stage 3+. Leaning (a) unless you actually run
with target mode disabled.

## Q3. Header: keep inline `Role · Logout`, or the plan's ☰ menu?
The plan sketches `⚙ ☰` in the header (menu holds Logout + Preferences). With Q2 likely
(a) and Setup owning config, a menu may be over-structure for one item. Current default:
keep the inline header, drop ☰ from the plan. Flip it if Preferences (Q2b) happens.

## Q4. Midnight wrap on time nudges
Nudging a one-off's time is clamped to its calendar date (`oneOffIso` keeps the date,
`shiftClock` wraps mod-24h), so 23:45 + 15min lands at 00:00 *the same day* — ~24h
earlier than intended. Same class of edge on the recurring override (HH:MM on a fixed
skip date). Rare for a ±15-minute nudge on a morning-heat schedule. Worth a
guard/disable at the day boundary, or fine to ignore?

## Q5. Recurring reschedule and DTDT ready-by mode
Implementing the atomic in-place recurring reschedule (Stage 2.5): when the parent is in
`ready_by` mode, I treated the edited HH:MM as the new **ready-by time** and recomputed
the cron start via the existing DTDT path (mirrors what override-next does). If you'd
rather the steppers edit the cron *start* time directly in ready-by mode, say so — the
endpoint keeps both representations, so it's a small change.

## Q6. Setup (Stage 3) decomposition granularity
Plan says decompose the 855-LOC `SettingsPanel` into Heat targets / Sensors / Heating
analysis / Users. If I get to it tonight I'll do the section shells + re-homing
(SensorConfigPanel reuse, users link) and split SettingsPanel only as far as clean seams
allow without behavior change — full decomposition may deserve its own TDD pass in
daylight. Flag if you want it more/less aggressive.
