import { base } from '$app/paths';

export interface ApiResponse {
	success: boolean;
	action: string;
	timestamp: string;
	duration?: number;
}

// API base path - backend is at {base}/backend/public
const API_BASE = `${base}/backend/public`;

async function post(endpoint: string): Promise<ApiResponse> {
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

export const api = {
	heaterOn: () => post('/api/equipment/heater/on'),
	heaterOff: () => post('/api/equipment/heater/off'),
	pumpRun: () => post('/api/equipment/pump/run')
};
