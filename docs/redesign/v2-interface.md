# Hot Tub Controller — v2 Interface: Staged UX/Product Plan

> Design reference for the v2 interface redesign. Built incrementally on
> `feature/v2-interface`, MVP-first, with a verification gate after every stage.

## Context

The UI grew incrementally during development. That was a strength then; now the app is
stable and the single-screen, *subtractive-role* design shows classic MVP debt: everything
on one long scroll, fuzzy role boundaries, overlapping/dead features. The backend APIs are
mature, tested, and stable.

**Goal:** re-architect the *interface only* into an intentional, role-aware tabbed app,
preserving all working logic.

### Decisions

- v2 = a new **tabbed app (Home / Schedule / Setup)** over the **same backend APIs**.
- **Keep the dark-slate look** — IA/structure re-architecture, *not* a visual redesign.
- **Three UI roles: Owner / User / Guest**, plus **Read-only** as an **API-only**
  integration token (Home Assistant) with **no UI surface**. All four map to the existing
  backend roles (`admin` / `user` / `basic` / `readonly`), so **no backend changes**.
- **Reuse** the data/logic layer and most component internals; rebuild only IA, navigation,
  and role gating. Build **alongside** the current UI under a `v2` route group; **cut over
  at parity**.

### Role mapping (no backend changes)

| v2 label | Backend role | Capabilities |
|---|---|---|
| **Owner** | `admin` | Everything, incl. user management + system config |
| **User** | `user` | Manual control + blinds + scheduling; no Setup/users |
| **Guest** | `basic` | Immediate heater on/off + blinds + see temp; **no scheduling** |
| **Read-only** | `readonly` | **API only** — integration token (Home Assistant); **no UI** |

Read-only never enters the web UI: those tokens are minted long-lived via
`backend/bin/mint-jwt.php` with password login disabled, and the backend already 403s every
write (`AuthMiddleware` + the global non-GET guard in `backend/public/index.php`). So **the
v2 frontend is designed for Owner / User / Guest only**.

---

## Product / UX spec

### Information architecture & navigation

- **Bottom tab bar**, tabs filtered by role (three UI roles; read-only has no UI):
  - Guest → **Home** · User → **Home, Schedule** · Owner → **Home, Schedule, Setup**
- **Menu (☰):** Logout (all); Preferences (User+); Owner's Users lives inside **Setup**.
- **Organizing idea: Home = now · Schedule = later · Setup = configure.** This fixes today's
  three overlapping heat entry points (Heat button, QuickSchedule presets, Schedule form) —
  now there is exactly one "heat now" (Home) and one "heat later" (Schedule).

### Screens

**Home** (Guest, User, Owner):
```
┌───────────────────────────┐
│ ●live   Hot Tub     ⚙  ☰  │
├───────────────────────────┤
│        ╭─────────╮        │
│        │  102°   │ water  │   temp hero (reuse TemperaturePanel
│        ╰─────────╯        │   data + staleness logic)
│      air 58°  ·  2m ago   │
│   ┌───────────────────┐   │
│   │   ◉ HEAT to 104   │   │   single Heat button, label via
│   └───────────────────┘   │   getHeatButtonLabel()
│   ┌──────┐   ┌────────┐   │
│   │ Off  │   │  Pump  │   │
│   └──────┘   └────────┘   │
│   Blinds    ▲ up   ▼ down │   shown when blindsEnabled
├───────────────────────────┤
│  Home   Schedule   Setup* │   *Setup = Owner only
└───────────────────────────┘
```
States: **loading**; **stale** (≥10 min → amber, "Last reading 14 min ago", not an
apology); **error** ("Couldn't reach the tub. Check power and try again.").

**Schedule** (User, Owner): reuse `SchedulePanel` — one-off + recurring, skip/unskip,
inline target-temp edit. Empty state: "No heating scheduled. Add a time to heat
automatically." QuickSchedule is gone.

**Setup** (Owner only): decompose the 855-LOC `SettingsPanel` into focused sub-sections —
**Heat targets** (static/dynamic + ambient calibration curve), **Sensors** (reuse
`SensorConfigPanel`), **Heating analysis** (blackbody/heating-characteristics), **Users**
(reuse the `users` page).

