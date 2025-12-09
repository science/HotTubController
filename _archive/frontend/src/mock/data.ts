import {
  Temperature,
  HeatingEvent,
  HeatingSystemStatus,
  SchedulePreset,
  MockScenario,
  TemperatureSimulation
} from '../types/heating'

// Constants based on real hot tub physics
export const HEATING_RATE = 0.5 // degrees F per minute
export const MIN_TEMP = 96
export const MAX_TEMP = 104
export const PRECISION_THRESHOLD = 1 // Within 1°F of target for precision monitoring

// Mock sensor data
export const createMockTemperature = (
  current: number = 97.9,
  target: number = 102,
  lastUpdated: Date = new Date()
): Temperature => ({
  current: Math.round(current * 10) / 10, // Round to 1 decimal place
  target,
  unit: 'fahrenheit',
  lastUpdated,
  sensorId: 'sensor-001'
})

// Create realistic temperature simulation
export class TemperatureSimulator {
  private simulation: TemperatureSimulation

  constructor(initialTemp: number, targetTemp: number) {
    this.simulation = {
      initialTemp,
      targetTemp,
      startTime: new Date(),
      currentTemp: initialTemp,
      progress: 0,
      isComplete: false
    }
  }

  // Get current temperature based on elapsed time
  getCurrentTemp(): number {
    const elapsed = Date.now() - this.simulation.startTime.getTime()
    const elapsedMinutes = elapsed / (1000 * 60)

    const totalTempChange = this.simulation.targetTemp - this.simulation.initialTemp
    const expectedChange = Math.min(elapsedMinutes * HEATING_RATE, Math.abs(totalTempChange))

    let currentTemp
    if (totalTempChange > 0) {
      // Heating up
      currentTemp = this.simulation.initialTemp + expectedChange
    } else {
      // Cooling down
      currentTemp = this.simulation.initialTemp - expectedChange
    }

    // Add small random variation (±0.1°F)
    currentTemp += (Math.random() - 0.5) * 0.2

    this.simulation.currentTemp = Math.round(currentTemp * 10) / 10
    this.simulation.progress = Math.abs(totalTempChange) > 0
      ? Math.min(expectedChange / Math.abs(totalTempChange), 1)
      : 1

    this.simulation.isComplete = Math.abs(this.simulation.targetTemp - this.simulation.currentTemp) < 0.25

    return this.simulation.currentTemp
  }

  getProgress(): number {
    this.getCurrentTemp() // Update progress
    return this.simulation.progress
  }

  isComplete(): boolean {
    this.getCurrentTemp() // Update completion status
    return this.simulation.isComplete
  }

  getEstimatedCompletion(): Date {
    const tempDiff = Math.abs(this.simulation.targetTemp - this.simulation.initialTemp)
    const estimatedMinutes = tempDiff / HEATING_RATE
    return new Date(this.simulation.startTime.getTime() + estimatedMinutes * 60 * 1000)
  }
}

// Mock heating events
export const createMockEvents = (): HeatingEvent[] => [
  {
    id: 'event-1',
    scheduledTime: new Date(Date.now() + 30 * 60 * 1000), // 30 minutes from now
    targetTemp: 102,
    duration: 45,
    status: 'scheduled',
    createdAt: new Date(Date.now() - 15 * 60 * 1000) // Created 15 minutes ago
  },
  {
    id: 'event-2',
    scheduledTime: new Date(Date.now() + 7.5 * 60 * 60 * 1000), // 7.5 hours from now (6:00 AM tomorrow)
    targetTemp: 104,
    status: 'scheduled',
    createdAt: new Date(Date.now() - 60 * 60 * 1000) // Created 1 hour ago
  },
  {
    id: 'event-3',
    scheduledTime: new Date(Date.now() - 2 * 60 * 60 * 1000), // 2 hours ago
    targetTemp: 100,
    duration: 60,
    status: 'completed',
    createdAt: new Date(Date.now() - 3 * 60 * 60 * 1000),
    startedAt: new Date(Date.now() - 2 * 60 * 60 * 1000),
    completedAt: new Date(Date.now() - 1 * 60 * 60 * 1000)
  }
]

