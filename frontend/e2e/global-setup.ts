import { FullConfig } from '@playwright/test';
import { rmSync, readdirSync, readFileSync, writeFileSync, existsSync, mkdirSync } from 'fs';
import { execSync } from 'child_process';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

/**
 * Test user patterns to clean up.
 * These are usernames created by E2E tests that may be left behind on failure.
 */
const TEST_USER_PATTERNS = [
	/^testuser_\d+$/,                // testuser_1234567890 (from user-management.spec.ts)
	/^testuser_heat_settings_\d+$/,  // testuser_heat_settings_* (from heat-target-settings.spec.ts)
	/^deletetest_\d+$/,              // deletetest_1234567890 (from user-management.spec.ts)
	/^basic_e2e_test$/,              // basic_e2e_test (from basic-user-role.spec.ts)
];

/**
 * Checks if a username matches a known test user pattern.
 */
function isTestUser(username: string): boolean {
	return TEST_USER_PATTERNS.some(pattern => pattern.test(username));
}

/**
 * Cleans up test users from the users.json file.
 * This runs BEFORE tests to clean up stale artifacts from previous runs.
 */
function cleanupTestUsers(usersFile: string): number {
	if (!existsSync(usersFile)) {
		return 0;
	}

	try {
		const content = readFileSync(usersFile, 'utf-8');
		const data = JSON.parse(content);

		if (!data.users || typeof data.users !== 'object') {
			return 0;
		}

		const usernames = Object.keys(data.users);
		const testUsers = usernames.filter(isTestUser);

		if (testUsers.length === 0) {
			return 0;
		}

		// Remove test users
		for (const username of testUsers) {
			delete data.users[username];
		}

		// Write back
		writeFileSync(usersFile, JSON.stringify(data, null, 4));

		return testUsers.length;
	} catch (error) {
		console.error('[E2E Setup] Error cleaning test users:', error);
		return 0;
	}
}

/**
 * Sets up ESP32 test data so temperature tests can verify both sources.
 */
function setupEsp32TestData(stateDir: string): void {
	// Ensure state directory exists
	if (!existsSync(stateDir)) {
		mkdirSync(stateDir, { recursive: true });
	}

	const esp32TempFile = join(stateDir, 'esp32-temperature.json');
	const esp32ConfigFile = join(stateDir, 'esp32-sensor-config.json');

	// Create test ESP32 temperature data
	const waterAddress = '28:F6:DD:87:00:88:1E:E8';
	const ambientAddress = '28:D5:AA:87:00:23:16:34';

	const tempData = {
		device_id: 'TEST:AA:BB:CC:DD:EE',
		sensors: [
			{ address: waterAddress, temp_c: 38.5, temp_f: 101.3 },
			{ address: ambientAddress, temp_c: 21.0, temp_f: 69.8 }
		],
		uptime_seconds: 3600,
		timestamp: new Date().toISOString(),
		received_at: Math.floor(Date.now() / 1000)
	};

	writeFileSync(esp32TempFile, JSON.stringify(tempData, null, 2));

	// Create test ESP32 sensor config with role assignments
	const configData = {
		sensors: {
			[waterAddress]: {
				role: 'water',
				calibration_offset: 0,
				name: 'Test Water Sensor'
			},
			[ambientAddress]: {
				role: 'ambient',
				calibration_offset: 0,
				name: 'Test Ambient Sensor'
			}
		}
	};

	writeFileSync(esp32ConfigFile, JSON.stringify(configData, null, 2));
}

/**
 * Resets heat-target settings to default values.
 * This ensures tests start with a known state.
 */
function resetHeatTargetSettings(stateDir: string): void {
	const settingsFile = join(stateDir, 'heat-target-settings.json');
	const defaultSettings = {
		enabled: false,
		target_temp_f: 103.0,
		updated_at: new Date().toISOString()
	};
	writeFileSync(settingsFile, JSON.stringify(defaultSettings, null, 4));
}

/**
 * Cleans up scheduled job files from previous test runs.
 */
function cleanupJobFiles(jobsDir: string): number {
	try {
		const files = readdirSync(jobsDir);
		let count = 0;
		for (const file of files) {
			// Only delete job files, preserve .gitkeep
			if (file.startsWith('job-') || file.startsWith('rec-')) {
				rmSync(join(jobsDir, file));
				count++;
			}
		}
		return count;
	} catch (error) {
		return 0;
	}
}

/**
 * Wipes all files in the state directory except .gitkeep.
 * This prevents stale state files with outdated schemas from breaking tests.
 * Test data is written back explicitly by setupEsp32TestData() and resetHeatTargetSettings().
 */
function cleanStateDirectory(stateDir: string): number {
	if (!existsSync(stateDir)) {
		mkdirSync(stateDir, { recursive: true });
		return 0;
	}

	const files = readdirSync(stateDir);
	let count = 0;
	for (const file of files) {
		if (file === '.gitkeep') continue;
		rmSync(join(stateDir, file));
		count++;
	}
	return count;
}

/**
 * Removes orphaned HOTTUB cron entries left by previous test runs.
 * Job files get cleaned up by cleanupJobFiles(), but the corresponding
 * crontab entries can survive if tests exit before proper cleanup.
 */
function cleanupCronEntries(): number {
	try {
		const crontab = execSync('crontab -l 2>/dev/null', { encoding: 'utf-8' });
		const lines = crontab.split('\n');
		const hottubLines = lines.filter(l => l.includes('HOTTUB:'));
		if (hottubLines.length === 0) return 0;

		const cleanedLines = lines.filter(l => !l.includes('HOTTUB:'));
		execSync('crontab -', { input: cleanedLines.join('\n') + '\n', encoding: 'utf-8' });
		return hottubLines.length;
	} catch {
		return 0;
	}
}

/**
 * Global setup for E2E tests.
 * Cleans up artifacts from previous test runs to ensure test isolation:
 * - Scheduled job files
 * - Test user accounts
 * Also sets up test data:
 * - ESP32 sensor data and configuration
 */
async function globalSetup(config: FullConfig) {
	const __filename = fileURLToPath(import.meta.url);
	const __dirname = dirname(__filename);
	const backendDir = join(__dirname, '../../backend');
	const jobsDir = join(backendDir, 'storage/scheduled-jobs');
	const usersFile = join(backendDir, 'storage/users/users.json');
	const stateDir = join(backendDir, 'storage/state');

	// Clean up job files and orphaned cron entries
	const jobsCount = cleanupJobFiles(jobsDir);
	console.log(`[E2E Setup] Cleaned up ${jobsCount} job files`);

	const cronCount = cleanupCronEntries();
	if (cronCount > 0) {
		console.log(`[E2E Setup] Removed ${cronCount} orphaned HOTTUB cron entry/entries`);
	}

	// Clean up test users from previous runs
	const usersCount = cleanupTestUsers(usersFile);
	if (usersCount > 0) {
		console.log(`[E2E Setup] Cleaned up ${usersCount} stale test user(s)`);
	}

	// Wipe all state files to prevent stale cached data from breaking tests
	const stateCount = cleanStateDirectory(stateDir);
	console.log(`[E2E Setup] Cleaned ${stateCount} state file(s)`);

	// Write back only the state files tests need
	setupEsp32TestData(stateDir);
	console.log(`[E2E Setup] Set up ESP32 test data`);

	resetHeatTargetSettings(stateDir);
	console.log(`[E2E Setup] Reset heat-target settings to defaults`);
}

export default globalSetup;
