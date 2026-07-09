# v2 Interface — UX/Design/Usability Review (2026-07-08)

Reviewed against `docs/redesign/v2-interface.md` on `feature/v2-interface`, by running the
app in stub mode and exercising Owner / User / Guest at a 390×844 phone viewport.
Screenshots referenced here live in the review artifact (see PR/session notes); the
findings stand alone.

## What's working well

- **The IA is doing its job.** Side-by-side with v1 (one ~6500px scroll mixing controls,
  scheduling, admin config, and sensor calibration), v2's Home is a single screen: temps →
  act now → what's next. "Home = now / Schedule = later / Setup = configure" reads clearly.
- **The folded "Next up" model is genuinely good.** Skip + override one-off collapsing into
  ONE card with "resets to 6:30 AM · 102.25°F daily" matches the user's mental model
  ("tomorrow, later and hotter") instead of the backend's (skip + new job). The stable-key
  selection surviving override churn is the right call.
- **Staged edits with an explicit Save** — given crontab rewrites are slow on the host —
  is the right trade, and the navigation guard means staging never silently loses work.
- **Role gating is honest**: Guest gets a tab-bar-free single screen, User loses Setup,
  and the guards mirror backend enforcement rather than replacing it.
- **Copy voice is mostly there**: "Unsaved — Save rewrites the schedule", "default stays
  6:30 AM · 102.25°F" explain consequences in plain language.

## Findings

Ordered by severity. **[fixed]** = adjusted directly on the branch during this review;
**[proposal]** = mockup/decision needed; **[question]** = logged in `v2-open-questions.md`.

### F1. Destructive "Cancel" is ambiguous — [fixed]
`EventCard`'s "Cancel" **deletes the scheduled event**, while the add-sheet's "Cancel"
dismisses a form and the guard modal's actions sit right next to it. Same word, opposite
severities; on the Schedule tab both were visible simultaneously. Renamed the destructive
action to **Remove** with clearer danger styling on hover; "Cancel" now always means
"back out of this UI".

