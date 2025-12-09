# Hot Tub Controller MVP - Implementation Plan

## Vision

A minimal, mobile-first control panel for hot tub equipment. Three buttons, dark mode, built for expansion.

## MVP Features (v0.1)

1. **Heater ON** - Turn on the hot tub heater
2. **Heater OFF** - Turn off the hot tub heater
3. **Pump Run** - Turn on pump for 2 hours

No authentication. Backend logs events (no real IFTTT calls yet).

---

## Architecture

### Technology Choices

| Layer | Technology | Rationale |
|-------|------------|-----------|
| Backend | PHP 8.x (vanilla/minimal) | Simple, matches existing server, easy to deploy |
| Frontend | SvelteKit | Full-featured Svelte framework, excellent DX, easy API integration |
| Styling | TailwindCSS | Utility-first, great for responsive/dark mode |
| Icons | Lucide Svelte | Clean, consistent icon set |

### Why SvelteKit over alternatives?

- **vs Svelte standalone**: SvelteKit provides routing, build tooling, and adapter system out of the box
- **vs other frameworks**: Svelte's compiled output is tiny and fast - perfect for a control panel
- **PHP integration**: SvelteKit can be built as static SPA or use adapter-static, then served alongside PHP API

### Project Structure

```
hot-tub-controller/
├── backend/                    # PHP API
│   ├── public/
│   │   └── index.php          # Entry point (router)
│   ├── src/
│   │   ├── Controllers/
│   │   │   └── EquipmentController.php
│   │   └── Services/
│   │       └── EventLogger.php
│   ├── logs/
│   │   └── events.log
│   ├── tests/
│   │   └── EquipmentControllerTest.php
│   ├── composer.json
│   └── phpunit.xml
│
├── frontend/                   # SvelteKit app
│   ├── src/
│   │   ├── lib/
│   │   │   ├── components/
│   │   │   │   ├── ControlButton.svelte
│   │   │   │   └── LongPressTooltip.svelte
│   │   │   └── api.ts
│   │   ├── routes/
│   │   │   └── +page.svelte
│   │   └── app.css
│   ├── tests/
│   │   └── *.test.ts
│   ├── package.json
│   ├── svelte.config.js
│   ├── tailwind.config.js
│   └── vite.config.ts
│
├── _archive/                   # Reference code
├── CLAUDE.md
└── PLAN.md
```

---

## API Design

### Endpoints

| Method | Path | Description | Request Body | Response |
|--------|------|-------------|--------------|----------|
| POST | `/api/equipment/heater/on` | Turn heater on | - | `{ "success": true, "action": "heater_on", "timestamp": "..." }` |
| POST | `/api/equipment/heater/off` | Turn heater off | - | `{ "success": true, "action": "heater_off", "timestamp": "..." }` |
| POST | `/api/equipment/pump/run` | Run pump 2hr | - | `{ "success": true, "action": "pump_run", "duration": 7200, "timestamp": "..." }` |
| GET | `/api/health` | Health check | - | `{ "status": "ok" }` |

### Response Format

All responses are JSON with consistent structure:
```json
{
  "success": true|false,
  "action": "action_name",
  "timestamp": "ISO8601",
  "error": "message if failed"
}
```

---

## UI Design

### Layout Concept

```
┌─────────────────────────────────────┐
│         HOT TUB CONTROL             │
│                                     │
│  ┌─────────────┐  ┌─────────────┐  │
│  │             │  │             │  │
│  │   🔥 ON     │  │   ❄️ OFF    │  │
│  │   Heater    │  │   Heater    │  │
│  │             │  │             │  │
│  └─────────────┘  └─────────────┘  │
│                                     │
│  ┌─────────────────────────────┐   │
│  │                             │   │
│  │      💨 RUN PUMP            │   │
│  │      2 Hours                │   │
│  │                             │   │
│  └─────────────────────────────┘   │
│                                     │
│  ─────────────────────────────────  │
│  Status: Ready                      │
└─────────────────────────────────────┘
```

### Design Tokens

