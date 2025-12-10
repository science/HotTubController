import type { LayoutLoad } from './$types';
import { base } from '$app/paths';

// Static adapter: disable SSR, enable prerendering for SPA mode
export const prerender = true;
export const ssr = false;

export const load: LayoutLoad = async ({ url, fetch }) => {
	const loginPath = `${base}/login`;

	// Skip auth check for login page
	if (url.pathname === loginPath) {
		return { user: null };
	}

	try {
		const response = await fetch(`${base}/api/auth/me`, {
			credentials: 'include'
		});

		if (!response.ok) {
			return { user: null, redirectTo: loginPath };
		}

		const data = await response.json();
		return { user: data.user };
	} catch (e) {
		return { user: null, redirectTo: loginPath };
	}
};
