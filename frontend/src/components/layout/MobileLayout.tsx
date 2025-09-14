import React from 'react'
import { cn } from '../../lib/utils'

interface MobileLayoutProps {
  children: React.ReactNode
  className?: string
}

export const MobileLayout: React.FC<MobileLayoutProps> = ({ children, className }) => {
  return (
    <div className={cn(
      "min-h-screen bg-gray-50",
      "flex flex-col",
      // Support safe areas for mobile devices
      "pt-safe-top pb-safe-bottom pl-safe-left pr-safe-right",
      className
    )}>
      {/* Main content area with scroll */}
      <main className="flex-1 overflow-y-auto px-4 py-6 space-y-6">
        {children}
      </main>
    </div>
  )
}

interface StatusBarProps {
  title?: string
  subtitle?: string
  actions?: React.ReactNode
}

export const StatusBar: React.FC<StatusBarProps> = ({
  title = "Hot Tub Controller",
  subtitle,
  actions
}) => {
  return (
    <header className="bg-white border-b border-gray-200 px-4 py-4 pt-safe-top">
      <div className="flex items-center justify-between">
        <div className="flex-1 min-w-0">
          <h1 className="text-xl font-semibold text-gray-900 truncate">
            {title}
          </h1>
          {subtitle && (
            <p className="text-sm text-gray-600 truncate">
              {subtitle}
            </p>
          )}
        </div>
        {actions && (
          <div className="flex items-center gap-2 ml-4">
            {actions}
          </div>
        )}
      </div>
    </header>
  )
}

interface BottomActionsProps {
  children: React.ReactNode
  className?: string
}

export const BottomActions: React.FC<BottomActionsProps> = ({ children, className }) => {
  return (
    <div className={cn(
      "fixed bottom-0 left-0 right-0",
      "bg-white border-t border-gray-200",
      "px-4 py-4 pb-safe-bottom",
      "shadow-lg",
      className
    )}>
      {children}
    </div>
  )
}