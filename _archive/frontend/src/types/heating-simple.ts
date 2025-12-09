// Simplified types to test module resolution
export type TemperatureUnit = 'fahrenheit' | 'celsius'

export interface Temperature {
  current: number
  target: number
  unit: TemperatureUnit
  lastUpdated: Date
  sensorId?: string
}

export interface HeatingSystemStatus {
  status: 'idle' | 'heating' | 'scheduled' | 'error' | 'offline'
  isHeating: boolean
  isConnected: boolean
  activeUntil?: Date
  progress: number
  equipmentStatus: {
    heater: boolean
    pump: boolean
    ionizer?: boolean
  }
}