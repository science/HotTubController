<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HotTub\Services\WirelessTagClient;
use HotTub\Services\StubWirelessTagHttpClient;

/**
 * Tests for WirelessTag timestamp handling.
 *
 * The timestamp returned by getTemperature() should reflect when the sensor
 * actually took the reading (from lastComm in the API response), NOT when
 * the API was called. This is critical because:
 * - Cached sensor readings may be several minutes old
 * - Page refresh should show when data was actually measured, not fetched
 */
class WirelessTagClientTimestampTest extends TestCase
{
    /**
     * @test
     * getTemperature() should return the timestamp from the API's lastComm field,
     * not the current time. This ensures page refreshes show when the sensor
     * actually took the reading.
     */
    public function getTemperatureReturnsLastCommTimestampNotCurrentTime(): void
    {
        // Set lastComm to 5 minutes ago
        $fiveMinutesAgo = time() - 300;

        $httpClient = new StubWirelessTagHttpClient();
        $httpClient->setLastCommTimestamp($fiveMinutesAgo);

        $client = new WirelessTagClient($httpClient);

        // Small delay to ensure time() would be different from our test timestamp
        usleep(100000); // 100ms

        $result = $client->getTemperature('0');

        // The timestamp should be close to 5 minutes ago, NOT close to now
        $this->assertArrayHasKey('timestamp', $result);

        $returnedTimestamp = $result['timestamp'];
        $now = time();

        // If the bug exists, returned timestamp will be close to now (within 2 seconds)
        // If fixed, returned timestamp should be close to fiveMinutesAgo (within 2 seconds)
        $this->assertLessThan(
            $now - 250, // At least 4+ minutes ago
            $returnedTimestamp,
            "Timestamp should reflect sensor reading time (5 min ago), not API call time (now). " .
            "Got timestamp that's only " . ($now - $returnedTimestamp) . " seconds old."
        );

        // Verify it's close to our set lastComm time
        $this->assertEqualsWithDelta(
            $fiveMinutesAgo,
            $returnedTimestamp,
            2, // Allow 2 seconds tolerance
            "Timestamp should match the lastComm value from the API response"
        );
    }
}
