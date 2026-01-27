#ifndef REPORT_SCHEDULER_H
#define REPORT_SCHEDULER_H

#include <cstdint>

// Scheduler state enumeration
enum SchedulerState {
    STATE_BOOT_SEND,      // Initial state - send immediately
    STATE_INTERVAL_WAIT,  // Waiting for interval to elapse
    STATE_ALIGN_WAIT,     // Waiting for :55 second mark
    STATE_READY_TO_SEND   // Ready to send - shouldSend() returns true
};

/**
 * Interface for time providers - allows dependency injection for testing.
 * Production code uses Esp32TimeProvider, tests use MockTimeProvider.
 */
class TimeProvider {
public:
    virtual ~TimeProvider() = default;

    // Get current Unix timestamp (seconds since epoch)
    virtual uint32_t getCurrentTime() const = 0;

    // Get current second of minute (0-59)
    virtual int getSecondOfMinute() const = 0;

    // Check if NTP has synced at least once
    virtual bool isTimeSynced() const = 0;
};

/**
 * Schedules temperature reports with optional :55 second alignment.
 *
 * Designed for robustness:
 * - Falls back to interval-only timing if NTP not synced
 * - Never blocks - always returns quickly
 * - State machine always progresses (no deadlocks)
 * - Sanity checks prevent infinite waits
 *
 * Pure C++ implementation with no Arduino dependencies for testability.
 */
class ReportScheduler {
public:
    // Configuration constants (prefixed to avoid macro conflicts)
    static const int SCHED_DEFAULT_INTERVAL_SEC = 300;
    static const int SCHED_DEFAULT_ALIGN_SECOND = 53;  // Target :53 for margin before :00 cron
    static const int SCHED_MIN_INTERVAL_FOR_ALIGNMENT = 60;
    static const int SCHED_ALIGNMENT_WINDOW_START = 55;
    static const int SCHED_ALIGNMENT_WINDOW_END = 59;
    static const uint32_t SCHED_MAX_ALIGN_WAIT_SEC = 65;  // Safety: max time to wait for alignment

    /**
     * Constructor
     * @param timeProvider Injected time provider (for testing)
     * @param intervalSeconds Reporting interval (default 300 = 5 min)
     * @param alignToSecond Target second for alignment (default 55)
     */
    ReportScheduler(TimeProvider* timeProvider,
                    int intervalSeconds = SCHED_DEFAULT_INTERVAL_SEC,
                    int alignToSecond = SCHED_DEFAULT_ALIGN_SECOND);

    /**
     * Check if it's time to send a report.
     * Call this in the main loop - returns quickly, never blocks.
     * @return true if a report should be sent now
     */
    bool shouldSend();

    /**
     * Record that a send was performed.
     * Call this after successfully sending data.
     * Resets the interval timer and transitions state.
     */
    void recordSend();

    /**
     * Update the reporting interval (e.g., from server response).
     * Clamps to reasonable bounds (10s - 1800s).
     * @param seconds New interval in seconds
     */
    void setInterval(int seconds);

    /**
     * Get current interval in seconds.
     */
    int getInterval() const;

    /**
     * Update the alignment target second (0-59).
     * Invalid values (< 0 or > 59) are ignored and default is used.
     * @param second Target second for alignment (0-59)
     */
    void setAlignSecond(int second);

    /**
     * Get current alignment target second.
     */
    int getAlignSecond() const;

    /**
     * Get current scheduler state (for debugging/telemetry).
     */
    SchedulerState getState() const;

    /**
     * Get seconds until next scheduled send (approximate).
     * Returns 0 if ready to send now.
     */
    int getSecondsUntilSend() const;

    /**
     * Get human-readable state name (for debugging).
     */
    static const char* stateToString(SchedulerState state);

    /**
     * Clamp interval to valid bounds.
     */
    static int clampInterval(int interval);

private:
    TimeProvider* timeProvider;
    int intervalSeconds;
    int alignToSecond;
    SchedulerState state;
    uint32_t lastSendTime;       // Unix timestamp of last send
    uint32_t intervalStartTime;  // When interval countdown started
    uint32_t alignWaitStartTime; // When alignment wait started (for safety timeout)

    // Internal helpers
    void transitionTo(SchedulerState newState);
    bool hasIntervalElapsed() const;
    bool isAlignedSecond() const;
    bool shouldSkipAlignment() const;
    bool hasAlignWaitTimedOut() const;
    static int clampAlignSecond(int second);

    // Interval bounds (prefixed to avoid macro conflicts)
    static const int SCHED_MIN_INTERVAL_SEC = 10;
    static const int SCHED_MAX_INTERVAL_SEC = 1800;
};

#endif // REPORT_SCHEDULER_H
