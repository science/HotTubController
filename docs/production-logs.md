# Production Logs: Fetching and Reading Them

**There is no HTTP API for logs.** The backend doesn't serve its own logs. To read
production logs you download them from the cPanel host over read-only FTP with
`backend/scripts/fetch-prod-logs.py`. This page explains how to run that script, what
each log and state file contains, and the traps to avoid when reading them.

FTP access is for **reading only**. Never upload or edit files on the server this way.
Code reaches production only through the git workflow described in `CLAUDE.md`.

## Fetching

### Prerequisites

- **Python 3.10 or newer.** No packages are required.
- **`backend/config/env.network.conveniences`**, which holds `FTP_HOST`, `FTP_USERNAME`
  and `FTP_PASSWORD`. This file is gitignored and exists only on operator machines. On
  the dev VMs it is on the shared `~/dev` mount. Older checkouts that predate the
  2026-08-11 split keep these keys in `config/env.production`, and the script falls back
  to that file.
- **Optional: `pip install dateparser`.** You only need it for free-form `--since` dates
  such as `"Jan 25 2pm"`. The forms listed below work without it.

### Commands

Run these from the repo root:

```bash
# The usual case: everything touched in the last two days
python3 backend/scripts/fetch-prod-logs.py --since "2 days ago"

# Choose where the files go (the default is /tmp/prod-debug-<timestamp>)
python3 backend/scripts/fetch-prod-logs.py --since "21 days ago" -o ~/dev/tmp/prod

# See what would be downloaded, without downloading it
python3 backend/scripts/fetch-prod-logs.py --list --since yesterday

# Everything on the server (crontab backups make up most of the files)
python3 backend/scripts/fetch-prod-logs.py
```

`--since` accepts these forms without any extra package:

- `"N minutes ago"`, and the same with `seconds`, `hours`, `days` or `weeks`
- `yesterday`, which means 24 hours ago
- ISO 8601 dates and times: `2026-11-01`, `2026-11-01 06:30`, or
  `2026-11-07T10:00-05:00`. A value without a time zone is read as this machine's local
  time.

`--since` filters on each file's **modification time**. A log that is still being
appended to, such as `api.log`, counts as recent and is downloaded whole. Compressed
`.gz` archives are decompressed after download unless you pass `--no-decompress`.

On `/tmp`: on a dev VM, `/tmp` isn't shared with the host. If the host side needs to
see the files, pass `-o ~/dev/...`.

### Output layout

The script mirrors these server directories into the output directory. Server paths are
relative to `public_html/tub/backend/`.

- `logs/` ← `storage/logs/`. Holds `api.log`, `cron.log`, `equipment-events.log`,
  `esp32.log` and `temperature-YYYY-MM-DD.log`.
- `device-calls/` ← `logs/`. Holds `events.log`, the record of every hardware command.
  Note that this is `backend/logs/`, a different directory from `backend/storage/logs/`.
- `state/` ← `storage/state/`. Holds the JSON state files described below.
- `crontab-backups/` ← `storage/crontab-backups/`. Holds a timestamped copy of the
  crontab taken before every change.
- `scheduled-jobs/` ← `storage/scheduled-jobs/`. Holds the job files that
  `cron-runner.sh` reads.

## Temperatures and calibration (read this before comparing numbers)

Each DS18B20 probe can have a calibration offset. Offsets live in
`state/esp32-sensor-config.json` as `calibration_offset`, and they are in degrees
**Celsius**. The controller makes every decision (target reached, stall, dynamic target)
on the **calibrated** value:

```
calibrated °C = raw °C + offset °C
calibrated °F = raw °F + offset × 1.8
```

For example, the water probe's offset of `0.4` adds **0.72 °F**, not 0.4 °F.