- **Background**: `#0f172a` (slate-900)
- **Card/Button background**: `#1e293b` (slate-800)
- **Primary accent**: `#f97316` (orange-500) - for heater on
- **Secondary accent**: `#3b82f6` (blue-500) - for heater off
- **Tertiary accent**: `#06b6d4` (cyan-500) - for pump
- **Text**: `#f1f5f9` (slate-100)
- **Muted text**: `#94a3b8` (slate-400)

### Icons (Control Panel Style - Monochrome)

- **Heater ON**: Flame icon (Lucide: `Flame`)
- **Heater OFF**: Flame with slash/ban (Lucide: `FlameOff` or custom `Flame` + `Ban` overlay)
- **Pump Run**: Recirculation arrows (Lucide: `RefreshCw` or custom SVG with two half-circle arrows)

### Interaction Pattern

- **Tap**: Execute action
- **Long press (500ms)**: Show tooltip with description
- **Visual feedback**: Button animates on press, shows loading state during API call
- **Status toast**: Brief confirmation message after action

---

## Implementation Plan (TDD Steps)

### Phase 1: Backend Foundation

| Step | Test First | Then Implement |
|------|------------|----------------|
| 1.1 | Test EventLogger writes to file | EventLogger service |
| 1.2 | Test health endpoint returns OK | Router + health endpoint |
| 1.3 | Test heater/on logs event | Heater on endpoint |
| 1.4 | Test heater/off logs event | Heater off endpoint |
| 1.5 | Test pump/run logs event with duration | Pump run endpoint |

### Phase 2: Frontend Foundation

| Step | Test First | Then Implement |
|------|------------|----------------|
| 2.1 | Test API client makes correct requests | API client module |
| 2.2 | Test ControlButton renders with icon and label | ControlButton component |
| 2.3 | Test ControlButton calls action on click | Click handler |
| 2.4 | Test long-press shows tooltip | LongPressTooltip behavior |
| 2.5 | Test main page renders three controls | Main page layout |

### Phase 3: Integration

| Step | Description |
|------|-------------|
| 3.1 | Wire frontend API calls to backend |
| 3.2 | Add loading states and error handling |
| 3.3 | Add status toast notifications |
| 3.4 | Mobile responsiveness testing |

---

## Development Workflow

### Backend Testing
```bash
cd backend
composer install
./vendor/bin/phpunit
```

### Frontend Testing
```bash
cd frontend
npm install
npm run test          # Vitest unit tests
npm run test:e2e      # Playwright (later)
```

### Development Servers
```bash
# Terminal 1: PHP backend
cd backend && php -S localhost:8080 -t public

# Terminal 2: SvelteKit frontend
cd frontend && npm run dev
```

---

## File-by-File Implementation Order

### Backend
1. `composer.json` - Dependencies (phpunit)
2. `phpunit.xml` - Test configuration
3. `tests/EventLoggerTest.php` - RED
4. `src/Services/EventLogger.php` - GREEN
5. `tests/EquipmentControllerTest.php` - RED (health)
6. `public/index.php` - Router, GREEN
7. Continue TDD cycle for each endpoint

### Frontend
1. `package.json` - Dependencies
2. `svelte.config.js`, `vite.config.ts`, `tailwind.config.js`
3. `src/app.css` - Tailwind + dark mode base
4. `tests/api.test.ts` - RED
5. `src/lib/api.ts` - GREEN
6. `tests/ControlButton.test.ts` - RED
7. `src/lib/components/ControlButton.svelte` - GREEN
8. Continue TDD cycle

---

## Definition of Done (MVP)

- [ ] Backend: All 4 endpoints working and tested
- [ ] Backend: Events logged to file with timestamp
- [ ] Frontend: Three control buttons rendered
- [ ] Frontend: Buttons call correct API endpoints
- [ ] Frontend: Long-press tooltips working
- [ ] Frontend: Dark mode, mobile-responsive
- [ ] Frontend: Visual feedback (loading, success/error states)
- [ ] Integration: Frontend calls backend successfully
- [ ] All tests passing

---

## Future Enhancements (Post-MVP)

1. Authentication (simple PIN or password)
2. Real IFTTT integration
3. Temperature display from WirelessTag
4. Scheduling system
5. History/logs viewer
6. PWA for "Add to Home Screen"
