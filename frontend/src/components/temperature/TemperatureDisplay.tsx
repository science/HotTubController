import React from 'react'
import { RefreshCw, Thermometer, AlertTriangle, Wifi, WifiOff } from 'lucide-react'
import { Card, CardContent } from '../ui/card'
import { Badge } from '../ui/badge'
import { Button } from '../ui/button'
import { Progress } from '../ui/progress'
import { TemperatureDisplayProps } from '../../types/heating'
import { formatTemperature, formatRelativeTime, cn } from '../../lib/utils'

export const TemperatureDisplay: React.FC<TemperatureDisplayProps> = ({
  temperature,
  status,
  onRefresh,
  loading = false
}) => {
  const isConnected = status.isConnected
  const progress = status.progress * 100
  const isHeating = status.isHeating

  // Calculate time remaining if actively heating
  const timeRemaining = status.activeUntil
    ? Math.max(0, Math.ceil((status.activeUntil.getTime() - Date.now()) / (1000 * 60)))
    : 0

  // Determine connection status badge
  const getConnectionStatus = () => {
    if (!isConnected) {
      return { variant: 'destructive' as const, label: 'Offline', icon: WifiOff }
    }
    return { variant: 'success' as const, label: 'Connected', icon: Wifi }
  }

  const connectionStatus = getConnectionStatus()

  return (
    <Card className="w-full">
      <CardContent className="p-6">
        {/* Status bar with connection and refresh */}
        <div className="flex justify-between items-center mb-6">
          <div className="flex items-center gap-2">
            <connectionStatus.icon className="h-4 w-4" />
            <Badge variant={connectionStatus.variant} className="text-xs">
              {connectionStatus.label}
            </Badge>
          </div>

          <Button
            variant="ghost"
            size="icon"
            onClick={onRefresh}
            disabled={loading}
            className={cn(
              "h-8 w-8",
              loading && "animate-spin"
            )}
          >
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>

        {/* Main temperature display */}
        <div className="text-center mb-6">
          <div className="flex items-center justify-center gap-2 mb-2">
            <Thermometer className="h-8 w-8 text-primary-500" />
            {status.errorMessage && (
              <AlertTriangle className="h-6 w-6 text-red-500" />
            )}
          </div>

          {/* Current temperature */}
          <div className={cn(
            "temperature-display mb-2 transition-all duration-500",
            !isConnected && "opacity-50 text-gray-400"
          )}>
            {isConnected ? formatTemperature(temperature.current, temperature.unit) : '--.-°F'}
          </div>

          {/* Target temperature */}
          <div className="text-lg text-gray-600 mb-1">
            Target: {formatTemperature(temperature.target, temperature.unit)}
          </div>

          {/* Last updated */}
          <div className="text-sm text-gray-500">
            {isConnected
              ? `Updated ${formatRelativeTime(temperature.lastUpdated)} ago`
              : 'Connection lost'
            }
          </div>
        </div>

        {/* Heating progress */}
        {isHeating && isConnected && (
          <div className="mb-4">
            <div className="flex justify-between items-center mb-2">
              <span className="text-sm font-medium text-gray-700">
                Heating Progress
              </span>
              <span className="text-sm text-gray-500">
                {Math.round(progress)}%
              </span>
            </div>
            <Progress
              value={progress}
              className="h-3 mb-2"
            />
            <div className="text-center">
              <Badge variant="default" className="text-xs">
                🔥 Heating Active
              </Badge>
              {timeRemaining > 0 && (
                <span className="text-xs text-gray-500 ml-2">
                  ~{timeRemaining} minutes remaining
                </span>
              )}
            </div>
          </div>
        )}

        {/* Status indicators */}
        <div className="flex justify-center gap-2 mb-4">
          {/* Heating status */}
          {isHeating && (
            <Badge variant="default" className="flex items-center gap-1">
              <div className="w-2 h-2 bg-white rounded-full animate-pulse" />
              Heating
            </Badge>
          )}

          {/* Equipment status */}
          {status.equipmentStatus.heater && (
            <Badge variant="warning">Heater On</Badge>
          )}

          {status.equipmentStatus.pump && (
            <Badge variant="secondary">Pump Running</Badge>
          )}

          {status.equipmentStatus.ionizer && (
            <Badge variant="secondary">Ionizer Active</Badge>
          )}
        </div>

        {/* Error message */}
        {status.errorMessage && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-3 text-center">
            <AlertTriangle className="h-4 w-4 text-red-500 inline mr-2" />
            <span className="text-sm text-red-700">{status.errorMessage}</span>
          </div>
        )}

        {/* Temperature differential indicator */}
        {isConnected && !status.errorMessage && (
          <div className="mt-4 text-center">
            {(() => {
              const diff = temperature.target - temperature.current
              if (Math.abs(diff) < 0.5) {
                return (
                  <div className="text-green-600 text-sm font-medium">
                    ✓ At target temperature
                  </div>
                )
              } else if (diff > 0) {
                return (
                  <div className="text-blue-600 text-sm">
                    {formatTemperature(diff)} below target
                  </div>
                )
              } else {
                return (
                  <div className="text-orange-600 text-sm">
                    {formatTemperature(Math.abs(diff))} above target
                  </div>
                )
              }
            })()}
          </div>
        )}
      </CardContent>
    </Card>
  )
}