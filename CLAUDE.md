# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Structure

This project is undergoing a complete refactor. The previous implementation has been archived:

- **`_archive/`** - Previous backend (PHP) and frontend (React) implementation for reference
- New implementation will be built from scratch using TDD methodology

## Development Methodology: TDD Red/Green

**All new functionality MUST follow Test-Driven Development (TDD) using the red/green methodology.** Work in small, incremental steps—never write large amounts of implementation code without corresponding tests.

### The TDD Cycle

1. **RED: Write a failing test first**
   - Write a small, focused test for the next piece of functionality
   - Run the test to **prove it fails** (this confirms the test is valid)
   - If the test passes immediately, the test is wrong or the functionality already exists

2. **GREEN: Write minimal code to pass**
   - Implement just enough code to make the failing test pass
   - Run the test to **prove it passes**
   - Do not add extra functionality beyond what the test requires

3. **REFACTOR: Clean up (optional)**
   - Improve code structure while keeping tests green
   - Run tests again to confirm nothing broke

4. **REPEAT: Move forward incrementally**
   - Add the next small test, repeat the cycle
   - Build functionality piece by piece, always with test coverage

### Test Types by Context

**CLI Tool (root project):** Use Jest unit tests
```bash
# Write test in test/*.test.js, then run:
npm test -- --testPathPattern=myFeature
```

**Web App (`web-app/`):** Use Vitest for unit tests, Playwright for browser tests
```bash
cd web-app
npm run test:unit              # Vitest unit tests
npm run test:e2e               # Playwright browser tests
npm run test:e2e:ui            # Playwright with interactive UI
```

**When to use each:**
- **Unit tests (Jest/Vitest)**: Pure functions, data transformations, business logic, module APIs
- **Playwright browser tests**: User interactions, page navigation, form submissions, visual behavior, component integration

### Debugging Failures (When Things Go Red)

When implementation work causes test failures or unexpected bugs:

1. **Add diagnostic logging**
   ```javascript
   console.log('DEBUG: value =', value);
   console.log('DEBUG: state before =', JSON.stringify(state, null, 2));
   ```

2. **Write a "proving" test that isolates the bug**
   - Create a minimal test case that reproduces the failure
   - Run it to confirm it fails (proves the bug exists)
   - This test becomes part of your regression suite

3. **Identify root cause**
   - Use the failing test + console output to trace the issue
   - Narrow down to the specific line/condition causing the problem

4. **Fix and verify**
   - Implement the fix
   - Run the proving test to confirm it now passes
   - Run full test suite to ensure no regressions

### Example TDD Workflow

Adding a new CLI flag `--verbose`:

```bash
# Step 1: Write failing test
# In test/cli.test.js, add:
test('parses --verbose flag', () => {
  const result = parseCliArgs(['input.md', '--verbose']);
  expect(result.verbose).toBe(true);
});

# Step 2: Run to prove failure
npm test -- --testPathPattern=cli
# ✗ FAIL - result.verbose is undefined (RED)

# Step 3: Implement minimal code in src/cli.js
# Add verbose flag parsing

# Step 4: Run to prove success
npm test -- --testPathPattern=cli
# ✓ PASS (GREEN)

# Step 5: Next test - verbose affects output
# Write test, prove failure, implement, prove success...
```

### Key Principles

- **Never skip the RED step** - Running a test before implementation proves it can fail
- **Small increments** - Each test should cover one small behavior, not entire features
- **Tests are documentation** - They show exactly what the code should do
- **Failing tests are information** - They tell you precisely what's broken
- **Console debugging is temporary** - Remove `console.log` statements after fixing issues

## Critical Safety - Hardware Control

**CRITICAL: This system controls real hardware!** The hot tub controller interfaces with:
- **WirelessTag API**: Temperature monitoring from wireless sensors
- **IFTTT Webhooks**: Equipment control (heater, pump, ionizer) via SmartLife automation

When implementing any functionality that could trigger hardware:
- Always use test/simulation modes during development
- Never commit real API keys to the repository
- Reference `_archive/backend/` for safety patterns from previous implementation

## Reference: Archived Implementation

The `_archive/` folder contains the previous implementation for reference:

- **`_archive/backend/`** - PHP API with WirelessTag and IFTTT integration
- **`_archive/frontend/`** - React 19 + TypeScript + Vite frontend
- **`_archive/docs/`** - Previous documentation

Useful patterns to reference:
- IFTTT safety client with test/dry-run modes
- VCR testing for HTTP interactions
- Temperature simulation for heating cycle tests