// Mock system status
export const createMockSystemStatus = (
  isHeating = false,
  progress = 0.65
): HeatingSystemStatus => ({
  status: isHeating ? 'heating' : 'idle',
  isHeating,
  isConnected: true,
  activeUntil: isHeating ? new Date(Date.now() + 25 * 60 * 1000) : undefined,
  progress,
  currentEvent: isHeating ? createMockEvents()[0] : undefined,
  equipmentStatus: {
    heater: isHeating,
    pump: true, // Pump typically runs during heating
    ionizer: false
  }
})

// Quick schedule presets
export const defaultPresets: SchedulePreset[] = [
  { id: 'preset-4', label: '+7.5hr', type: 'relative', value: 450, description: 'Start in 7.5 hours' },
  { id: 'preset-5', label: '5:00 AM', type: 'absolute', value: '05:00', description: 'Next 5:00 AM' },
  { id: 'preset-6', label: '5:30 AM', type: 'absolute', value: '05:30', description: 'Next 5:30 AM' },
  { id: 'preset-7', label: '6:00 AM', type: 'absolute', value: '06:00', description: 'Next 6:00 AM' },
  { id: 'preset-8', label: '6:30 AM', type: 'absolute', value: '06:30', description: 'Next 6:30 AM' },
  { id: 'preset-9', label: '7:00 AM', type: 'absolute', value: '07:00', description: 'Next 7:00 AM' },
  { id: 'preset-10', label: '7:30 AM', type: 'absolute', value: '07:30', description: 'Next 7:30 AM' }
]

// Mock scenarios for testing different states
export const mockScenarios: MockScenario[] = [
  {
    id: 'normal',
    name: 'Normal Operation',
    description: 'Idle system with scheduled events',
    temperature: createMockTemperature(97.9, 102),
    status: createMockSystemStatus(false, 0),
    events: createMockEvents()
  },
  {
    id: 'heating',
    name: 'Currently Heating',
    description: 'System actively heating to target temperature',
    temperature: createMockTemperature(99.5, 102),
    status: createMockSystemStatus(true, 0.65),
    events: createMockEvents().map(event => ({
      ...event,
      status: event.id === 'event-1' ? 'active' as const : event.status
    }))
  },
  {
    id: 'error',
    name: 'Sensor Error',
    description: 'Temperature sensor communication error',
    temperature: createMockTemperature(0, 102, new Date(Date.now() - 10 * 60 * 1000)),
    status: {
      ...createMockSystemStatus(false, 0),
      status: 'error',
      isConnected: false,
      errorMessage: 'Temperature sensor timeout - last reading 10 minutes ago'
    },
    events: createMockEvents()
  },
  {
    id: 'near-completion',
    name: 'Near Target Temperature',
    description: 'Close to reaching target temperature',
    temperature: createMockTemperature(101.7, 102),
    status: createMockSystemStatus(true, 0.95),
    events: createMockEvents()
  }
]

// Utility functions for mock data
export const getNextAbsoluteTime = (timeString: string): Date => {
  const [hours, minutes] = timeString.split(':').map(Number)
  const now = new Date()
  const target = new Date()

  target.setHours(hours, minutes, 0, 0)

  // If time has passed today, schedule for tomorrow
  if (target.getTime() <= now.getTime()) {
    target.setDate(target.getDate() + 1)
  }

  return target
}

export const calculateScheduleTime = (preset: SchedulePreset): Date => {
  if (preset.type === 'relative') {
    return new Date(Date.now() + (preset.value as number) * 60 * 1000)
  } else {
    return getNextAbsoluteTime(preset.value as string)
  }
}

// Generate random temperature readings for realistic feel
export const generateRandomTemp = (baseTemp: number, variance = 0.2): number => {
  return Math.round((baseTemp + (Math.random() - 0.5) * variance) * 10) / 10
}

// Simulate network delay for realistic API feel
export const simulateDelay = (ms: number = 500): Promise<void> => {
  return new Promise(resolve => setTimeout(resolve, ms))
}