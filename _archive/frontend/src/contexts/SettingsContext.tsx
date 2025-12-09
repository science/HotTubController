import React, { createContext, useContext, useState, ReactNode } from 'react'

interface SettingsContextType {
  pollingEnabled: boolean
  setPollingEnabled: (enabled: boolean) => void
}

const SettingsContext = createContext<SettingsContextType | undefined>(undefined)

interface SettingsProviderProps {
  children: ReactNode
}

export const SettingsProvider: React.FC<SettingsProviderProps> = ({ children }) => {
  const [pollingEnabled, setPollingEnabled] = useState(false) // Default to off

  return (
    <SettingsContext.Provider
      value={{
        pollingEnabled,
        setPollingEnabled,
      }}
    >
      {children}
    </SettingsContext.Provider>
  )
}

export const useSettings = () => {
  const context = useContext(SettingsContext)
  if (context === undefined) {
    throw new Error('useSettings must be used within a SettingsProvider')
  }
  return context
}