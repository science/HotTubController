# Temperature Panel Feature Design

## Overview

Add a temperature display section to the hot tub controller UI that shows:
- Current water temperature (from WirelessTag sensor)
- Ambient temperature
- Battery/signal status (optional, for diagnostics)
- Refresh button with loading indicator

## UX Design

### Layout Position
Between "Quick Heat On" panel and "Schedule" panel on the main page.

### Visual Design
```
+--------------------------------------------------+
| Temperature                            [Refresh] |
|   Water: 98.4°F    Ambient: 62°F                |
+--------------------------------------------------+
```

On narrow screens (mobile), the layout wraps:
```
+---------------------------+
| Temperature     [Refresh] |
|   Water: 98.4°F          |
|   Ambient: 62°F          |
+---------------------------+
```

Key CSS: `flex flex-wrap` on container, `whitespace-nowrap` on each reading to keep icon+label+value as a unit.

### Loading State
When refresh is clicked:
1. Refresh icon rotates (CSS animation)
2. Temperature values show "..." or dim
3. Small status text: "Fetching..." with animated dots

### Error State
If API call fails:
- Show error message below temperatures
- Keep last known values visible (if any)

## Communication Strategy

**Decision: Simple Request/Response with Loading State**

Rationale:
- SSE (Server-Sent Events) requires keeping PHP connections open, problematic on shared hosting
- Polling adds complexity for a user-initiated action
- ~200-300ms typical response time is acceptable for manual refresh
- Matches existing pattern used by schedule list refresh

Implementation:
1. User clicks refresh button
2. Frontend shows loading state
3. Backend calls WirelessTag API (or stub)
4. Backend returns JSON response
5. Frontend displays new temperatures

## API Design

### Endpoint
```
GET /api/temperature
```

### Response (Success)
```json
{
  "water_temp_f": 98.4,
  "water_temp_c": 36.9,
  "ambient_temp_f": 62.0,
  "ambient_temp_c": 16.7,
  "battery_voltage": 3.54,
  "signal_dbm": -67,
  "device_name": "Hot Tub",
  "timestamp": "2025-12-11T10:30:00Z"
}
```

### Response (Error)
```json
{
  "error": "Failed to read temperature sensor"
}
```
HTTP 500

### Authentication
- Requires authentication (protected route)
- Uses existing auth middleware

## Backend Implementation

### TemperatureController
New controller in `src/Controllers/TemperatureController.php`:
- Injects `WirelessTagClient`
- `get()` method returns temperature data
- Handles errors gracefully

### Route Registration
Add to `public/index.php`:
```php
$router->get('/api/temperature', fn() => $temperatureController->get(), $requireAuth);
```

### Factory Integration
Use existing `WirelessTagClientFactory` to create client based on mode.

## Frontend Implementation

### TemperaturePanel.svelte
New component following existing panel patterns:
- Uses Svelte 5 runes ($state, $effect)
- Tailwind CSS matching existing design
- lucide-svelte icons (Thermometer, RefreshCw)

### Loading Animation
CSS keyframe animation for spinning refresh icon:
```css
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
```

### State Management
```typescript
let temperature = $state<TemperatureData | null>(null);
let loading = $state(false);
let error = $state<string | null>(null);
```

### API Client
Add to `src/lib/api.ts`:
```typescript
export interface TemperatureData {
  water_temp_f: number;
  water_temp_c: number;
  ambient_temp_f: number;
  ambient_temp_c: number;
  battery_voltage: number | null;
  signal_dbm: number | null;
  device_name: string;
  timestamp: string;
}

// In api object:
getTemperature: () => get<TemperatureData>('/api/temperature'),
```

## TDD Test Plan

### Backend Tests (PHPUnit)

1. **TemperatureControllerTest** (stub mode)
   - Returns temperature data with correct structure
   - Returns 401 when not authenticated
   - Returns 500 on WirelessTag API error

2. **Integration Test** (stub mode)
   - Full request/response through router
   - Auth middleware integration

### Frontend Tests (Vitest)

1. **TemperaturePanel.test.ts**
   - Displays temperature when data available
   - Shows loading state during fetch
   - Shows error message on failure
   - Refresh button triggers API call

### E2E Tests (Playwright)

1. **temperature.spec.ts**
   - Temperature panel visible after login
   - Refresh button updates display
   - Loading spinner appears during fetch

## File Changes Summary

### New Files
- `backend/src/Controllers/TemperatureController.php`
- `backend/tests/Unit/Controllers/TemperatureControllerTest.php`
- `frontend/src/lib/components/TemperaturePanel.svelte`
- `frontend/src/lib/components/TemperaturePanel.test.ts`
- `frontend/e2e/temperature.spec.ts`

### Modified Files
- `backend/public/index.php` - Add route and wire up controller
- `frontend/src/lib/api.ts` - Add temperature types and API method
- `frontend/src/routes/+page.svelte` - Add TemperaturePanel component

## Implementation Order (TDD)

1. Backend: Write failing test for TemperatureController
2. Backend: Implement controller to pass test
3. Backend: Wire up route in index.php
4. Frontend: Add API types and method
5. Frontend: Write failing test for TemperaturePanel
6. Frontend: Implement component to pass test
7. Frontend: Integrate into main page
8. E2E: Write integration tests
9. Manual UAT verification