### Copy / voice

- Consistent verbs across an action: button "Heat to 104°F" → toast "Heating to 104°F";
  "Off" → "Heater and pump off".
- Errors say what happened and how to fix it, in the interface's voice. Empty states invite
  the next action.

### Visual & components

- Keep the dark-slate palette and existing panel/button styling
  (`bg-slate-800/50 rounded-xl border border-slate-700`). The only visual-adjacent change is
  a DRY cleanup: merge the duplicated button variant/color objects into **one token module**
  (`ControlButton` is being deleted; `CompactControlButton`'s variants become the source of
  truth).
- Quality floor: phone-first responsive, visible keyboard focus, reduced-motion respected,
  labeled/reachable tab bar.

### Role → tab / capability matrix

| Capability | Owner | User | Guest |
|---|:--:|:--:|:--:|
| See temp / equipment status | ✓ | ✓ | ✓ |
| Heater on/off, Pump, Blinds | ✓ | ✓ | ✓ |
| **Home** tab | ✓ | ✓ | ✓ |
| **Schedule** tab | ✓ | ✓ | — |
| **Setup** tab + manage users | ✓ | — | — |

*Read-only is omitted — API-only integration token with no UI; enforced/tested server-side.*

### Reuse / rebuild / delete

- **Keep as-is (data + logic):** `lib/api.ts`, `lib/stores/*` (+ new capability helpers in
  `auth.svelte.ts`), `lib/scheduleUtils.ts`, `lib/autoHeatOff.ts`, `lib/settings.ts`,
  `lib/config.ts`.
