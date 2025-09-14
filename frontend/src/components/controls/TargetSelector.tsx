import React, { useState, useCallback } from 'react'
import { Minus, Plus, Target } from 'lucide-react'
import { Card, CardContent } from '../ui/card'
import { Button } from '../ui/button'
import { TargetSelectorProps } from '../../types/heating'
import { formatTemperature, vibrate, cn } from '../../lib/utils'

export const TargetSelector: React.FC<TargetSelectorProps> = ({
  value,
  min,
  max,
  step,
  unit,
  onChange,
  disabled = false
}) => {
  const [isChanging, setIsChanging] = useState(false)

  const handleDecrease = useCallback(() => {
    if (disabled || value <= min) return

    const newValue = Math.max(min, value - step)
    onChange(newValue)
    vibrate(50) // Light haptic feedback
    setIsChanging(true)
    setTimeout(() => setIsChanging(false), 200)
  }, [value, min, step, onChange, disabled])

  const handleIncrease = useCallback(() => {
    if (disabled || value >= max) return

    const newValue = Math.min(max, value + step)
    onChange(newValue)
    vibrate(50) // Light haptic feedback
    setIsChanging(true)
    setTimeout(() => setIsChanging(false), 200)
  }, [value, max, step, onChange, disabled])

  // Handle long press for continuous increment/decrement
  const [longPressTimer, setLongPressTimer] = useState<NodeJS.Timeout | null>(null)
  const [longPressActive, setLongPressActive] = useState(false)

  const startLongPress = useCallback((action: 'increase' | 'decrease') => {
    if (disabled) return

    setLongPressActive(true)
    vibrate(100) // Stronger feedback for long press

    const timer = setInterval(() => {
      if (action === 'increase') {
        handleIncrease()
      } else {
        handleDecrease()
      }
    }, 150) // Repeat every 150ms

    setLongPressTimer(timer)
  }, [handleIncrease, handleDecrease, disabled])

  const endLongPress = useCallback(() => {
    if (longPressTimer) {
      clearInterval(longPressTimer)
      setLongPressTimer(null)
    }
    setLongPressActive(false)
  }, [longPressTimer])

  const canDecrease = !disabled && value > min
  const canIncrease = !disabled && value < max

  return (
    <Card className="w-full">
      <CardContent className="p-6">
        <div className="flex items-center justify-center gap-2 mb-4">
          <Target className="h-5 w-5 text-primary-500" />
          <span className="text-lg font-medium text-gray-700">Target Temperature</span>
        </div>

        <div className="flex items-center justify-center gap-4">
          {/* Decrease button */}
          <Button
            variant="outline"
            size="lg"
            className={cn(
              "h-16 w-16 rounded-full flex-shrink-0 text-xl font-bold transition-all",
              canDecrease ? "hover:bg-blue-50 hover:border-blue-300" : "opacity-50 cursor-not-allowed",
              longPressActive && "scale-95"
            )}
            disabled={!canDecrease}
            onClick={handleDecrease}
            onMouseDown={() => setTimeout(() => startLongPress('decrease'), 500)}
            onMouseUp={endLongPress}
            onMouseLeave={endLongPress}
            onTouchStart={() => setTimeout(() => startLongPress('decrease'), 500)}
            onTouchEnd={endLongPress}
          >
            <Minus className="h-6 w-6" />
          </Button>

          {/* Temperature display */}
          <div className="flex-1 text-center">
            <div className={cn(
              "text-temp-medium text-primary-600 font-bold transition-all duration-200",
              isChanging && "scale-110"
            )}>
              {formatTemperature(value, unit)}
            </div>
            <div className="text-sm text-gray-500 mt-1">
              Range: {formatTemperature(min, unit)} - {formatTemperature(max, unit)}
            </div>
            {step !== 1 && (
              <div className="text-xs text-gray-400 mt-1">
                Step: {formatTemperature(step, unit)}
              </div>
            )}
          </div>

          {/* Increase button */}
          <Button
            variant="outline"
            size="lg"
            className={cn(
              "h-16 w-16 rounded-full flex-shrink-0 text-xl font-bold transition-all",
              canIncrease ? "hover:bg-red-50 hover:border-red-300" : "opacity-50 cursor-not-allowed",
              longPressActive && "scale-95"
            )}
            disabled={!canIncrease}
            onClick={handleIncrease}
            onMouseDown={() => setTimeout(() => startLongPress('increase'), 500)}
            onMouseUp={endLongPress}
            onMouseLeave={endLongPress}
            onTouchStart={() => setTimeout(() => startLongPress('increase'), 500)}
            onTouchEnd={endLongPress}
          >
            <Plus className="h-6 w-6" />
          </Button>
        </div>

        {/* Quick preset buttons for common temperatures */}
        <div className="flex justify-center gap-2 mt-6">
          {[100, 101, 102, 103, 104].map((temp) => (
            <Button
              key={temp}
              variant={value === temp ? "default" : "ghost"}
              size="sm"
              className={cn(
                "min-w-12 text-sm transition-all",
                value === temp && "ring-2 ring-primary-500 ring-offset-2"
              )}
              disabled={disabled || temp < min || temp > max}
              onClick={() => {
                if (temp !== value) {
                  onChange(temp)
                  vibrate(30)
                  setIsChanging(true)
                  setTimeout(() => setIsChanging(false), 200)
                }
              }}
            >
              {temp}°
            </Button>
          ))}
        </div>

        {disabled && (
          <div className="text-center mt-4 text-sm text-gray-500">
            Temperature adjustment disabled during heating
          </div>
        )}
      </CardContent>
    </Card>
  )
}