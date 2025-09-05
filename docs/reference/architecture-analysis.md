# Hot Tub Controller Architecture Analysis

*Analysis of the existing Tasker-based hot tub control system*

## Overview

The current hot tub control system is built using Android Tasker automation. It provides temperature monitoring, scheduling, and manual control of hot tub heating elements through a combination of sensors, webhook triggers, and a custom UI interface.

## Current System Components

### User Interface: Hot Tub Start Time Picker Scene

The main user interface is a Tasker scene with the following elements:

#### Display Elements
- **Target Temperature Picker**: Range selector (86-104°F) with current value displayed
- **Current Water Temperature**: Shows `%HOTTUBTEMP_F` in Fahrenheit 
- **Outside Temperature**: Shows `%AMBIENTTEMP_F` for ambient conditions
- **Heat Status**: Shows current heating state (`%HotTubHeat`)
- **Estimated Time to Target**: Shows `%HOTTUB_TIME_TO_TARGET` in minutes
- **Target Time**: Shows `%HOTTUB_CLOCKTIME_TO_TARGET` when temperature will be reached
- **Scheduled On Time**: Shows `%HotTubOnTime` for next scheduled heating

#### Control Elements
- **Preset Time Buttons**: Quick schedule options (5:00am, 5:30am, 6:00am, 6:30am, 7:00am, 7:30am)
- **Custom Time Button**: "+7.5 hours" for relative scheduling
- **Manual Override Buttons**: 
  - "Heat On" - immediate heating activation
  - "Heat Off" - immediate heating deactivation
- **Refresh Button**: Updates temperature readings
- **Cancel Button**: Cancels scheduled heating
- **Exit Button**: Closes the interface

### Variables and State Management

#### Temperature Variables
- `%HOTTUBTEMP_F` - Current hot tub water temperature in Fahrenheit
- `%HOTTUBTARGETTEMP` - Target temperature setting (86-104°F range)
- `%AMBIENTTEMP_F` - Outside/ambient temperature

#### Timing Variables
- `%HOTTUB_TIME_TO_TARGET` - Estimated minutes to reach target temperature
- `%HOTTUB_CLOCKTIME_TO_TARGET` - Clock time when target will be reached
- `%HotTubOnTime` - Scheduled start time for heating
- `%HotTubHeatOnTime` - Timestamp when heating was turned on
- `%HotTubHeatOffTime` - Timestamp when heating was turned off

#### Status Variables
- `%HotTubHeat` - Current heating status (on/off/heating)
- `%HOT_TUB_READY_NOTIFY_TITLE` - Notification title when target reached

#### API Configuration
- `%WEBHOOK` - IFTTT webhook key (`cqlRsKtaWLMkXuUmp4-kY-`)
- `%WTAG_API_KEY` - WirelessTag API bearer token
- `%WTAG_HOT_TUB_DEVICE_ID` - Hot tub temperature sensor ID (0)
- `%WTAG_BEDROOM_TEMP_DEVICE_ID` - Ambient temperature sensor ID (1)

### External API Integrations

#### IFTTT Webhooks
The system uses IFTTT Maker webhooks to control smart devices:

**Hot Tub Control:**
- `hot-tub-heat-on` - Activates hot tub heater
- `hot-tub-heat-off` - Deactivates hot tub heater
- `Hot-tub-heat-pump-on` - Activates heat pump (2-hour timer)
- `turn-on-hot-tub-ionizer` - Enables water ionizer
- `turn-off-hot-tub-ionizer` - Disables water ionizer

#### WirelessTag Temperature Sensors
Integration with WirelessTag cloud service for temperature monitoring:

**API Endpoints:**
- `https://wirelesstag.net/ethClient.asmx/GetTagList` - Retrieves sensor data
- `https://wirelesstag.net/ethClient.asmx/RequestImmediatePostback` - Forces sensor reading

**Authentication:** Bearer token in Authorization header

### Core Tasks and Automation Logic

#### Temperature Calculation (`Calc Time To Heat Hot Tub`)
Estimates heating time based on:
- Current temperature vs target temperature
- Heating rate: ~2 minutes per degree when heater is on
- Additional 4-minute startup time when heater is off
- Formula: `Round((%HOTTUBTARGETTEMP-%HOTTUBTEMP_F)*2)` (with heater on)

#### Smart Scheduling Logic
**Profiles that trigger hot tub heating:**
- `AmDroid Starts Hot Tub` - Triggered by alarm clock app
- `Hot Tub On At Time` - Variable-based scheduling
- `Set Heater If Cold At 1pm` - Conditional heating at 1:00 PM
- `Set Heater If Cold At 10am` - Conditional heating at 10:00 AM  
- `Set Heater If Cold 630am` - Daily heating at 6:30 AM

