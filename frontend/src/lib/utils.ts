import { type ClassValue, clsx } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatTemperature(temp: number, unit: 'fahrenheit' | 'celsius' = 'fahrenheit'): string {
  const rounded = Math.round(temp * 10) / 10
  return `${rounded}°${unit === 'fahrenheit' ? 'F' : 'C'}`
}

export function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const mins = minutes % 60

  if (hours === 0) return `${mins}min`
  if (mins === 0) return `${hours}hr`
  return `${hours}hr ${mins}min`
}

export function formatRelativeTime(date: Date): string {
  const now = new Date()
  const diffMs = date.getTime() - now.getTime()
  const diffMins = Math.round(diffMs / (1000 * 60))

  if (diffMins < 0) return 'Past'
  if (diffMins === 0) return 'Now'
  if (diffMins < 60) return `${diffMins} minutes`

  const diffHours = Math.round(diffMins / 60)
  if (diffHours < 24) return `${diffHours} hours`

  const diffDays = Math.round(diffHours / 24)
  return `${diffDays} days`
}

export function vibrate(pattern: number | number[] = 100) {
  if ('vibrate' in navigator) {
    navigator.vibrate(pattern)
  }
}