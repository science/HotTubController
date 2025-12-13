import { FullConfig } from '@playwright/test';
import { rmSync, readdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

/**
 * Global setup for E2E tests.
 * Cleans up scheduled job files from previous test runs to ensure test isolation.
 */
async function globalSetup(config: FullConfig) {
	const __filename = fileURLToPath(import.meta.url);
	const __dirname = dirname(__filename);
	const jobsDir = join(__dirname, '../../backend/storage/scheduled-jobs');

	try {
		const files = readdirSync(jobsDir);
		for (const file of files) {
			// Only delete job files, preserve .gitkeep
			if (file.startsWith('job-') || file.startsWith('rec-')) {
				rmSync(join(jobsDir, file));
			}
		}
		console.log(`[E2E Setup] Cleaned up ${files.filter(f => f.startsWith('job-') || f.startsWith('rec-')).length} job files`);
	} catch (error) {
		console.log('[E2E Setup] No job files to clean up');
	}
}

export default globalSetup;