**Conditional Logic:**
- Only heats if ambient temperature is low enough
- Considers both high and low daily temperature forecasts
- Location-based (only when "Near 1707" - home location)

#### Critical Control Logic: "Turn Off Hot Tub At Temp" Task
This is the core temperature control algorithm that manages the heating cycle. It's complex in Tasker but would be straightforward in a modern language:

**Adaptive Monitoring Loop:**
1. **Initial Setup**: 
   - `wait_time_min = 19`, `wait_time_sec = 45` (default polling interval)
   - `loop_count = 0` (safety counter)

2. **Dynamic Wait Times Based on Temperature Difference:**
   ```
   Temp Diff > 10°F  → Wait 19min 45sec (far from target)
   Temp Diff 5-10°F  → Wait 9min 45sec  (approaching target)  
   Temp Diff 1-5°F   → Wait 1min 45sec  (close to target)
   Temp Diff 0-1°F   → Wait 0min 15sec  (very close, check frequently)
   ```

3. **Target Temperature Logic:**
   - If target > 102°F: Add 0.2°F buffer (`target_temp = %HOTTUBTARGETTEMP + 0.2`)
   - Otherwise: Use exact target (`target_temp = %HOTTUBTARGETTEMP`)

4. **Control Loop:**
   - Get fresh temperature reading via `Force Get New Hot Tub Temp`
   - Calculate `temp_difference = target_temp - current_temp`
   - If temp_difference ≤ 0: **SUCCESS** - Turn off heat, send notification, exit
   - If temp_difference > 0: Wait according to adaptive schedule, repeat

5. **Safety Mechanisms:**
   - **Loop Timeout**: Exit with error after 20 iterations (prevents infinite loops)
   - **Data Validation**: Check if temp_difference is a valid number (> -20°F)
   - **Over-Temperature Protection**: If loop_count > 12, force shorter intervals
   - **Error Logging**: Comprehensive error messages with timestamps and sensor data

6. **Success Actions:**
   - Send "Hot Tub Ready" notification with final temperature
   - Execute `Hot Tub Heat Off` task (turns off IFTTT webhooks)
   - Exit with success status

**Why This Logic is Critical:**
- **Prevents Overheating**: Stops heating precisely at target temperature
- **Energy Efficiency**: Reduces polling frequency when far from target
- **Safety First**: Multiple failsafes prevent runaway heating
- **Reliability**: Handles sensor failures and communication errors gracefully

This sophisticated control algorithm would be ~10 lines in modern code but requires 60+ Tasker actions due to limited conditional logic and loop constructs.

#### Additional Safety and Monitoring
- `Turn Off Plugs - Safety` - Regular safety shutoff (10:05-10:12 AM)
- Error handling and timeout protection (21-loop max)
- Notification system for heating completion

### Data Flow

1. **Temperature Monitoring**: WirelessTag sensors → API → Tasker variables
2. **User Input**: Scene interface → Task execution → Webhook triggers
3. **Automated Scheduling**: Time/location/temperature triggers → Heating logic
4. **Device Control**: IFTTT webhooks → Smart plugs/switches → Physical equipment
5. **Status Updates**: Device feedback → Variable updates → UI refresh

## System Strengths

- **Comprehensive Logic**: Handles complex scheduling with weather-based conditions
- **Safety Features**: Multiple failsafes and automatic shutoffs
- **Rich UI**: Full-featured control interface with real-time data
- **Integration**: Works with multiple smart home platforms
- **Reliability**: Error handling and notification system

## System Limitations

- **Platform Dependency**: Android Tasker only - not cross-platform
- **Complexity**: Difficult to modify or debug complex automation logic
- **Single User**: No multi-user access or permissions
- **Local Only**: Requires physical proximity to Android device
- **Vendor Lock-in**: Dependent on IFTTT and WirelessTag services
- **No History**: Limited data logging and historical tracking

## Key Requirements for New System

Based on the analysis, the replacement system must provide:

1. **Real-time temperature monitoring** from multiple sensors
2. **Preset scheduling buttons** for common start times
3. **Manual override controls** for immediate heating
4. **Smart scheduling logic** with weather conditions
5. **Safety shutoffs** and timeout protection  
6. **Cross-platform access** via web interface
7. **Push notifications** when heating completes
8. **Historical data** and usage tracking