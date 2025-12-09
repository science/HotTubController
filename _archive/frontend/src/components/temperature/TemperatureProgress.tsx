import React from 'react'
import { cn } from '../../lib/utils'

interface TemperatureProgressProps {
  current: number
  target: number
  min?: number
  max?: number
  className?: string
}

export const TemperatureProgress: React.FC<TemperatureProgressProps> = ({
  current,
  target,
  min = 96,
  max = 104,
  className
}) => {
  // Calculate positions as percentages
  const range = max - min
  const currentPosition = Math.min(Math.max((current - min) / range, 0), 1) * 100
  const targetPosition = Math.min(Math.max((target - min) / range, 0), 1) * 100

  return (
    <div className={cn("relative h-2 bg-gray-200 rounded-full overflow-hidden", className)}>
      {/* Background gradient representing temperature range */}
      <div className="absolute inset-0 bg-gradient-to-r from-blue-300 via-green-300 to-orange-300" />

      {/* Target temperature indicator */}
      <div
        className="absolute top-0 w-1 h-full bg-gray-600 shadow-sm"
        style={{ left: `${targetPosition}%`, transform: 'translateX(-50%)' }}
      >
        {/* Target label */}
        <div className="absolute -top-8 left-1/2 transform -translate-x-1/2">
          <div className="bg-gray-700 text-white text-xs px-2 py-1 rounded whitespace-nowrap">
            Target: {target}°F
          </div>
        </div>
      </div>

      {/* Current temperature indicator */}
      <div
        className={cn(
          "absolute top-0 w-3 h-full rounded-full shadow-lg border-2 border-white transition-all duration-500",
          Math.abs(current - target) < 0.5
            ? "bg-green-500"
            : current < target
            ? "bg-blue-500"
            : "bg-orange-500"
        )}
        style={{ left: `${currentPosition}%`, transform: 'translateX(-50%)' }}
      >
        {/* Current temperature label */}
        <div className="absolute -bottom-8 left-1/2 transform -translate-x-1/2">
          <div className="bg-gray-900 text-white text-xs px-2 py-1 rounded font-medium whitespace-nowrap">
            {current}°F
          </div>
        </div>
      </div>

      {/* Temperature scale markers */}
      <div className="absolute -bottom-12 left-0 right-0 flex justify-between text-xs text-gray-400">
        <span>{min}°F</span>
        <span>{((min + max) / 2).toFixed(0)}°F</span>
        <span>{max}°F</span>
      </div>
    </div>
  )
}