import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
	plugins: [svelte({ hot: !process.env.VITEST })],
	test: {
		include: ['src/**/*.test.ts'],
		environment: 'jsdom',
		globals: true,
		alias: {
			'$lib': '/src/lib',
			'$app/paths': '/src/test/mocks/app-paths.ts',
		},
	},
	resolve: {
		conditions: ['browser'],
	},
});
