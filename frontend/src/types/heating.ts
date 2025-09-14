// Core temperature and sensor types
export type TemperatureUnit = 'fahrenheit' | 'celsius'

export interface Temperature {
  current: number
  target: number
  unit: TemperatureUnit
  lastUpdated: Date
  sensorId?: string
}

export interface SensorStatus {
  connected: boolean
  batteryLevel?: number
  signalStrength?: number
  lastSeen: Date
}

// Heating event and schedule types
export type HeatingEventStatus = 'scheduled' | 'active' | 'completed' | 'cancelled' | 'failed'

export interface HeatingEvent {
  id: string
  scheduledTime: Date
  targetTemp: number
  duration?: number // minutes
  status: HeatingEventStatus
  createdAt: Date
  startedAt?: Date
  completedAt?: Date
  failureReason?: string
}

// System status types
export type SystemStatus = 'idle' | 'heating' | 'scheduled' | 'error' | 'offline'

export interface HeatingSystemStatus {
  status: SystemStatus
  isHeating: boolean
  isConnected: boolean
  activeUntil?: Date
  progress: number // 0-1 for heating progress
  currentEvent?: HeatingEvent
  errorMessage?: string
  equipmentStatus: {
    heater: boolean
    pump: boolean
    ionizer?: boolean
  }
}

// Quick schedule presets
export type PresetType = 'relative' | 'absolute'

export interface SchedulePreset {
  id: string
  label: string
  type: PresetType
  // For relative: minutes from now
  // For absolute: target time (e.g., "6:00", "6:30")
  value: number | string
  description?: string
}

// API response types
export interface ApiResponse<T = unknown> {
  success: boolean
  data?: T
  error?: string
  timestamp: string
}

export interface TemperatureResponse extends ApiResponse<Temperature> {
  data: Temperature
}

export interface StatusResponse extends ApiResponse<HeatingSystemStatus> {
  data: HeatingSystemStatus
}

export interface EventsResponse extends ApiResponse<HeatingEvent[]> {
  data: HeatingEvent[]
}

export interface ScheduleResponse extends ApiResponse<HeatingEvent> {
  data: HeatingEvent
}

// UI State types
export type LoadingState = 'idle' | 'loading' | 'success' | 'error'

export interface UIState {
  loadingState: LoadingState
  error?: string
  lastRefresh: Date
}

// Component prop types
export interface TemperatureDisplayProps {
  temperature: Temperature
  status: HeatingSystemStatus
  onRefresh: () => void
  loading?: boolean
}

export interface TargetSelectorProps {
  value: number
  min: number
  max: number
  step: number
  unit: TemperatureUnit
  onChange: (value: number) => void
  disabled?: boolean
}

export interface QuickScheduleProps {
  presets: SchedulePreset[]
  targetTemp: number
  onSchedule: (preset: SchedulePreset) => void
  disabled?: boolean
  loading?: boolean
}

export interface ScheduleListProps {
  events: HeatingEvent[]
  onCancel: (eventId: string) => void
  onEdit?: (eventId: string) => void
  loading?: boolean
}

export interface ActionButtonsProps {
  systemStatus: HeatingSystemStatus
  targetTemp: number
  onStartHeating: () => void
  onStopHeating: () => void
  onCancelScheduled: () => void
  loading?: boolean
}

// Mock data types for development
export interface MockScenario {
  id: string
  name: string
  description: string
  temperature: Temperature
  status: HeatingSystemStatus
  events: HeatingEvent[]
}

export interface TemperatureSimulation {
  initialTemp: number
  targetTemp: number
  startTime: Date
  currentTemp: number
  progress: number
  isComplete: boolean
}