- **Keep, re-home:** `CompactControlButton`, `EquipmentStatusBar`, `SensorConfigPanel`,
  `SettingsPanel` (decomposed). `TemperaturePanel` stays for v1; v2 Home uses the new
  `TempHero` (v1 panel's sensor detail moves to Setup).
- **New:** v2 layout shell + tab bar, `Home/Schedule/Setup` routes, role-capability helpers
  (`lib/roles.ts`), `EventCard`, `TempHero`, `pendingEdits` store + `UnsavedChangesModal`,
  shared button-token module.
- **Delete at cutover:** `QuickSchedulePanel.svelte` (dead), `ControlButton.svelte`
  (superseded), `SchedulePanel.svelte` (superseded by the card-based Schedule tab).
- **Decide in Stage 2:** auto-heat-off (overlaps heat-to-target's own auto-off).

---

## Staged implementation (MVP-first — STOP and verify after each stage)

Each stage is TDD red/green per `CLAUDE.md`; tests run **only** via `./scripts/test.sh`. The
old UI stays live until Stage 5.

> **Status (2026-07-08):** Stages 0–2 are ✅ done (with deliberate deviations noted
> below). Stage 2.5 (card parity) is the active work. Stages 3–5 remain. A UX review of
> the shipped stages lives in `v2-ux-review.md`; open decisions in `v2-open-questions.md`.

### Stage 0 — Branch + scaffold ✅
- Create `feature/v2-interface`; commit this doc.
- v2 route group `frontend/src/routes/v2/` (respect `/tub` base): `+layout.svelte` (tab shell
  + role gating) and an empty Home page.
- Role-capability helpers landed as a standalone pure module `lib/roles.ts` (not inside
  `auth.svelte.ts` as first sketched) with unit tests — works with both the layout `data.user`
  and the auth store.
- **Verify gate:** `/tub/v2` renders the tab shell; the existing UI is untouched;
  `./scripts/test.sh` green.

### Stage 1 — MVP: Home ✅ (+ Stage 1.5)
- Build Home by composing existing pieces: temperature hero, `CompactControlButton`
  (Heat/Off/Pump + blinds), `equipmentStatus` + `heatTargetSettings` stores, and the ETA +
  stall logic lifted from today's `+page.svelte`.
- **Stage 1.5 (added):** Home "Heat now" target dial (± the saved default target temp) backed
  by a new write-level endpoint `PUT /api/settings/heat-target/temp` (all other heat-target
  config stays admin-only). Dial is Owner/User in the UI (see review F7 / question Q1).
- **Verify gate:** sign in as Owner/User/Guest → Home shows temps + controls; Heat/Off/Pump
  work in stub mode. `./scripts/test.sh` green. ✅

### Stage 2 — Schedule (User+) ✅ (deviated from sketch, deliberately)
- Planned as "reuse `SchedulePanel`"; shipped instead as a **card-based Schedule tab**
  (`EventCard`) plus a Home **"Next up"** zone — richer than the plan:
  - `foldScheduledEvents`: a recurring parent + its next-run override render as ONE logical
    card keyed by the stable parent id.
  - New atomic endpoints: `POST/DELETE /api/schedule/{id}/override-next` ("adjust just the
    next run", mode-aware) and `PUT /api/schedule/{id}/reschedule` (move a one-off in place,
    id preserved).
  - **Staged edits**: ± steppers mutate a local draft; explicit Save commits ONE backend
    call (crontab rewrites are slow on the host). A `pendingEdits` registry + navigation
    guard (`UnsavedChangesModal`) prevents silent loss.
  - QuickSchedule is gone from v2 as planned. auto-heat-off decision still open (Q2).
- **Verify gate:** User creates one-off + recurring jobs; Guest has no Schedule tab; tests
  green. ✅

### Stage 2.5 — Card parity (recurring ⇄ one-off) ← ACTIVE
- Backend (TDD): atomic **in-place recurring reschedule** — change a recurring job's daily
  time (+ optional temp) preserving its id, mirroring `rescheduleOneOff`. Must rewrite the
  daily cron via `CronSchedulingService`/`scheduleDailyInTimezone`, keep the healthcheck
  cron in sync, and honor DTDT ready-by (edited HH:MM = new ready-by time; see Q5).
  Rejected recreate-based route: a failed recreate could drop the schedule and orphan
  Home's override folding.
- Frontend: `api.rescheduleRecurring`; `EventCard` renders the SAME ± time/temp steppers +
  Save/Discard for recurring heat-to-target as for one-offs (recurring differs only by the
  repeat indicator + Skip next). "Edit temp" input retires.
- Also fold Home's adjust-bar into the selected card (review F8) so Home and Schedule share
  one expanded-card interaction.
- **Verify gate:** recurring daily time/temp edit round-trips; skip/override flows intact;
  tests green. **STOP.**

### Stage 3 — Setup (Owner)
- `v2/setup` route with sub-sections; decompose `SettingsPanel` → Heat targets / Sensors /
  Heating analysis / Users (reuse `SensorConfigPanel`, the `users` page, the
  `DynamicTargetCalculator` chart).
- **Verify gate:** Owner sets static + dynamic target, configures sensors, runs heating
  analysis, manages users; User/Guest have no Setup tab; tests green. **STOP.**

### Stage 4 — Gating polish + E2E
- Finalize tab/route guards for the three UI roles (Owner/User/Guest); port/extend the E2E
  role specs (`frontend/e2e/basic-user-role.spec.ts` and friends) to assert v2 tab
  visibility + control gating per role. (Read-only stays an API/backend concern.)
- **Verify gate:** the three-role UI matrix passes in E2E + manual; tests green. **STOP.**

### Stage 5 — Cutover
- Point `/tub` at v2 (root renders v2 or redirects); delete old `+page.svelte`,
  `QuickSchedulePanel`, `ControlButton`; update all E2E to v2; full regression.
- **Verify gate:** `./scripts/test.sh` fully green; manual smoke per role; **STOP**, then PR
  `feature/v2-interface` → `main`.

---

## Verification & testing

- Tests **only** via `./scripts/test.sh` (per `CLAUDE.md`). **Never dismiss E2E failures.**
- TDD red/green per stage.
- Manual: `cd frontend && npm run dev -- --host 0.0.0.0`; exercise each role.
- The current UI remains the default and fully working until the Stage 5 cutover.