### F2. Temperature hero isn't a hero — [fixed]
The plan's Home sketch centers a big water temperature with "air 58° · 2m ago" beneath.
What shipped is the v1 `TemperaturePanel`: small numbers, an "ESP32" chip + implementation
labels ("Water:", "Ambient:"), an absolute timestamp ("Last reading: Jul 7, 10:35 PM"),
and a refresh icon. On the "now" surface the water temp *is* the product. Added a v2
`TempHero` (big water reading, `air 55° · 2m ago` subline, relative age, amber stale
state ≥10 min with plain-language copy, error state per the plan's voice). ESP32/sensor
detail stays in Setup where it belongs.

### F3. Schedule cards repeat the same time three times — [fixed]
A recurring card read: title `Heat to 102.25°F`, then `start6:30 AM` (note the missing
space — a Svelte whitespace-collapse bug), then `every day · next Tomorrow 6:30 AM`. The
one-off card duplicated its full date verbatim on two adjacent lines. Now: one when-line
(`Daily · start 6:30 AM` / `Thu, Jul 9 · 3:00 PM`), and a "next …" line only when it adds
information (skips).

### F4. "active" badge is noise — [fixed]
Every non-skipped card wore a green "active" pill, so the badge carried no information.
Badges are now reserved for exceptions (`skipped`, and Home's `adjusted`), which makes the
exceptions actually pop.

### F5. Guard modal hides the safest choice — [fixed]
`UnsavedChangesModal` offered only "Discard" and "Save & continue"; "keep editing" existed
but only via Escape or a backdrop tap — undiscoverable on a phone, and the two visible
choices are both commitments. Added an explicit "Keep editing" button (and initial focus
on the safe action).

### F6. Action feedback can render off-screen — [fixed]
The status toast was static text at the bottom of the page flow; with a few Next-up cards,
tapping "Heat" gave no visible feedback (the confirmation rendered below the fold). It's
now a fixed toast above the tab bar.

### F7. Guest can rewrite the household default target — [fixed, question logged]
The Home target dial persists the *global* saved default (`PUT
/api/settings/heat-target/temp`), and it rendered for Guests (`basic` is a write role).
The plan scopes Guest to "immediate heater on/off + blinds + see temp" — a houseguest
nudging the family's everyday 102.25°F default is a surprise with persistence. The dial is
now Owner/User; Guests see the target inside the Heat button label and can still heat to
it. Backend still permits `basic` writes to that endpoint — flagged as a question (UI-only
gate vs. tightening the endpoint).

### F8. Home's adjust bar floats above the thing it edits — [proposal]
The "Adjusting just the next run" control bar sits *above* the card list and acts on the
selected card *below* it. The bar reads as its own card; the target of the ± steppers is
spatially inverted, and selection ("radio-card") is an unusual pattern to discover.
Proposal: move the adjust controls *inside* the selected card (tap a card → it expands),
matching the Schedule tab's expanded-card pattern — one mental model everywhere. Sketch:

```
NEXT UP                                 NEXT UP
┌──────────────────────────┐            ┌──────────────────────────┐
│ Adjusting just the next… │            │ Heat to 102.25°F  ⟳      │
│  [− 6:30 AM +][− 102 +]  │    ──►     │ Tomorrow 6:30 AM         │
│ Skip next run   default… │            │  [− 6:30 AM +][− 102 +]  │
└──────────────────────────┘            │  Skip next · default 6:30│
┌──────────────────────────┐            └──────────────────────────┘
│ Heat to 102.25°F  ⟳     ◄│ selected   ┌──────────────────────────┐
│ Tomorrow 6:30 AM         │            │ Heat to 100°F            │
└──────────────────────────┘            │ Thu, Jul 9 3:00 PM       │
┌──────────────────────────┐            └──────────────────────────┘
│ Heat to 100°F            │            (tap another card → it
│ Thu, Jul 9 3:00 PM       │             expands, controls move)
└──────────────────────────┘
```
Not implemented tonight: it touches the selection/guard state machine and E2E specs;
better done deliberately as part of the Stage-2.5 card-parity work, where Home's expanded
card and Schedule's card become the same component.

### F9. Recurring vs one-off cards still lack parity — [fixed later the same night]
On the Schedule tab a one-off got inline ± time/temp steppers; a recurring card got a
modal-ish "Edit temp" input and *no* time adjustment (its daily time could only be changed
by delete + re-add). Landed per the standing decision: atomic in-place recurring
reschedule (`SchedulerService::rescheduleRecurring` + `DtdtService::rescheduleReadyBy`
for ready-by wake-up recompute, TDD'd), `PUT /api/schedule/{id}/reschedule` branching on
job type, and `EventCard` rendering identical steppers for recurring heat events (Skip
next shows while clean). "Edit temp" is retired from adjustable cards.

### F10. Smaller notes
- **Add sheet**: "+ Add" → what? Retitled the button "Add heating" per the copy rule
  (verbs name the action). [fixed]
- **Tab bar** is text-only; icons + labels would improve scannability and enlarge tap
  targets. [proposal — cheap, any time]
- **Time-nudge edge**: shifting a one-off past midnight wraps within the same calendar
  day (23:45 + 15min → 00:00 *that morning*, i.e. ~24h earlier). Rare on a "nudge"
  control; logged. [question]
- **Header** still has inline `Owner · Logout`; the plan's ☰ menu (Preferences, Logout)
  has no owner yet. Fine until Setup lands; decide with Stage 3. [question]
- **auto-heat-off** ("decide in Stage 2") is still undecided; v2 currently ships without
  it. Heat-to-target's own auto-off covers the main risk when target mode is on, but
  plain `heater-on` mode has no v2 auto-off. [question]
- **DRY**: `formatTemp`/`formatClock`/`resumeLabel`/repeat-icon/`ACTION_LABELS` are
  duplicated between Home and EventCard — fold into `scheduleUtils`/a shared snippet
  during the parity refactor.
