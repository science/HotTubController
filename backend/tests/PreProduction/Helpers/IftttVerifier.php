<?php

declare(strict_types=1);

namespace HotTub\Tests\PreProduction\Helpers;

use PHPUnit\Framework\Assert;

/**
 * Verifies IFTTT events from stub log for E2E tests.
 *
 * The IFTTT stub logs all trigger events to a file. This class reads
 * that log and provides assertions for verifying expected events.
 *
 * This is the "ground truth" for E2E tests - if the correct IFTTT
 * events were logged, the system behaved correctly end-to-end.
 */
class IftttVerifier
{
    private string $eventsLogPath;

    public function __construct(string $eventsLogPath)
    {
        $this->eventsLogPath = $eventsLogPath;
    }

    /**
     * Get all IFTTT events from the stub log.
     *
     * @return string[] Event names like 'hot-tub-heat-on', 'hot-tub-heat-off'
     */
    public function getEvents(): array
    {
        if (!file_exists($this->eventsLogPath)) {
            return [];
        }

        $events = [];
        $lines = file($this->eventsLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry === null) {
                continue;
            }

            // IFTTT stub logs with action='ifttt_trigger' and data.event='event-name'
            if (isset($entry['action']) && str_starts_with($entry['action'], 'ifttt_')) {
                $events[] = $entry['data']['event'] ?? 'unknown';
            }
        }

        return $events;
    }

    /**
     * Get full event entries with timestamps and details.
     *
     * @return array[] Array of event entries
     */
    public function getEventDetails(): array
    {
        if (!file_exists($this->eventsLogPath)) {
            return [];
        }

        $events = [];
        $lines = file($this->eventsLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry === null) {
                continue;
            }

            if (isset($entry['action']) && str_starts_with($entry['action'], 'ifttt_')) {
                $events[] = $entry;
            }
        }

        return $events;
    }

    /**
     * Assert that specific events occurred in exact order.
     *
     * @param string[] $expectedEvents Expected event names in order
     */
    public function assertEventsInOrder(array $expectedEvents, string $message = ''): void
    {
        $actualEvents = $this->getEvents();

        $prefix = $message ? "$message\n" : '';

        Assert::assertEquals(
            $expectedEvents,
            $actualEvents,
            $prefix .
            "IFTTT events mismatch.\n" .
            "Expected: " . json_encode($expectedEvents) . "\n" .
            "Actual: " . json_encode($actualEvents)
        );
    }

    /**
     * Assert that a specific event occurred (anywhere in the log).
     *
     * @param string $eventName Event to look for
     */
    public function assertEventOccurred(string $eventName, string $message = ''): void
    {
        $events = $this->getEvents();

        $prefix = $message ? "$message\n" : '';

        Assert::assertContains(
            $eventName,
            $events,
            $prefix .
            "IFTTT event '$eventName' not found.\n" .
            "Events logged: " . json_encode($events)
        );
    }

    /**
     * Assert that a specific event did NOT occur.
     *
     * @param string $eventName Event that should not be present
     */
    public function assertEventNotOccurred(string $eventName, string $message = ''): void
    {
        $events = $this->getEvents();

        $prefix = $message ? "$message\n" : '';

        Assert::assertNotContains(
            $eventName,
            $events,
            $prefix .
            "IFTTT event '$eventName' should NOT have occurred.\n" .
            "Events logged: " . json_encode($events)
        );
    }

    /**
     * Assert the log is empty (no IFTTT events occurred).
     */
    public function assertNoEvents(string $message = ''): void
    {
        $events = $this->getEvents();

        $prefix = $message ? "$message\n" : '';

        Assert::assertEmpty(
            $events,
            $prefix .
            "Expected no IFTTT events, but found: " . json_encode($events)
        );
    }

    /**
     * Clear the events log for a fresh test.
     */
    public function clear(): void
    {
        if (file_exists($this->eventsLogPath)) {
            unlink($this->eventsLogPath);
        }
    }

    /**
     * Get count of a specific event type.
     *
     * @param string $eventName Event to count
     * @return int Number of occurrences
     */
    public function countEvent(string $eventName): int
    {
        $events = $this->getEvents();
        return count(array_filter($events, fn($e) => $e === $eventName));
    }

    /**
     * Assert an event occurred exactly N times.
     *
     * @param string $eventName Event to count
     * @param int $expectedCount Expected occurrences
     */
    public function assertEventCount(string $eventName, int $expectedCount, string $message = ''): void
    {
        $actualCount = $this->countEvent($eventName);

        $prefix = $message ? "$message\n" : '';

        Assert::assertEquals(
            $expectedCount,
            $actualCount,
            $prefix .
            "Expected '$eventName' to occur $expectedCount time(s), but it occurred $actualCount time(s).\n" .
            "All events: " . json_encode($this->getEvents())
        );
    }
}
