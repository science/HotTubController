import React, { useState } from 'react'
import { Calendar, Clock, Trash2, Play, CheckCircle, XCircle, AlertCircle } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card'
import { Badge } from '../ui/badge'
import { Button } from '../ui/button'
import { ScheduleListProps, HeatingEvent } from '../../types/heating'
import { formatTemperature, formatDuration, vibrate, cn } from '../../lib/utils'

interface ScheduleCardProps {
  event: HeatingEvent
  onCancel: (eventId: string) => void
  loading?: boolean
}

const ScheduleCard: React.FC<ScheduleCardProps> = ({ event, onCancel, loading = false }) => {
  const [isDeleting, setIsDeleting] = useState(false)
  const [showConfirm, setShowConfirm] = useState(false)

  const handleDelete = async () => {
    if (event.status === 'active' || loading) return

    try {
      setIsDeleting(true)
      vibrate([100, 50, 100]) // Delete feedback pattern

      await onCancel(event.id)
    } catch (error) {
      setIsDeleting(false)
      console.error('Failed to cancel event:', error)
    }
  }

  const handleLongPress = () => {
    if (event.status === 'scheduled' && !loading) {
      setShowConfirm(true)
      vibrate(200) // Long press feedback
    }
  }

  // Format time display
  const formatEventTime = (date: Date) => {
    const now = new Date()
    const diffMs = date.getTime() - now.getTime()
    const diffMins = Math.round(diffMs / (1000 * 60))

    // If it's today or tomorrow, show relative time
    if (diffMins < 24 * 60 && diffMins > -24 * 60) {
      if (diffMins < 0) return 'Past'
      if (diffMins < 60) return `${diffMins} minutes`
      if (diffMins < 24 * 60) return `${Math.round(diffMins / 60)} hours`
    }

    return date.toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    })
  }

  // Get status styling
  const getStatusProps = (status: HeatingEvent['status']) => {
    switch (status) {
      case 'active':
        return {
          badge: { variant: 'default' as const, label: '🔥 Heating', icon: Play },
          card: 'border-primary-300 bg-primary-50'
        }
      case 'scheduled':
        return {
          badge: { variant: 'secondary' as const, label: '⏰ Scheduled', icon: Clock },
          card: 'border-gray-200 hover:border-gray-300'
        }
      case 'completed':
        return {
          badge: { variant: 'success' as const, label: '✅ Complete', icon: CheckCircle },
          card: 'border-green-200 bg-green-50 opacity-75'
        }
      case 'cancelled':
        return {
          badge: { variant: 'secondary' as const, label: '❌ Cancelled', icon: XCircle },
          card: 'border-gray-200 opacity-60'
        }
      case 'failed':
        return {
          badge: { variant: 'destructive' as const, label: '⚠️ Failed', icon: AlertCircle },
          card: 'border-red-200 bg-red-50'
        }
      default:
        return {
          badge: { variant: 'secondary' as const, label: status, icon: Clock },
          card: 'border-gray-200'
        }
    }
  }

  const statusProps = getStatusProps(event.status)

  return (
    <Card
      className={cn(
        "transition-all duration-200 touch-target",
        statusProps.card,
        isDeleting && "scale-95 opacity-50",
        showConfirm && "ring-2 ring-red-500 ring-offset-2"
      )}
      onTouchStart={() => {
        const timeout = setTimeout(handleLongPress, 800)
        const cleanup = () => clearTimeout(timeout)
        document.addEventListener('touchend', cleanup, { once: true })
        document.addEventListener('touchmove', cleanup, { once: true })
      }}
    >
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-3">
          {/* Event info */}
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-2">
              <statusProps.badge.icon className="h-4 w-4 text-gray-600" />
              <Badge variant={statusProps.badge.variant} className="text-xs">
                {statusProps.badge.label}
              </Badge>
            </div>

            <div className="space-y-1">
              {/* Target temperature */}
              <div className="font-medium text-gray-900">
                Target: {formatTemperature(event.targetTemp, 'fahrenheit')}
              </div>

              {/* Scheduled time */}
              <div className="text-sm text-gray-600">
                {formatEventTime(event.scheduledTime)}
              </div>

              {/* Duration if specified */}
              {event.duration && (
                <div className="text-sm text-gray-500">
                  Duration: {formatDuration(event.duration)}
                </div>
              )}

              {/* Failure reason */}
              {event.failureReason && (
                <div className="text-sm text-red-600">
                  {event.failureReason}
                </div>
              )}
            </div>
          </div>

          {/* Action button */}
          <div className="flex-shrink-0">
            {event.status === 'scheduled' && !loading && (
              <Button
                variant="ghost"
                size="sm"
                className={cn(
                  "text-red-600 hover:text-red-700 hover:bg-red-50 transition-colors",
                  isDeleting && "opacity-50 cursor-not-allowed"
                )}
                disabled={isDeleting}
                onClick={handleDelete}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            )}
          </div>
        </div>

        {/* Confirmation overlay */}
        {showConfirm && (
          <div className="absolute inset-0 bg-white/90 flex items-center justify-center gap-2 rounded-lg">
            <Button
              variant="destructive"
              size="sm"
              onClick={() => {
                setShowConfirm(false)
                handleDelete()
              }}
            >
              Delete
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setShowConfirm(false)}
            >
              Cancel
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

export const ScheduleList: React.FC<ScheduleListProps> = ({
  events,
  onCancel,
  loading = false
}) => {
  // Separate events by status
  const activeEvents = events.filter(e => e.status === 'active')
  const scheduledEvents = events.filter(e => e.status === 'scheduled')
  const pastEvents = events.filter(e => ['completed', 'cancelled', 'failed'].includes(e.status))

  // Sort events by scheduled time
  const sortedScheduledEvents = [...scheduledEvents].sort(
    (a, b) => a.scheduledTime.getTime() - b.scheduledTime.getTime()
  )
  const sortedPastEvents = [...pastEvents].sort(
    (a, b) => (b.completedAt || b.scheduledTime).getTime() - (a.completedAt || a.scheduledTime).getTime()
  )

  const hasEvents = events.length > 0

  return (
    <Card className="w-full">
      <CardHeader className="pb-4">
        <CardTitle className="flex items-center gap-2 text-lg">
          <Calendar className="h-5 w-5 text-primary-500" />
          Heating Schedule
        </CardTitle>
        <div className="text-sm text-gray-600">
          {hasEvents ? `${events.length} events` : 'No scheduled events'}
        </div>
      </CardHeader>

      <CardContent className="pt-0 space-y-4">
        {!hasEvents ? (
          <div className="text-center py-8 text-gray-500">
            <Calendar className="h-12 w-12 mx-auto mb-3 opacity-50" />
            <div className="text-lg font-medium mb-2">No scheduled events</div>
            <div className="text-sm">
              Use Quick Schedule above to schedule heating sessions
            </div>
          </div>
        ) : (
          <>
            {/* Active heating events */}
            {activeEvents.length > 0 && (
              <div className="space-y-3">
                <h3 className="font-medium text-gray-900 flex items-center gap-2">
                  <Play className="h-4 w-4" />
                  Active
                </h3>
                {activeEvents.map(event => (
                  <ScheduleCard
                    key={event.id}
                    event={event}
                    onCancel={onCancel}
                    loading={loading}
                  />
                ))}
              </div>
            )}

            {/* Scheduled events */}
            {sortedScheduledEvents.length > 0 && (
              <div className="space-y-3">
                <h3 className="font-medium text-gray-900 flex items-center gap-2">
                  <Clock className="h-4 w-4" />
                  Scheduled ({sortedScheduledEvents.length})
                </h3>
                {sortedScheduledEvents.map(event => (
                  <ScheduleCard
                    key={event.id}
                    event={event}
                    onCancel={onCancel}
                    loading={loading}
                  />
                ))}
              </div>
            )}

            {/* Past events (limited to 3 most recent) */}
            {sortedPastEvents.length > 0 && (
              <div className="space-y-3">
                <h3 className="font-medium text-gray-900 flex items-center gap-2">
                  <CheckCircle className="h-4 w-4" />
                  Recent History
                </h3>
                {sortedPastEvents.slice(0, 3).map(event => (
                  <ScheduleCard
                    key={event.id}
                    event={event}
                    onCancel={onCancel}
                    loading={loading}
                  />
                ))}
                {sortedPastEvents.length > 3 && (
                  <div className="text-center text-sm text-gray-500 py-2">
                    +{sortedPastEvents.length - 3} more events
                  </div>
                )}
              </div>
            )}
          </>
        )}

        {/* Loading state */}
        {loading && (
          <div className="text-center py-4">
            <div className="animate-pulse text-sm text-gray-500">
              Updating schedule...
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}