import { base } from '$app/paths';

export interface User {
	username: string;
	role: string;
}

export interface AuthState {
	user: User | null;
	isAuthenticated: boolean;
	isLoading: boolean;
	error: string | null;
}

// Reactive auth state using Svelte 5 runes
let user = $state<User | null>(null);
let isLoading = $state(true);
let error = $state<string | null>(null);

// Derived state
const isAuthenticated = $derived(user !== null);

// API base URL - uses SvelteKit base path
const API_BASE = base;

export async function login(username: string, password: string): Promise<boolean> {
	isLoading = true;
	error = null;

	try {
		const response = await fetch(`${API_BASE}/api/auth/login`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			credentials: 'include',
			body: JSON.stringify({ username, password })
		});

		const data = await response.json();

		if (!response.ok) {
			error = data.error || 'Login failed';
			return false;
		}

		user = data.user;
		return true;
	} catch (e) {
		error = 'Network error. Please try again.';
		return false;
	} finally {
		isLoading = false;
	}
}

export async function logout(): Promise<void> {
	try {
		await fetch(`${API_BASE}/api/auth/logout`, {
			method: 'POST',
			credentials: 'include'
		});
	} catch (e) {
		// Ignore errors on logout
	} finally {
		user = null;
		error = null;
	}
}

export async function checkAuth(): Promise<boolean> {
	isLoading = true;

	try {
		const response = await fetch(`${API_BASE}/api/auth/me`, {
			credentials: 'include'
		});

		if (!response.ok) {
			user = null;
			return false;
		}

		const data = await response.json();
		user = data.user;
		return true;
	} catch (e) {
		user = null;
		return false;
	} finally {
		isLoading = false;
	}
}

// Export reactive getters
export function getAuthState(): AuthState {
	return {
		user,
		isAuthenticated,
		isLoading,
		error
	};
}

export function getUser(): User | null {
	return user;
}

export function getIsAuthenticated(): boolean {
	return isAuthenticated;
}

export function getIsLoading(): boolean {
	return isLoading;
}

export function getError(): string | null {
	return error;
}

export function clearError(): void {
	error = null;
}
