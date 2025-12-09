import React, { useState } from 'react'
import { Button } from './ui/button'
import { Badge } from './ui/badge'
import { TemperatureDisplay } from './temperature/TemperatureDisplay'
import { TargetSelector } from './controls/TargetSelector'
import { QuickSchedule } from './schedule/QuickSchedule'
import { ScheduleList } from './schedule/ScheduleList'
import { ActionButtons } from './controls/ActionButtons'
import { useMockHotTub, useMockScenarios } from '../hooks/useMockData'

export const ComponentShowcase: React.FC = () => {
  const [targetTemp, setTargetTemp] = useState(102)
  const mockData = useMockHotTub()
  const scenarios = useMockScenarios()

  return (
    <div className="min-h-screen bg-gray-50 p-4 space-y-6">
      {/* Scenario switcher for development */}
      <div className="bg-white rounded-lg p-4 shadow-sm">
        <h2 className="text-lg font-semibold mb-3">Mock Scenarios</h2>
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
        <div className="mt-2 text-sm text-gray-600">
          {scenarios.scenarios.find(s => s.id === scenarios.activeScenario)?.description}
        </div>
      </div>

      {/* Temperature Display */}
      <div>
        <h2 className="text-lg font-semibold mb-3">Temperature Display</h2>
        <TemperatureDisplay
          temperature={mockData.temperature}
          status={mockData.systemStatus}
          onRefresh={mockData.actions.refreshTemperature}
          loading={mockData.loading}
        />
      </div>

      {/* Target Selector */}
      <div>
        <h2 className="text-lg font-semibold mb-3">Target Selector</h2>
        <TargetSelector
          value={targetTemp}
          min={96}
          max={104}
          step={0.25}
          unit="fahrenheit"
          onChange={setTargetTemp}
          disabled={mockData.systemStatus.isHeating}
        />
      </div>

      {/* Quick Schedule */}
      <div>
        <h2 className="text-lg font-semibold mb-3">Quick Schedule</h2>
        <QuickSchedule
          presets={mockData.presets}
          targetTemp={targetTemp}
          onSchedule={mockData.actions.scheduleEvent}
          loading={mockData.loading}
        />
      </div>

      {/* Schedule List */}
      <div>
        <h2 className="text-lg font-semibold mb-3">Schedule List</h2>
        <ScheduleList
          events={mockData.events}
          onCancel={mockData.actions.cancelEvent}
          loading={mockData.loading}
        />
      </div>

      {/* Action Buttons */}
      <div>
        <h2 className="text-lg font-semibold mb-3">Action Buttons</h2>
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
      </div>

      {/* System Info */}
      <div className="bg-white rounded-lg p-4 shadow-sm">
        <h2 className="text-lg font-semibold mb-3">System Info</h2>
        <div className="space-y-2 text-sm">
          <div className="flex justify-between">
            <span>Status:</span>
            <Badge variant={mockData.systemStatus.isHeating ? 'default' : 'secondary'}>
              {mockData.systemStatus.status}
            </Badge>
          </div>
          <div className="flex justify-between">
            <span>Connected:</span>
            <Badge variant={mockData.systemStatus.isConnected ? 'success' : 'destructive'}>
              {mockData.systemStatus.isConnected ? 'Yes' : 'No'}
            </Badge>
          </div>
          <div className="flex justify-between">
            <span>Events:</span>
            <span>{mockData.events.length}</span>
          </div>
          <div className="flex justify-between">
            <span>Loading:</span>
            <Badge variant={mockData.loading ? 'default' : 'secondary'}>
              {mockData.loading ? 'Yes' : 'No'}
            </Badge>
          </div>
        </div>
      </div>
    </div>
  )
}