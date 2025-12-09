import React, { useState } from 'react'
import { Clock, Calendar, Zap, Check } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card'
import { Button } from '../ui/button'
import { Badge } from '../ui/badge'
import { QuickScheduleProps } from '../../types/heating'
import { formatTemperature, formatRelativeTime, vibrate, cn } from '../../lib/utils'
import { calculateScheduleTime } from '../../mock/data'

export const QuickSchedule: React.FC<QuickScheduleProps> = ({
  presets,
  targetTemp,
  onSchedule,
  disabled = false,
  loading = false
}) => {
  const [lastScheduled, setLastScheduled] = useState<string | null>(null)

  const handlePresetClick = async (preset: any) => {
    if (disabled || loading) return

    try {
      vibrate(100) // Haptic feedback
      setLastScheduled(preset.id)

      await onSchedule(preset)

      // Clear the "just scheduled" state after animation
      setTimeout(() => setLastScheduled(null), 2000)
    } catch (error) {
      setLastScheduled(null)
      console.error('Failed to schedule heating:', error)
    }
  }

  const getScheduleTime = (preset: any) => {
    try {
      const scheduleTime = calculateScheduleTime(preset)
      return scheduleTime
    } catch {
      return null
    }
  }

  const formatPresetTime = (preset: any) => {
    const scheduleTime = getScheduleTime(preset)
    if (!scheduleTime) return preset.label

    if (preset.type === 'relative') {
      return formatRelativeTime(scheduleTime)
    } else {
      return scheduleTime.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
      })
    }
  }

  return (
    <Card className="w-full">
      <CardHeader className="pb-4">
        <CardTitle className="flex items-center gap-2 text-lg">
          <Zap className="h-5 w-5 text-primary-500" />
          Quick Schedule
        </CardTitle>
        <div className="text-sm text-gray-600">
          Schedule heating to {formatTemperature(targetTemp, 'fahrenheit')}
        </div>
      </CardHeader>

      <CardContent className="pt-0">
        {/* Preset buttons grid */}
        <div className="grid grid-cols-2 gap-3">
          {presets.map((preset) => {
            const isJustScheduled = lastScheduled === preset.id
            const scheduleTime = getScheduleTime(preset)
            const isRelative = preset.type === 'relative'

            return (
              <Button
                key={preset.id}
                variant={isJustScheduled ? "default" : "outline"}
                className={cn(
                  "h-auto p-4 flex flex-col items-center gap-2 text-center transition-all",
                  isJustScheduled && "ring-2 ring-primary-500 ring-offset-2 bg-green-500 hover:bg-green-600",
                  !isJustScheduled && !disabled && "hover:bg-primary-50 hover:border-primary-300"
                )}
                disabled={disabled || loading || !scheduleTime}
                onClick={() => handlePresetClick(preset)}
              >
                {/* Icon */}
                <div className="flex items-center justify-center">
                  {isJustScheduled ? (
                    <Check className="h-5 w-5 animate-pulse" />
                  ) : isRelative ? (
                    <Clock className="h-5 w-5" />
                  ) : (
                    <Calendar className="h-5 w-5" />
                  )}
                </div>

                {/* Main label */}
                <div className="font-medium">
                  {preset.label}
                </div>

                {/* Time description */}
                <div className={cn(
                  "text-xs opacity-80",
                  isJustScheduled && "text-white",
                  !isJustScheduled && "text-gray-600"
                )}>
                  {isJustScheduled ? "Scheduled!" : formatPresetTime(preset)}
                </div>

                {/* Additional info for absolute times */}
                {!isRelative && scheduleTime && !isJustScheduled && (
                  <Badge variant="secondary" className="text-xs">
                    {scheduleTime.toLocaleDateString('en-US', {
                      month: 'short',
                      day: 'numeric'
                    })}
                  </Badge>
                )}
              </Button>
            )
          })}
        </div>

        {/* Start now button */}
        <div className="mt-6 pt-4 border-t">
          <Button
            variant="default"
            size="lg"
            className="w-full font-medium"
            disabled={disabled || loading}
            onClick={() => handlePresetClick({
              id: 'start-now',
              label: 'Start Now',
              type: 'relative',
              value: 0
            })}
          >
            <Zap className="h-5 w-5 mr-2" />
            Start Heating Now
          </Button>
        </div>

        {/* Status message */}
        {disabled && (
          <div className="mt-4 text-center text-sm text-gray-500">
            Scheduling disabled while heating is active
          </div>
        )}

        {loading && (
          <div className="mt-4 text-center text-sm text-primary-600">
            <div className="animate-pulse">Scheduling heating...</div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}