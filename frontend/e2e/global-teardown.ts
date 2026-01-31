import { FullConfig } from '@playwright/test';
import { readFileSync, writeFileSync, existsSync } from 'fs';
import { execSync } from 'child_process';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

/**
 * Test user patterns to clean up.
 * These are usernames created by E2E tests that may be left behind on failure.
 * Must match the patterns in global-setup.ts.
 */
const TEST_USER_PATTERNS = [
	/^testuser_\d+$/,      // testuser_1234567890 (from user-management.spec.ts)
	/^deletetest_\d+$/,    // deletetest_1234567890 (from user-management.spec.ts)
	/^basic_e2e_test$/,    // basic_e2e_test (from basic-user-role.spec.ts)
];

/**
 * Checks if a username matches a known test user pattern.
 */
function isTestUser(username: string): boolean {
	return TEST_USER_PATTERNS.some(pattern => pattern.test(username));
}

/**
 * Cleans up test users from the users.json file.
 * This runs AFTER tests to clean up any artifacts left behind by failed tests.
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
		console.error('[E2E Teardown] Error cleaning test users:', error);
		return 0;
	}
}

/**
 * Global teardown for E2E tests.
 * Cleans up test artifacts that may have been left behind:
 * - Test user accounts (from tests that failed mid-execution)
 *
 * This provides defense-in-depth with global-setup.ts:
 * - Setup cleans stale artifacts from PREVIOUS runs
 * - Teardown cleans artifacts from CURRENT run
 */
async function globalTeardown(config: FullConfig) {
	const __filename = fileURLToPath(import.meta.url);
	const __dirname = dirname(__filename);
	const backendDir = join(__dirname, '../../backend');
	const usersFile = join(backendDir, 'storage/users/users.json');

	// Clean up test users that may have been left behind
	const usersCount = cleanupTestUsers(usersFile);
	if (usersCount > 0) {
		console.log(`[E2E Teardown] Cleaned up ${usersCount} leftover test user(s)`);
	}

	// Clean up any HOTTUB cron entries created during tests
	try {
		const crontab = execSync('crontab -l 2>/dev/null', { encoding: 'utf-8' });
		const lines = crontab.split('\n');
		const hottubLines = lines.filter(l => l.includes('HOTTUB:'));
		if (hottubLines.length > 0) {
			const cleanedLines = lines.filter(l => !l.includes('HOTTUB:'));
			execSync('crontab -', { input: cleanedLines.join('\n') + '\n', encoding: 'utf-8' });
			console.log(`[E2E Teardown] Removed ${hottubLines.length} HOTTUB cron entry/entries`);
		}
	} catch {
		// Cron cleanup is best-effort
	}
}

export default globalTeardown;
