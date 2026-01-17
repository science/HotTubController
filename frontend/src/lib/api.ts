import { base } from '$app/paths';

export interface ApiResponse {
	success: boolean;
	action: string;
	timestamp: string;
	duration?: number;
}

export interface ScheduledJob {
	jobId: string;
	action: string;
	scheduledTime: string;
	createdAt: string;
	recurring: boolean;
}

export interface ScheduleListResponse {
	jobs: ScheduledJob[];
}

export interface User {
	username: string;
	role: string;
	created_at: string;
}

export interface TemperatureData {
	water_temp_f: number | null;
	water_temp_c: number | null;
	ambient_temp_f: number | null;
	ambient_temp_c: number | null;
	device_name: string;
	timestamp: string;
	source?: string;
	device_id?: string;
	uptime_seconds?: number;
	sensors?: Esp32Sensor[];
	error?: string;
	error_code?: string;
}

export interface AllTemperaturesResponse {
	esp32: TemperatureData | null;
}

export type SensorRole = 'water' | 'ambient' | 'unassigned';

export interface Esp32Sensor {
	address: string;
	temp_c: number;
	temp_f?: number;
	role: SensorRole;
	calibration_offset: number;
	name: string;
}

export interface Esp32SensorListResponse {
	sensors: Esp32Sensor[];
}

export interface SensorUpdateRequest {
	role?: SensorRole;
	calibration_offset?: number;
	name?: string;
}

export interface SensorUpdateResponse {
	sensor: {
		address: string;
		role: SensorRole;
		calibration_offset: number;
		name: string;
	};
}

export interface EquipmentState {
	on: boolean;
	lastChangedAt: string | null;
}

export interface EquipmentStatus {
	heater: EquipmentState;
	pump: EquipmentState;
}

export interface HealthResponse {
	status: string;
	ifttt_mode: string;
	equipmentStatus: EquipmentStatus;
	blindsEnabled?: boolean;
}

export interface UserListResponse {
	users: User[];
}

export interface CreateUserResponse {
	username: string;
	password: string;
	role: string;
	message: string;
}

// API base path - backend is at {base}/backend/public
const API_BASE = `${base}/backend/public`;

async function post<T = ApiResponse>(endpoint: string): Promise<T> {
	const response = await fetch(`${API_BASE}${endpoint}`, {
		method: 'POST',
		credentials: 'include'
	});
	if (!response.ok) {
		if (response.status === 401) {
			throw new Error('Unauthorized');
		}
		throw new Error('Request failed');
	}
	return response.json();
}

async function postJson<T>(endpoint: string, data: object): Promise<T> {
	const response = await fetch(`${API_BASE}${endpoint}`, {
		method: 'POST',
		credentials: 'include',
		headers: {
			'Content-Type': 'application/json'
		},
		body: JSON.stringify(data)
	});
	if (!response.ok) {
		if (response.status === 401) {
			throw new Error('Unauthorized');
		}
		const errorBody = await response.json().catch(() => ({}));
		throw new Error(errorBody.error || 'Request failed');
	}
	return response.json();
}

async function get<T>(endpoint: string): Promise<T> {
	const response = await fetch(`${API_BASE}${endpoint}`, {
		method: 'GET',
		credentials: 'include'
	});
	if (!response.ok) {
		if (response.status === 401) {
			throw new Error('Unauthorized');
		}
		if (response.status === 403) {
			throw new Error('Forbidden');
		}
		// Try to extract error message from response body
		const errorBody = await response.json().catch(() => ({}));
		throw new Error(errorBody.error || 'Request failed');
	}
	return response.json();
}

async function del(endpoint: string): Promise<{ success: boolean }> {
	const response = await fetch(`${API_BASE}${endpoint}`, {
		method: 'DELETE',
		credentials: 'include'
	});
	if (!response.ok) {
		if (response.status === 401) {
			throw new Error('Unauthorized');
		}
		if (response.status === 403) {
			throw new Error('Forbidden');
		}
		throw new Error('Request failed');
	}
	return response.json();
}

async function put<T>(endpoint: string, data: object): Promise<T> {
	const response = await fetch(`${API_BASE}${endpoint}`, {
		method: 'PUT',
		credentials: 'include',
		headers: {
			'Content-Type': 'application/json'
		},
		body: JSON.stringify(data)
	});
	if (!response.ok) {
		if (response.status === 401) {
			throw new Error('Unauthorized');
		}
		if (response.status === 403) {
			throw new Error('Forbidden');
		}
		const errorBody = await response.json().catch(() => ({}));
		throw new Error(errorBody.error || 'Request failed');
	}
	return response.json();
}

export const api = {
	// Health/status endpoint
	getHealth: () => get<HealthResponse>('/api/health'),

	heaterOn: () => post('/api/equipment/heater/on'),
	heaterOff: () => post('/api/equipment/heater/off'),
	pumpRun: () => post('/api/equipment/pump/run'),

	// Blinds endpoints (optional feature)
	blindsOpen: () => post('/api/blinds/open'),
	blindsClose: () => post('/api/blinds/close'),

	// Temperature endpoints
	getTemperature: () => get<TemperatureData>('/api/temperature'),
	getAllTemperatures: () => get<AllTemperaturesResponse>('/api/temperature/all'),

	// Schedule endpoints
	scheduleJob: (action: string, scheduledTime: string, recurring: boolean = false) =>
		postJson<ScheduledJob>('/api/schedule', { action, scheduledTime, recurring }),
	listScheduledJobs: () => get<ScheduleListResponse>('/api/schedule'),
	cancelScheduledJob: (jobId: string) => del(`/api/schedule/${jobId}`),

	// User management endpoints (admin only)
	listUsers: () => get<UserListResponse>('/api/users'),
	createUser: (username: string, password: string, role: string = 'user') =>
		postJson<CreateUserResponse>('/api/users', { username, password, role }),
	deleteUser: (username: string) => del(`/api/users/${username}`),
	updateUserPassword: (username: string, password: string) =>
		put<{ success: boolean }>(`/api/users/${username}/password`, { password }),

	// ESP32 sensor configuration endpoints
	listEsp32Sensors: () => get<Esp32SensorListResponse>('/api/esp32/sensors'),
	updateEsp32Sensor: (address: string, data: SensorUpdateRequest) =>
		put<SensorUpdateResponse>(`/api/esp32/sensors/${encodeURIComponent(address)}`, data)
};
