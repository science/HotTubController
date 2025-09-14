import { useState, useEffect, useCallback, useRef } from 'react'
import {
  Temperature,
  HeatingEvent,
  HeatingSystemStatus,
  SchedulePreset,
  LoadingState,
  MockScenario
} from '../types/heating'
import {
  createMockTemperature,
  createMockEvents,
  createMockSystemStatus,
  defaultPresets,
  mockScenarios,
  TemperatureSimulator,
  simulateDelay,
  generateRandomTemp,
  calculateScheduleTime
} from '../mock/data'

// Global state for mock scenario
let currentScenario: string = 'normal'
let temperatureSimulator: TemperatureSimulator | null = null

// Hook for mock temperature data with realistic updates
export const useMockTemperature = (refreshInterval = 5000) => {
  const [temperature, setTemperature] = useState<Temperature>(() =>
    createMockTemperature(97.9, 102)
  )
  const [loading, setLoading] = useState<LoadingState>('idle')
  const [lastRefresh, setLastRefresh] = useState(new Date())

  const refresh = useCallback(async () => {
    setLoading('loading')

    try {
      await simulateDelay(Math.random() * 500 + 200) // 200-700ms delay

      const scenario = mockScenarios.find(s => s.id === currentScenario)
      if (scenario) {
        // If heating, use simulator for realistic progression
        if (scenario.status.isHeating && temperatureSimulator) {
          const currentTemp = temperatureSimulator.getCurrentTemp()
          setTemperature(prev => createMockTemperature(
            currentTemp,
            prev.target,
            new Date()
          ))
        } else {
          // Add small random variation to simulate real sensor readings
          const baseTemp = scenario.temperature.current
          const randomTemp = generateRandomTemp(baseTemp, 0.1)
          setTemperature(prev => createMockTemperature(
            randomTemp,
            prev.target,
            new Date()
          ))
        }
      }

      setLoading('success')
      setLastRefresh(new Date())
    } catch (error) {
      setLoading('error')
      console.error('Failed to refresh temperature:', error)
    }
  }, [])

  // Auto-refresh interval
  useEffect(() => {
    const interval = setInterval(refresh, refreshInterval)
    return () => clearInterval(interval)
  }, [refresh, refreshInterval])

  // Initial load
  useEffect(() => {
    refresh()
  }, [refresh])

  return {
    temperature,
    loading,
    lastRefresh,
    refresh
  }
}

// Hook for mock system status
export const useMockSystemStatus = (refreshInterval = 3000) => {
  const [status, setStatus] = useState<HeatingSystemStatus>(() =>
    createMockSystemStatus(false, 0)
  )
  const [loading, setLoading] = useState<LoadingState>('idle')

  const refresh = useCallback(async () => {
    setLoading('loading')

    try {
      await simulateDelay(300)

      const scenario = mockScenarios.find(s => s.id === currentScenario)
      if (scenario) {
        let updatedStatus = { ...scenario.status }

        // Update progress if heating
        if (scenario.status.isHeating && temperatureSimulator) {
          updatedStatus.progress = temperatureSimulator.getProgress()

          // Check if heating is complete
          if (temperatureSimulator.isComplete()) {
            updatedStatus.isHeating = false
            updatedStatus.status = 'idle'
            updatedStatus.activeUntil = undefined
            updatedStatus.equipmentStatus.heater = false
            temperatureSimulator = null
          }
        }

        setStatus(updatedStatus)
      }

      setLoading('success')
    } catch (error) {
      setLoading('error')
      console.error('Failed to refresh system status:', error)
    }
  }, [])

  // Auto-refresh interval
  useEffect(() => {
    const interval = setInterval(refresh, refreshInterval)
    return () => clearInterval(interval)
  }, [refresh, refreshInterval])

  // Initial load
  useEffect(() => {
    refresh()
  }, [refresh])

  return {
    status,
    loading,
    refresh
  }
}

