// Role → capability mapping for the v2 interface.
//
// The backend (JsonUserRepository::VALID_ROLES / WRITE_ROLES, AuthMiddleware) is the
// source of truth and *enforces* access; these helpers mirror that model so the UI shows
// each role exactly what it can act on. Functions are pure and take a raw backend role
// string (or null/undefined), so they work equally with `data.user?.role` from the
// layout load and with the auth store.
//
// Backend role  →  v2 label
//   admin       →  Owner
//   user        →  User
//   basic       →  Guest
//   readonly    →  Read-only (API-only integration; no UI)

export type Tab = 'home' | 'schedule' | 'setup';

// Roles that may mutate hardware (heater/pump/blinds). Mirrors backend WRITE_ROLES.
const CONTROL_ROLES = ['admin', 'user', 'basic'];

// Roles that get the Schedule tab. A UI affordance: the backend lets any write role POST
// a schedule, but the v2 UI reserves scheduling for Owner and User (Guests control "now").
const SCHEDULE_ROLES = ['admin', 'user'];

const FRIENDLY_NAMES: Record<string, string> = {
	admin: 'Owner',
	user: 'User',
	basic: 'Guest',
	readonly: 'Read-only'
};

export function isOwner(role?: string | null): boolean {
	return role === 'admin';
}

export function isUser(role?: string | null): boolean {
	return role === 'user';
}

export function isGuest(role?: string | null): boolean {
	return role === 'basic';
}

export function isReadonly(role?: string | null): boolean {
	return role === 'readonly';
}

/** Can act on hardware: heater on/off, pump, blinds. */
export function canControl(role?: string | null): boolean {
	return CONTROL_ROLES.includes(role ?? '');
}

/** Can create/manage scheduled jobs (sees the Schedule tab). */
export function canSchedule(role?: string | null): boolean {
	return SCHEDULE_ROLES.includes(role ?? '');
}

/**
 * Can adjust the *persistent* default target temp (Home dial). Guests may heat to the
 * household default but not rewrite it — the dial writes a global setting. UI-scoping
 * only: the backend endpoint accepts any write role (see v2-open-questions.md Q1).
 */
export function canTuneTarget(role?: string | null): boolean {
	return SCHEDULE_ROLES.includes(role ?? '');
}

/** Can change system configuration: heat targets, sensors, heating analysis (Setup tab). */
export function canConfigure(role?: string | null): boolean {
	return role === 'admin';
}

/** Can create, list, and delete users. */
export function canManageUsers(role?: string | null): boolean {
	return role === 'admin';
}

/** UI label for a backend role; falls back to the raw value, or '' for none. */
export function friendlyRoleName(role?: string | null): string {
	if (!role) return '';
	return FRIENDLY_NAMES[role] ?? role;
}

/** Tabs this role may navigate, in display order. Always includes 'home'. */
export function visibleTabs(role?: string | null): Tab[] {
	const tabs: Tab[] = ['home'];
	if (canSchedule(role)) tabs.push('schedule');
	if (canConfigure(role)) tabs.push('setup');
	return tabs;
}