**Logging convention (since 2026-11, issue #154):** every log records the **raw**
reading, with the offset in force at that moment beside it in a
`*_calibration_offset_c` field. A null offset means "unknown" because no sensor config
was available. That is different from an offset of 0. `TemperatureLogFields`
(`backend/src/Services/TemperatureLogFields.php`) is the single place that builds these
fields.

**Older entries** have no `*_calibration_offset_c` field, and their meaning depends on
the entry type:

- **Temperature log, and heater/pump `on`/`off` events:** the value was already raw.
  On/off events took the first sensor in the reading. In production that is the water
  probe.
- **`stall_detected`:** `water_temp_f` was **calibrated**.
- **`dynamic_heat_target_*`:** `ambient_temp_f` was **calibrated**.

If the offset field is present, the value is raw. If it is absent, apply the rules above.

## Time zones

- **JSON logs and state files** use UTC timestamps (`+00:00`). The server's PHP time
  zone is UTC, so the daily `temperature-YYYY-MM-DD.log` files roll over at **UTC**
  midnight.
- **`cron.log`** uses the server's **local** time, US Eastern (`-05:00` or `-04:00`).
  `cron-runner.sh` stamps it with the shell's `date`. The cron daemon fires jobs in that
  same zone.
- **`esp32.log`** uses UTC.

## Log reference

### `logs/equipment-events.log`: what the heater controller decided

This file has one JSON object per line. Every entry has `timestamp`, `equipment` and
`action`. The actions are:

- **`on` / `off`** (`equipment` is `heater` or `pump`). The equipment state changed
  after the device call returned success. Fields: `water_temp_f` and
  `water_calibration_offset_c`. Each heat-to-target session normally produces two `off`
  entries: one when the target is reached, and one about 8 minutes later from the
  watchdog's backup off command.
- **`dynamic_heat_target_start`.** A session chose its target from the outdoor air
  temperature. Fields: `ambient_temp_f`, `ambient_calibration_offset_c`,
  `computed_target_f` (the target the session actually used), `static_target_f` (the
  job's saved temperature, used only as a fallback), `calibration_points` (the curve),
  and `clamped`.
- **`dynamic_heat_target_fallback`.** The ambient sensor was unavailable, so the session
  used `static_target_f`. The `fallback_reason` field says why.
- **`stall_detected`.** The temperature stopped rising while heating, so the session was
  stopped. Fields: `water_temp_f` and `water_calibration_offset_c`.
- **`session_expired`.** An abandoned session's lease ran out and it was cleared. Fields:
  `reason`, `target_temp_f` and `started_at`.

This is the first file to read for any question about heating behaviour. A session
starts at `dynamic_heat_target_start` (if dynamic) or at `on`, and ends at `off`.

### `device-calls/events.log`: every hardware command and what came back

This file has one JSON object per line, with `timestamp`, `action` and `data`.

- **`iot_api_live`**: one entry per call to iot-api. Data fields: `event` (for example
  `hot-tub-heat-off`), `device`, `body`, `http_code`, `duration_ms` and `success`.
- **`heater_on` / `heater_off`**: the controller-level outcome. `data.ifttt_success` is
  historically named but means "the device call succeeded".
- **`watchdog_heater_off`**: the watchdog fired. `heater_was_on: false` means the
  normal check chain had already turned the heater off.

**A 200 means Home Assistant accepted the command, not that the hardware moved.** To
confirm the hardware, look in Home Assistant, or check that the water temperature
stopped rising in the temperature log. See "Hardware Control Path" in `CLAUDE.md`.

### `logs/temperature-YYYY-MM-DD.log`: the temperature curve

This file has one JSON object per ESP32 report. The ESP32 reports every 60 seconds while
the heater is on, and every 300 seconds otherwise. Fields: `water_temp_f`, `water_temp_c`,
`water_calibration_offset_c`, `ambient_temp_f`, `ambient_temp_c`,
`ambient_calibration_offset_c` and `heater_on`. The temperatures are raw.

### `logs/cron.log`: the scheduled-job chain

Plain text. Each line looks like `[<server-local ISO time>] <job-id>: <message>`. These
are the job-id prefixes:

- **`job-…`**: a one-off scheduled action.
- **`rec-…`**: a recurring scheduled action.
- **`heat-target-…`**: one link in a heat-to-target session's chain of temperature checks.
- **`watchdog-…`**: a session's backup heater-off.

`SUCCESS: API returned 200` means the backend finished the request. `FAILED ... 000`
means curl gave up before the backend answered. The curl limit is
`API_MAX_TIME_SECONDS` in `cron-runner.sh`, sized to cover the slowest possible request.

### `logs/api.log`: every HTTP request

This file has one JSON object per request. Fields: `timestamp`, `ip`, `method`, `uri`,
`status`, `response_time_ms`, and `user` (the token's subject; cron jobs show
`cron-system`).

### `logs/esp32.log`: ESP32 check-ins

Plain text, one line per report: `[<UTC time>] OK 200 uptime=<seconds>`. A gap here
means the ESP32 stopped reporting, so the controller was working from stale temperatures.

## State files (`state/`)

- **`equipment-status.json`**: what the backend believes the heater and pump are doing.
  It only changes when a device call succeeds.
- **`esp32-temperature.json`**: the latest ESP32 reading, with raw values per sensor.
- **`esp32-sensor-config.json`**: each sensor's role (`water`, `ambient` or
  `unassigned`) and its `calibration_offset` in °C.
- **`heat-target-settings.json`**: global heat-to-target settings, including the ambient
  curve's `calibration_points`. Whether a job follows the curve is set **per job**, in
  the job's `dynamic` parameter. The global `dynamic_mode` is only the default.
- **`target-temperature.json`**: the active session, with its effective
  `target_temp_f`, lease and stall reference. This file exists only while a session is
  running.
- **`heating-characteristics.json`**: the measured heating rate, startup lag and
  overshoot, used to time a session's first check.
- **`last-stall-event.json`**: the most recent stall, as shown by `/api/health`.

## Retention

At 03:00 server time on the 1st of each month, cron runs
`storage/bin/log-rotation-cron.sh`. That script calls `POST /api/maintenance/logs/rotate`
(`MaintenanceController`), which does two things.

It handles `storage/logs/*.log` files that haven't been modified recently:

- After 30 days, a log is gzipped. `fetch-prod-logs.py` decompresses the `.gz` files it
  downloads.
- After 2 years (730 days), it is deleted. The long window is deliberate. The old daily
  temperature logs are the only record of how fast the tub heated in past seasons, and a
  heater element that is slowly scaling up would show up there as a falling heating
  rate. A gzipped day costs about 3 KB, so two years is about 2.2 MB.

It also deletes crontab backups older than 30 days. A backup is written before every
crontab edit, about 17 a day, and they are only useful for debugging recent scheduling
problems. Because the job runs monthly, between 30 and 60 days of backups are on hand at
any time. They are left as plain text so they stay greppable.

Live logs are written constantly, so they are never compressed or deleted. That covers
`api.log`, `cron.log`, `esp32.log`, `equipment-events.log`, and
`backend/logs/events.log`, which is outside `storage/` and not covered by rotation
anyway. These files simply grow. In November 2026 they totalled about 12 MB.

`storage/bin/rotate-logs.php` has its own, stricter numbers, but nothing schedules it.

## Recipe: investigating one heating session

1. **Find the session's boundaries and its real target.** Look in
   `equipment-events.log`. With the ambient curve, the target is `computed_target_f`,
   which can be well above the job's saved temperature on cold mornings.
2. **Check that each command landed.** Find the matching `iot_api_live` entries in
   `device-calls/events.log`: the HTTP code and how long each call took.
3. **Check that the checks ran on time.** Look for the `heat-target-…` lines in
   `cron.log`, keeping in mind that it uses Eastern time. Gaps in the chain or
   `FAILED` lines point at the check chain.
4. **Look at the curve.** Use that day's temperature log. Add `offset × 1.8` to the raw
   water values before comparing them with the target. After the heater turns off,
   expect the water to keep rising for a few minutes, from heat left in the pipes.
5. **If the backend did everything right and the tub still misbehaved,** look in Home
   Assistant (`persistent_notification`, the script traces).