// Hook for mock heating events
export const useMockEvents = () => {
  const [events, setEvents] = useState<HeatingEvent[]>(() => createMockEvents())
  const [loading, setLoading] = useState<LoadingState>('idle')

  const refresh = useCallback(async () => {
    setLoading('loading')

    try {
      await simulateDelay(400)

      const scenario = mockScenarios.find(s => s.id === currentScenario)
      if (scenario) {
        setEvents(scenario.events)
      }

      setLoading('success')
    } catch (error) {
      setLoading('error')
      console.error('Failed to refresh events:', error)
    }
  }, [])

  const scheduleEvent = useCallback(async (preset: SchedulePreset, targetTemp: number) => {
    setLoading('loading')

    try {
      await simulateDelay(800)

      const scheduledTime = calculateScheduleTime(preset)
      const newEvent: HeatingEvent = {
        id: `event-${Date.now()}`,
        scheduledTime,
        targetTemp,
        status: 'scheduled',
        createdAt: new Date()
      }

      setEvents(prev => [newEvent, ...prev])
      setLoading('success')

      return newEvent
    } catch (error) {
      setLoading('error')
      throw error
    }
  }, [])

  const cancelEvent = useCallback(async (eventId: string) => {
    setLoading('loading')

    try {
      await simulateDelay(500)

      setEvents(prev => prev.map(event =>
        event.id === eventId
          ? { ...event, status: 'cancelled' as const }
          : event
      ))

      setLoading('success')
    } catch (error) {
      setLoading('error')
      throw error
    }
  }, [])

  const startHeating = useCallback(async (targetTemp: number) => {
    setLoading('loading')

    try {
      await simulateDelay(1000)

      // Create temperature simulator
      temperatureSimulator = new TemperatureSimulator(97.9, targetTemp)

      // Create active event
      const activeEvent: HeatingEvent = {
        id: `active-${Date.now()}`,
        scheduledTime: new Date(),
        targetTemp,
        status: 'active',
        createdAt: new Date(),
        startedAt: new Date()
      }

      setEvents(prev => [activeEvent, ...prev])

      // Switch to heating scenario
      currentScenario = 'heating'

      setLoading('success')
      return activeEvent
    } catch (error) {
      setLoading('error')
      throw error
    }
  }, [])

  const stopHeating = useCallback(async () => {
    setLoading('loading')

    try {
      await simulateDelay(600)

      // Stop simulator
      temperatureSimulator = null

      // Mark active event as completed
      setEvents(prev => prev.map(event =>
        event.status === 'active'
          ? { ...event, status: 'completed' as const, completedAt: new Date() }
          : event
      ))

      // Switch back to normal scenario
      currentScenario = 'normal'

      setLoading('success')
    } catch (error) {
      setLoading('error')
      throw error
    }
  }, [])

  // Initial load
  useEffect(() => {
    refresh()
  }, [refresh])

  return {
    events,
    loading,
    refresh,
    scheduleEvent,
    cancelEvent,
    startHeating,
    stopHeating
  }
}

// Hook for schedule presets
export const useMockPresets = () => {
  const [presets] = useState<SchedulePreset[]>(defaultPresets)
  const [loading] = useState<LoadingState>('success')

  return {
    presets,
    loading
  }
}

// Hook for managing mock scenarios (for development)
export const useMockScenarios = () => {
  const [scenarios] = useState<MockScenario[]>(mockScenarios)
  const [activeScenario, setActiveScenario] = useState<string>(currentScenario)

  const switchScenario = useCallback((scenarioId: string) => {
    const scenario = scenarios.find(s => s.id === scenarioId)
    if (scenario) {
      currentScenario = scenarioId
      setActiveScenario(scenarioId)

      // Reset temperature simulator if switching away from heating
      if (scenarioId !== 'heating') {
        temperatureSimulator = null
      } else {
        // Initialize simulator for heating scenario
        temperatureSimulator = new TemperatureSimulator(
          scenario.temperature.current,
          scenario.temperature.target
        )
      }
    }
  }, [scenarios])

  return {
    scenarios,
    activeScenario,
    switchScenario
  }
}

// Combined hook that provides all mock data
export const useMockHotTub = () => {
  const temperature = useMockTemperature()
  const systemStatus = useMockSystemStatus()
  const events = useMockEvents()
  const presets = useMockPresets()

  const refreshAll = useCallback(async () => {
    await Promise.all([
      temperature.refresh(),
      systemStatus.refresh(),
      events.refresh()
    ])
  }, [temperature.refresh, systemStatus.refresh, events.refresh])

  const isLoading = temperature.loading === 'loading' ||
                   systemStatus.loading === 'loading' ||
                   events.loading === 'loading'

  return {
    temperature: temperature.temperature,
    systemStatus: systemStatus.status,
    events: events.events,
    presets: presets.presets,
    loading: isLoading,
    actions: {
      refreshAll,
      refreshTemperature: temperature.refresh,
      scheduleEvent: events.scheduleEvent,
      cancelEvent: events.cancelEvent,
      startHeating: events.startHeating,
      stopHeating: events.stopHeating
    }
  }
}