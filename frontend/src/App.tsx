import React, { useState } from 'react'
import { RefreshCw } from 'lucide-react'
import { useMockHotTub, useMockScenarios } from './hooks/useMockData'
import { SettingsProvider, useSettings } from './contexts/SettingsContext'
import { Switch } from './components/ui/switch'

function AppContent() {
  const [targetTemp, setTargetTemp] = useState(102)
  const { pollingEnabled, setPollingEnabled } = useSettings()
  const mockData = useMockHotTub()
  const scenarios = useMockScenarios()

  const formatStatus = () => {
    if (mockData.systemStatus.isHeating) return 'HEATING'
    if (mockData.systemStatus.hasScheduled) return 'SCHEDULED'
    return 'IDLE'
  }

  const statusColor = () => {
    if (mockData.systemStatus.isHeating) return 'var(--color-red)'
    if (mockData.systemStatus.hasScheduled) return 'var(--color-yellow)'
    return 'var(--color-green)'
  }

  return (
    <div style={{ maxWidth: '1200px', margin: '0 auto', padding: '0.5rem' }}>
      {/* Compact responsive layout */}
      {/* Status bar - temp display with ON/OFF */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem', gap: '0.5rem' }}>
        {/* Left: Temp and status */}
        <div style={{ display: 'flex', alignItems: 'baseline', gap: '0.75rem' }}>
          <div style={{ fontSize: '2.5rem', lineHeight: 1, fontFamily: 'var(--font-family-mono)' }}>
            {mockData.temperature.current.toFixed(1)}°F
          </div>
          <div style={{ fontSize: '0.75rem', color: statusColor() }}>
            {formatStatus()}
          </div>
        </div>

        {/* Right: ON/OFF switch only */}
        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
          <Switch
            checked={mockData.systemStatus.isHeating}
            onCheckedChange={(checked) => {
              if (checked) {
                mockData.actions.startHeating(targetTemp)
              } else {
                mockData.actions.stopHeating()
              }
            }}
            disabled={mockData.loading}
          />
          <span style={{ fontSize: '0.75rem', fontWeight: 500 }}>
            {mockData.systemStatus.isHeating ? 'ON' : 'OFF'}
          </span>
        </div>
      </div>

      {/* Target temp with Cancel All and Refresh tucked alongside */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '1rem', flexWrap: 'wrap' }}>
        <span style={{ fontSize: '0.75rem', color: 'var(--color-text-dim)' }}>Target:</span>
        <input
          type="number"
          value={targetTemp}
          onChange={(e) => setTargetTemp(parseFloat(e.target.value))}
          min={96}
          max={104}
          step={0.25}
          disabled={mockData.systemStatus.isHeating}
          style={{
            background: 'transparent',
            border: '1px solid var(--color-border)',
            color: 'var(--color-text)',
            padding: '0.25rem 0.5rem',
            fontSize: '0.875rem',
            fontFamily: 'var(--font-family-mono)',
            width: '60px'
          }}
        />
        <span style={{ fontSize: '0.875rem' }}>°F</span>

        {/* Cancel All and Refresh tucked here */}
        {mockData.events.filter(e => e.status === 'scheduled').length > 0 && (
          <button
            onClick={() => {
              mockData.events.filter(e => e.status === 'scheduled').forEach(e => mockData.actions.cancelEvent(e.id))
            }}
            disabled={mockData.loading}
            style={{ padding: '0.25rem 0.5rem', fontSize: '0.75rem', marginLeft: 'auto' }}
          >
            Cancel All
          </button>
        )}
        <button
          onClick={mockData.actions.refreshAll}
          disabled={mockData.loading}
          style={{
            padding: '0.25rem',
            minWidth: 'auto',
            width: '28px',
            height: '28px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            marginLeft: mockData.events.filter(e => e.status === 'scheduled').length === 0 ? 'auto' : '0'
          }}
          title="Refresh"
        >
          <RefreshCw style={{ width: '14px', height: '14px' }} />
        </button>
      </div>

      {/* Two column grid: Schedule (left) and Events (right) */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '1rem', alignItems: 'start' }}>

        {/* Left Column: Schedule (appears first on mobile) */}
        <div>
          <div style={{ fontSize: '0.65rem', color: 'var(--color-text-dim)', marginBottom: '0.5rem', textTransform: 'uppercase' }}>
            Schedule to {targetTemp}°F
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.5rem' }}>
            {['5:00 AM', '5:30 AM', '6:00 AM', '6:30 AM', '7:00 AM', '7:30 AM'].map((time) => {
              const preset = mockData.presets.find(p => p.label === time)
              return preset ? (
                <button
                  key={preset.id}
                  onClick={() => mockData.actions.scheduleEvent(preset, targetTemp)}
                  disabled={mockData.systemStatus.isHeating || mockData.loading}
                  style={{ padding: '0.5rem', fontSize: '0.75rem' }}
                >
                  {time}
                </button>
              ) : null
            })}
            {(() => {
              const preset = mockData.presets.find(p => p.label === '+7.5hr')
              return preset ? (
                <button
                  key={preset.id}
                  onClick={() => mockData.actions.scheduleEvent(preset, targetTemp)}
                  disabled={mockData.systemStatus.isHeating || mockData.loading}
                  style={{ padding: '0.5rem', fontSize: '0.75rem', gridColumn: '1 / -1' }}
                >
                  +7.5hr
                </button>
              ) : null
            })()}
          </div>
        </div>

        {/* Right Column: Events (appears second on mobile) */}
        {mockData.events.length > 0 && (
          <div>
            <div style={{ fontSize: '0.65rem', color: 'var(--color-text-dim)', marginBottom: '0.25rem', textTransform: 'uppercase' }}>
              Events
            </div>
            {mockData.events.map((event) => (
              <div
                key={event.id}
                style={{
                  border: '1px solid var(--color-border)',
                  padding: '0.5rem',
                  marginBottom: '0.25rem',
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  fontSize: '0.75rem'
                }}
              >
                <div>
                  <div>{event.status.toUpperCase()}: {event.targetTemp}°F</div>
                  <div style={{ fontSize: '0.65rem', color: 'var(--color-text-dim)' }}>
                    {new Date(event.startTime).toLocaleString()}
                  </div>
                </div>
                {event.status === 'scheduled' && (
                  <button
                    onClick={() => mockData.actions.cancelEvent(event.id)}
                    style={{ padding: '0.25rem 0.5rem', fontSize: '0.65rem' }}
                  >
                    X
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Dev Controls */}
      <div style={{ marginTop: '3rem', borderTop: '1px solid var(--color-border)', paddingTop: '1rem' }}>
        <div style={{ fontSize: '0.875rem', color: 'var(--color-text-dim)', marginBottom: '1rem' }}>
          DEVELOPMENT
        </div>
        <div style={{ marginBottom: '1rem' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer' }}>
            <input
              type="checkbox"
              checked={pollingEnabled}
              onChange={(e) => setPollingEnabled(e.target.checked)}
            />
            Auto-refresh (polls every 2-5s)
          </label>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {scenarios.scenarios.map((scenario) => (
            <button
              key={scenario.id}
              onClick={() => scenarios.switchScenario(scenario.id)}
              style={{
                background: scenarios.activeScenario === scenario.id ? 'var(--color-hover)' : 'transparent'
              }}
            >
              {scenario.name}
            </button>
          ))}
        </div>
      </div>
    </div>
  )
}

function App() {
  return (
    <SettingsProvider>
      <AppContent />
    </SettingsProvider>
  )
}

export default App
