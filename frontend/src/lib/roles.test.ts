import { describe, it, expect } from 'vitest';
import {
	isOwner,
	isUser,
	isGuest,
	isReadonly,
	canControl,
	canSchedule,
	canConfigure,
	canManageUsers,
	canTuneTarget,
	friendlyRoleName,
	visibleTabs
} from './roles';

describe('roles', () => {
	describe('identity predicates', () => {
		it('maps each backend role to its v2 identity', () => {
			expect(isOwner('admin')).toBe(true);
			expect(isUser('user')).toBe(true);
			expect(isGuest('basic')).toBe(true);
			expect(isReadonly('readonly')).toBe(true);
		});

		it('is exclusive across identities', () => {
			expect(isOwner('user')).toBe(false);
			expect(isUser('basic')).toBe(false);
			expect(isGuest('admin')).toBe(false);
			expect(isReadonly('basic')).toBe(false);
		});

		it('treats null/undefined/unknown as no identity', () => {
			expect(isOwner(null)).toBe(false);
			expect(isUser(undefined)).toBe(false);
			expect(isGuest('nonsense')).toBe(false);
			expect(isReadonly('')).toBe(false);
		});
	});

	describe('canTuneTarget — persistent default target temp (Home dial)', () => {
		it('allows Owner and User', () => {
			expect(canTuneTarget('admin')).toBe(true);
			expect(canTuneTarget('user')).toBe(true);
		});

		it('blocks Guest — heating to the household default is fine, rewriting it is not', () => {
			expect(canTuneTarget('basic')).toBe(false);
			expect(canTuneTarget('readonly')).toBe(false);
			expect(canTuneTarget(null)).toBe(false);
		});
	});

	describe('canControl — hardware (heater/pump/blinds)', () => {
		it('allows the three write roles', () => {
			expect(canControl('admin')).toBe(true);
			expect(canControl('user')).toBe(true);
			expect(canControl('basic')).toBe(true);
		});

		it('blocks read-only and unknown roles', () => {
			expect(canControl('readonly')).toBe(false);
			expect(canControl(null)).toBe(false);
			expect(canControl('nonsense')).toBe(false);
		});
	});

	describe('canSchedule — Schedule tab (Owner + User only)', () => {
		it('allows owner and user', () => {
			expect(canSchedule('admin')).toBe(true);
			expect(canSchedule('user')).toBe(true);
		});

		it('blocks guest and read-only', () => {
			expect(canSchedule('basic')).toBe(false);
			expect(canSchedule('readonly')).toBe(false);
		});
	});

	describe('canConfigure / canManageUsers — Owner only', () => {
		it('allows only owner', () => {
			expect(canConfigure('admin')).toBe(true);
			expect(canManageUsers('admin')).toBe(true);
		});

		it('blocks every non-owner role', () => {
			for (const role of ['user', 'basic', 'readonly', null, undefined, 'nonsense']) {
				expect(canConfigure(role)).toBe(false);
				expect(canManageUsers(role)).toBe(false);
			}
		});
	});

	describe('friendlyRoleName', () => {
		it('maps backend roles to UI labels', () => {
			expect(friendlyRoleName('admin')).toBe('Owner');
			expect(friendlyRoleName('user')).toBe('User');
			expect(friendlyRoleName('basic')).toBe('Guest');
			expect(friendlyRoleName('readonly')).toBe('Read-only');
		});

		it('falls back to the raw value for unknown roles and empty for none', () => {
			expect(friendlyRoleName('weird')).toBe('weird');
			expect(friendlyRoleName(null)).toBe('');
			expect(friendlyRoleName(undefined)).toBe('');
		});
	});

	describe('visibleTabs — role-filtered navigation', () => {
		it('owner sees Home, Schedule, Setup in order', () => {
			expect(visibleTabs('admin')).toEqual(['home', 'schedule', 'setup']);
		});

		it('user sees Home and Schedule', () => {
			expect(visibleTabs('user')).toEqual(['home', 'schedule']);
		});

		it('guest sees Home only', () => {
			expect(visibleTabs('basic')).toEqual(['home']);
		});

		it('unknown/none still gets Home so the app never renders empty', () => {
			expect(visibleTabs(null)).toEqual(['home']);
			expect(visibleTabs('readonly')).toEqual(['home']);
		});
	});
});
