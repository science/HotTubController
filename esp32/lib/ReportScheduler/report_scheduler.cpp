#include "report_scheduler.h"

ReportScheduler::ReportScheduler(TimeProvider* timeProvider,
                                 int intervalSeconds,
                                 int alignToSecond)
    : timeProvider(timeProvider)
    , intervalSeconds(clampInterval(intervalSeconds))
    , alignToSecond(clampAlignSecond(alignToSecond))
    , state(STATE_BOOT_SEND)
    , lastSendTime(0)
    , intervalStartTime(0)
    , alignWaitStartTime(0)
{
}

int ReportScheduler::clampAlignSecond(int second) {
    if (second < 0 || second > 59) {
        return SCHED_DEFAULT_ALIGN_SECOND;
    }
    return second;
}

bool ReportScheduler::shouldSend() {
    switch (state) {
        case STATE_BOOT_SEND:
            // Always send immediately on boot - this is the most robust behavior
            return true;

        case STATE_INTERVAL_WAIT:
            if (hasIntervalElapsed()) {
                if (shouldSkipAlignment()) {
                    // Short interval or NTP not synced - go straight to ready
                    transitionTo(STATE_READY_TO_SEND);
                    return true;
                } else {
                    // Start alignment wait
                    transitionTo(STATE_ALIGN_WAIT);
                    alignWaitStartTime = timeProvider->getCurrentTime();
                    // Check immediately if we're already at alignment second
                    if (isAlignedSecond()) {
                        transitionTo(STATE_READY_TO_SEND);
                        return true;
                    }
                }
            }
            return false;

        case STATE_ALIGN_WAIT:
            // Safety: timeout after MAX_ALIGN_WAIT_SEC to prevent getting stuck
            if (hasAlignWaitTimedOut()) {
                // Timed out waiting for alignment - send anyway
                transitionTo(STATE_READY_TO_SEND);
                return true;
            }
            if (isAlignedSecond()) {
                transitionTo(STATE_READY_TO_SEND);
                return true;
            }
            return false;

        case STATE_READY_TO_SEND:
            return true;

        default:
            // Unknown state - recover by allowing send (fail-safe)
            transitionTo(STATE_BOOT_SEND);
            return true;
    }
}

void ReportScheduler::recordSend() {
    uint32_t now = timeProvider->getCurrentTime();
    lastSendTime = now;
    intervalStartTime = now;
    transitionTo(STATE_INTERVAL_WAIT);
}

void ReportScheduler::setInterval(int seconds) {
    intervalSeconds = clampInterval(seconds);
}

int ReportScheduler::getInterval() const {
    return intervalSeconds;
}

void ReportScheduler::setAlignSecond(int second) {
    alignToSecond = clampAlignSecond(second);
}

int ReportScheduler::getAlignSecond() const {
    return alignToSecond;
}

SchedulerState ReportScheduler::getState() const {
    return state;
}

int ReportScheduler::getSecondsUntilSend() const {
    if (state == STATE_BOOT_SEND || state == STATE_READY_TO_SEND) {
        return 0;
    }

    uint32_t now = timeProvider->getCurrentTime();

    if (state == STATE_INTERVAL_WAIT) {
        uint32_t elapsed = now - intervalStartTime;
        if (elapsed >= static_cast<uint32_t>(intervalSeconds)) {
            // Interval elapsed, just alignment wait remaining
            if (shouldSkipAlignment()) {
                return 0;
            }
            int currentSec = timeProvider->getSecondOfMinute();
            if (currentSec >= alignToSecond) {
                return 0; // Already in window
            }
            return alignToSecond - currentSec;
        }

        int remaining = intervalSeconds - static_cast<int>(elapsed);

        // Add estimated alignment wait if applicable
        if (!shouldSkipAlignment()) {
            int currentSec = timeProvider->getSecondOfMinute();
            if (currentSec < alignToSecond) {
                // Estimate: we might need up to (alignToSecond - currentSec) more
                // This is approximate since second will change as interval elapses
            }
        }
        return remaining;
    }

    if (state == STATE_ALIGN_WAIT) {
        int currentSec = timeProvider->getSecondOfMinute();
        if (currentSec >= alignToSecond && currentSec <= SCHED_ALIGNMENT_WINDOW_END) {
            return 0;
        }
        // Calculate seconds until alignToSecond
        if (currentSec < alignToSecond) {
            return alignToSecond - currentSec;
        }
        // currentSec > 59 window, wait for next minute's alignToSecond
        return (60 - currentSec) + alignToSecond;
    }

    return 0;
}

const char* ReportScheduler::stateToString(SchedulerState state) {
    switch (state) {
        case STATE_BOOT_SEND:     return "BOOT_SEND";
        case STATE_INTERVAL_WAIT: return "INTERVAL_WAIT";
        case STATE_ALIGN_WAIT:    return "ALIGN_WAIT";
        case STATE_READY_TO_SEND: return "READY_TO_SEND";
        default:                  return "UNKNOWN";
    }
}

int ReportScheduler::clampInterval(int interval) {
    if (interval < SCHED_MIN_INTERVAL_SEC) {
        return SCHED_MIN_INTERVAL_SEC;
    }
    if (interval > SCHED_MAX_INTERVAL_SEC) {
        return SCHED_MAX_INTERVAL_SEC;
    }
    return interval;
}

void ReportScheduler::transitionTo(SchedulerState newState) {
    state = newState;
}

bool ReportScheduler::hasIntervalElapsed() const {
    uint32_t now = timeProvider->getCurrentTime();
    uint32_t elapsed = now - intervalStartTime;
    return elapsed >= static_cast<uint32_t>(intervalSeconds);
}

bool ReportScheduler::isAlignedSecond() const {
    int currentSec = timeProvider->getSecondOfMinute();
    return currentSec >= alignToSecond && currentSec <= SCHED_ALIGNMENT_WINDOW_END;
}

bool ReportScheduler::shouldSkipAlignment() const {
    // Skip alignment for short intervals (too frequent to align)
    if (intervalSeconds < SCHED_MIN_INTERVAL_FOR_ALIGNMENT) {
        return true;
    }
    // Skip alignment if NTP is not synced (we don't have reliable time)
    if (!timeProvider->isTimeSynced()) {
        return true;
    }
    return false;
}

bool ReportScheduler::hasAlignWaitTimedOut() const {
    uint32_t now = timeProvider->getCurrentTime();
    uint32_t waitTime = now - alignWaitStartTime;
    return waitTime >= SCHED_MAX_ALIGN_WAIT_SEC;
}
