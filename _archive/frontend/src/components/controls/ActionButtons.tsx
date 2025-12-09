import React, { useState } from 'react'
import { Play, Square, X, AlertTriangle } from 'lucide-react'
import { Card, CardContent } from '../ui/card'
import { Button } from '../ui/button'
import { ActionButtonsProps } from '../../types/heating'
import { formatTemperature, vibrate, cn } from '../../lib/utils'

export const ActionButtons: React.FC<ActionButtonsProps> = ({
  systemStatus,
  targetTemp,
  onStartHeating,
  onStopHeating,
  onCancelScheduled,
  loading = false
}) => {
  const [confirmAction, setConfirmAction] = useState<string | null>(null)

  const isHeating = systemStatus.isHeating
  const hasScheduled = systemStatus.status === 'scheduled'
  const isError = systemStatus.status === 'error'

  const handleStartHeating = async () => {
    if (loading || isHeating) return

    try {
      vibrate([200, 100, 200]) // Start heating pattern
      await onStartHeating()
    } catch (error) {
      console.error('Failed to start heating:', error)
    }
  }

  const handleStopHeating = async () => {
    if (loading || !isHeating) return

    if (confirmAction !== 'stop') {
      setConfirmAction('stop')
      vibrate([100, 50, 100]) // Confirmation needed pattern
      return
    }

    try {
      setConfirmAction(null)
      vibrate([300, 100, 300]) // Stop heating pattern
      await onStopHeating()
    } catch (error) {
      console.error('Failed to stop heating:', error)
    }
  }

  const handleCancelScheduled = async () => {
    if (loading || !hasScheduled) return

    if (confirmAction !== 'cancel') {
      setConfirmAction('cancel')
      vibrate([100, 50, 100]) // Confirmation needed pattern
      return
    }

    try {
      setConfirmAction(null)
      vibrate([200, 100, 200]) // Cancel scheduled pattern
      await onCancelScheduled()
    } catch (error) {
      console.error('Failed to cancel scheduled heating:', error)
    }
  }

  // Clear confirmation after 5 seconds
  React.useEffect(() => {
    if (confirmAction) {
      const timer = setTimeout(() => setConfirmAction(null), 5000)
      return () => clearTimeout(timer)
    }
  }, [confirmAction])

  return (
    <Card className="w-full">
      <CardContent className="p-6">
        <div className="flex flex-col gap-4">
          {/* Main action button */}
          <div className="flex-1">
            {isHeating ? (
              // Stop heating button
              <Button
                variant="destructive"
                size="lg"
                className={cn(
                  "w-full h-14 text-lg font-semibold transition-all",
                  confirmAction === 'stop' && "ring-4 ring-red-500 ring-offset-2 animate-pulse"
                )}
                disabled={loading}
                onClick={handleStopHeating}
              >
                {loading ? (
                  <div className="flex items-center gap-2">
                    <div className="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent" />
                    Stopping...
                  </div>
                ) : confirmAction === 'stop' ? (
                  <div className="flex items-center gap-2">
                    <AlertTriangle className="h-5 w-5" />
                    Tap again to confirm
                  </div>
                ) : (
                  <div className="flex items-center gap-2">
                    <Square className="h-5 w-5" />
                    Stop Heating
                  </div>
                )}
              </Button>
            ) : (
              // Start heating button
              <Button
                variant="default"
                size="lg"
                className={cn(
                  "w-full h-14 text-lg font-semibold bg-gradient-to-r from-primary-500 to-primary-600",
                  "hover:from-primary-600 hover:to-primary-700 transition-all",
                  isError && "opacity-50 cursor-not-allowed"
                )}
                disabled={loading || isError || !systemStatus.isConnected}
                onClick={handleStartHeating}
              >
                {loading ? (
                  <div className="flex items-center gap-2">
                    <div className="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent" />
                    Starting...
                  </div>
                ) : (
                  <div className="flex items-center gap-2">
                    <Play className="h-5 w-5" />
                    Start Heating to {formatTemperature(targetTemp, 'fahrenheit')}
                  </div>
                )}
              </Button>
            )}
          </div>

          {/* Secondary actions */}
          <div className="flex gap-3">
            {/* Cancel scheduled heating */}
            {hasScheduled && (
              <Button
                variant="outline"
                size="lg"
                className={cn(
                  "flex-1 h-12 font-medium",
                  confirmAction === 'cancel' && "ring-2 ring-orange-500 ring-offset-2 bg-orange-50"
                )}
                disabled={loading}
                onClick={handleCancelScheduled}
              >
                {confirmAction === 'cancel' ? (
                  <div className="flex items-center gap-2">
                    <AlertTriangle className="h-4 w-4" />
                    Confirm Cancel
                  </div>
                ) : (
                  <div className="flex items-center gap-2">
                    <X className="h-4 w-4" />
                    Cancel Scheduled
                  </div>
                )}
              </Button>
            )}
          </div>

          {/* Status messages */}
          <div className="text-center space-y-2">
            {isError && (
              <div className="text-sm text-red-600 flex items-center justify-center gap-2">
                <AlertTriangle className="h-4 w-4" />
                System error - heating disabled
              </div>
            )}

            {!systemStatus.isConnected && (
              <div className="text-sm text-gray-500 flex items-center justify-center gap-2">
                <AlertTriangle className="h-4 w-4" />
                Waiting for sensor connection
              </div>
            )}

            {isHeating && systemStatus.activeUntil && (
              <div className="text-sm text-primary-600">
                Heating until {systemStatus.activeUntil.toLocaleTimeString('en-US', {
                  hour: 'numeric',
                  minute: '2-digit',
                  hour12: true
                })}
              </div>
            )}

            {hasScheduled && systemStatus.currentEvent && (
              <div className="text-sm text-blue-600">
                Next heating: {systemStatus.currentEvent.scheduledTime.toLocaleString('en-US', {
                  month: 'short',
                  day: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
                  hour12: true
                })}
              </div>
            )}

            {confirmAction && (
              <div className="text-xs text-gray-500">
                Tap again within 5 seconds to confirm
              </div>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  )
}