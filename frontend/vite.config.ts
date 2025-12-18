import { sveltekit } from '@sveltejs/kit/vite';
import { SvelteKitPWA } from '@vite-pwa/sveltekit';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
	plugins: [
		sveltekit(),
		tailwindcss(),
		SvelteKitPWA({
			registerType: 'autoUpdate',
			manifest: {
				name: 'Hot Tub Controller',
				short_name: 'Hot Tub',
				description: 'Control your hot tub heater and pump',
				theme_color: '#0ea5e9',
				background_color: '#0f172a',
				display: 'standalone',
				start_url: '/tub/',
				scope: '/tub/',
				icons: [
					{
						src: '/tub/icons/icon-192.png',
						sizes: '192x192',
						type: 'image/png'
					},
					{
						src: '/tub/icons/icon-512.png',
						sizes: '512x512',
						type: 'image/png'
					}
				]
			},
			workbox: {
				globPatterns: []
			},
			devOptions: {
				enabled: true
			}
		})
	],
	server: {
		proxy: {
			'/tub/backend/public/api': {
				target: 'http://localhost:8080',
				changeOrigin: true,
				rewrite: (path) => path.replace(/^\/tub\/backend\/public/, ''),
			},
		},
	},
});
