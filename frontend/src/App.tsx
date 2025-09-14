import React, { useState } from 'react'
import { Settings, RefreshCw } from 'lucide-react'
import { MobileLayout, StatusBar } from './components/layout/MobileLayout'
import { TemperatureDisplay } from './components/temperature/TemperatureDisplay'
import { TargetSelector } from './components/controls/TargetSelector'
import { QuickSchedule } from './components/schedule/QuickSchedule'
import { ScheduleList } from './components/schedule/ScheduleList'
import { ActionButtons } from './components/controls/ActionButtons'
import { ComponentShowcase } from './components/ComponentShowcase'
import { Button } from './components/ui/button'
import { useMockHotTub, useMockScenarios } from './hooks/useMockData'

function App() {
  const [targetTemp, setTargetTemp] = useState(102)
  const [showShowcase, setShowShowcase] = useState(false)

  const mockData = useMockHotTub()
  const scenarios = useMockScenarios()

  // Show component showcase for development
  if (showShowcase) {
    return <ComponentShowcase />
  }

  return (
    <MobileLayout>
      {/* Status bar with app title and dev controls */}
      <StatusBar
        title="Hot Tub Controller"
        subtitle={`Current: ${mockData.temperature.current.toFixed(1)}°F • Target: ${targetTemp}°F`}
        actions={
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setShowShowcase(true)}
            >
              <Settings className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={mockData.actions.refreshAll}
              disabled={mockData.loading}
            >
              <RefreshCw className={`h-4 w-4 ${mockData.loading ? 'animate-spin' : ''}`} />
            </Button>
          </div>
        }
      />

      {/* Main interface sections */}
      <div className="space-y-6">
        {/* Temperature Display - most prominent */}
        <TemperatureDisplay
          temperature={mockData.temperature}
          status={mockData.systemStatus}
          onRefresh={mockData.actions.refreshTemperature}
          loading={mockData.loading}
        />

        {/* Heating Controls */}
        <ActionButtons
          systemStatus={mockData.systemStatus}
          targetTemp={targetTemp}
          onStartHeating={() => mockData.actions.startHeating(targetTemp)}
          onStopHeating={mockData.actions.stopHeating}
          onCancelScheduled={() => {
            const scheduledEvents = mockData.events.filter(e => e.status === 'scheduled')
            if (scheduledEvents.length > 0) {
              mockData.actions.cancelEvent(scheduledEvents[0].id)
            }
          }}
          loading={mockData.loading}
        />

        {/* Target Temperature Selector */}
        <TargetSelector
          value={targetTemp}
          min={96}
          max={104}
          step={0.25}
          unit="fahrenheit"
          onChange={setTargetTemp}
          disabled={mockData.systemStatus.isHeating}
        />

        {/* Quick Schedule Options */}
        <QuickSchedule
          presets={mockData.presets}
          targetTemp={targetTemp}
          onSchedule={mockData.actions.scheduleEvent}
          disabled={mockData.systemStatus.isHeating}
          loading={mockData.loading}
        />

        {/* Schedule List */}
        <ScheduleList
          events={mockData.events}
          onCancel={mockData.actions.cancelEvent}
          loading={mockData.loading}
        />

        {/* Development scenario switcher */}
        <div className="bg-white rounded-lg p-4 shadow-sm">
          <div className="text-sm font-medium text-gray-700 mb-2">
            Development Scenarios:
          </div>
          <div className="flex flex-wrap gap-2">
            {scenarios.scenarios.map((scenario) => (
              <Button
                key={scenario.id}
                variant={scenarios.activeScenario === scenario.id ? "default" : "outline"}
                size="sm"
                onClick={() => scenarios.switchScenario(scenario.id)}
              >
                {scenario.name}
              </Button>
            ))}
          </div>
        </div>
      </div>
    </MobileLayout>
  )
}

export default App
