import type { LayoutLoad } from './$types';

export const load: LayoutLoad = async ({ url, fetch }) => {
	// Skip auth check for login page
	if (url.pathname === '/login') {
		return { user: null };
	}

	try {
		const response = await fetch('/api/auth/me', {
			credentials: 'include'
		});

		if (!response.ok) {
			return { user: null, redirectTo: '/login' };
		}

		const data = await response.json();
		return { user: data.user };
	} catch (e) {
		return { user: null, redirectTo: '/login' };
	}
};
