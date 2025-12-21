<?php

declare(strict_types=1);

namespace HotTub\Tests\Poc;

use PHPUnit\Framework\TestCase;

/**
 * Proof-of-Concept Tests for Healthchecks.io Integration
 *
 * These tests verify our understanding of how Healthchecks.io behaves:
 * 1. Does a newly created check alert if never pinged?
 * 2. Does a check that's been pinged once then abandoned go to "down"?
 * 3. How long do state transitions take?
 *
 * Run with: ./vendor/bin/phpunit tests/Poc/HealthchecksIoTest.php
 *
 * @group poc
 * @group healthchecks
 * @group live
 */
class HealthchecksIoTest extends TestCase
{
    private string $apiKey;
    private string $baseUrl = 'https://healthchecks.io/api/v3';

    // Store created check UUIDs for cleanup
    private array $createdChecks = [];

    protected function setUp(): void
    {
        // Load from env.production or environment
        $envFile = dirname(__DIR__, 2) . '/config/env.production';
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            if (preg_match('/^HEALTHCHECKS_IO_KEY=(.+)$/m', $content, $matches)) {
                $this->apiKey = trim($matches[1]);
            }
        }

        if (empty($this->apiKey)) {
            $this->apiKey = getenv('HEALTHCHECKS_IO_KEY') ?: '';
        }

        if (empty($this->apiKey)) {
            $this->markTestSkipped('HEALTHCHECKS_IO_KEY not configured');
        }
    }

    protected function tearDown(): void
    {
        // Clean up any checks we created
        foreach ($this->createdChecks as $uuid) {
            $this->deleteCheck($uuid);
        }
    }

    /**
     * Test 1: Create a check and immediately check its status.
     * Expected: status = "new" (never been pinged)
     */
    public function testNewCheckStartsInNewState(): void
    {
        $check = $this->createCheck([
            'name' => 'poc-test-new-state-' . time(),
            'timeout' => 60,  // 1 minute timeout
            'grace' => 60,    // 1 minute grace
        ]);

        $this->assertNotNull($check, 'Check creation failed');
        $this->createdChecks[] = $check['uuid'];

        echo "\n  Created check: {$check['uuid']}\n";
        echo "  Status: {$check['status']}\n";
        echo "  Ping URL: {$check['ping_url']}\n";

        $this->assertEquals('new', $check['status'], 'New check should start in "new" state');
    }

    /**
     * Test 2: Create a check, ping it once, then check status.
     * Expected: status = "up" after first ping
     */
    public function testCheckTransitionsToUpAfterFirstPing(): void
    {
        $check = $this->createCheck([
            'name' => 'poc-test-ping-up-' . time(),
            'timeout' => 60,
            'grace' => 60,
        ]);

        $this->assertNotNull($check);
        $this->createdChecks[] = $check['uuid'];

        echo "\n  Created check: {$check['uuid']}, status: {$check['status']}\n";

        // Ping to arm
        $pingResult = $this->ping($check['ping_url']);
        echo "  Pinged, response: $pingResult\n";

        // Check status after ping
        sleep(2); // Small delay for API to update
        $updated = $this->getCheck($check['uuid']);

        echo "  Status after ping: {$updated['status']}\n";
        echo "  n_pings: {$updated['n_pings']}\n";

        $this->assertEquals('up', $updated['status'], 'Check should be "up" after first ping');
        $this->assertEquals(1, $updated['n_pings'], 'Should have 1 ping recorded');
    }

    /**
     * Test 3: Create check with very short timeout, ping once, wait for timeout.
     * This is the KEY test - does it transition to "grace" then "down"?
     *
     * Using minimum values: 60s timeout + 60s grace = 2 minutes total wait
     */
    public function testCheckGoesToGraceAndDownAfterTimeout(): void
    {
        // Use minimum allowed values for faster testing
        $timeout = 60;  // 1 minute
        $grace = 60;    // 1 minute

        $check = $this->createCheck([
            'name' => 'poc-test-timeout-' . time(),
            'timeout' => $timeout,
            'grace' => $grace,
        ]);

        $this->assertNotNull($check);
        $this->createdChecks[] = $check['uuid'];

        echo "\n  Created check: {$check['uuid']}\n";
        echo "  Timeout: {$timeout}s, Grace: {$grace}s\n";
        echo "  Initial status: {$check['status']}\n";

        // PING ONCE to arm it
        $pingResult = $this->ping($check['ping_url']);
        echo "  Armed with ping: $pingResult\n";

        sleep(2);
        $afterPing = $this->getCheck($check['uuid']);
        echo "  Status after ping: {$afterPing['status']}\n";

        // Record the states we observe
        $states = ['up' => date('H:i:s')];

        // Now wait and poll - should go: up -> grace -> down
        echo "  Waiting for state transitions (up to 3 minutes)...\n";

        $startTime = time();
        $maxWait = ($timeout + $grace + 30); // Extra 30s buffer
        $lastStatus = 'up';

        while ((time() - $startTime) < $maxWait) {
            sleep(15); // Check every 15 seconds

            $current = $this->getCheck($check['uuid']);
            $elapsed = time() - $startTime;

            if ($current['status'] !== $lastStatus) {
                $states[$current['status']] = date('H:i:s') . " (+{$elapsed}s)";
                echo "    -> Status changed to: {$current['status']} at +{$elapsed}s\n";
                $lastStatus = $current['status'];

                // If we've reached "down", we're done
                if ($current['status'] === 'down') {
                    break;
                }
            }
        }

        echo "  Final state transitions observed:\n";
        foreach ($states as $state => $time) {
            echo "    $state: $time\n";
        }

        // Verify we saw the expected transitions
        $this->assertArrayHasKey('grace', $states, 'Should have transitioned to grace state');
        $this->assertArrayHasKey('down', $states, 'Should have transitioned to down state');
    }

    /**
     * Test 4: Verify a NEVER-PINGED check does NOT alert.
     * Create check, wait past timeout+grace, confirm still "new" (not "down").
     */
    public function testNeverPingedCheckDoesNotAlert(): void
    {
        $timeout = 60;
        $grace = 60;

        $check = $this->createCheck([
            'name' => 'poc-test-never-pinged-' . time(),
            'timeout' => $timeout,
            'grace' => $grace,
        ]);

        $this->assertNotNull($check);
        $this->createdChecks[] = $check['uuid'];

        echo "\n  Created check (NOT pinging): {$check['uuid']}\n";
        echo "  Initial status: {$check['status']}\n";
        echo "  Waiting 2.5 minutes (past timeout+grace)...\n";

        // Wait past the timeout + grace period
        sleep(150); // 2.5 minutes

        $final = $this->getCheck($check['uuid']);
        echo "  Final status: {$final['status']}\n";

        // Per our source code analysis, this should STILL be "new"
        $this->assertEquals('new', $final['status'],
            'Never-pinged check should remain "new", not transition to "down"');
    }

    // =========================================================================
    // API Helper Methods
    // =========================================================================

    private function createCheck(array $params): ?array
    {
        $ch = curl_init($this->baseUrl . '/checks/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201) {
            echo "  ERROR creating check: HTTP $httpCode - $response\n";
            return null;
        }

        return json_decode($response, true);
    }

    private function getCheck(string $uuid): ?array
    {
        $ch = curl_init($this->baseUrl . '/checks/' . $uuid);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "  ERROR getting check: HTTP $httpCode\n";
            return null;
        }

        return json_decode($response, true);
    }

    private function deleteCheck(string $uuid): bool
    {
        $ch = curl_init($this->baseUrl . '/checks/' . $uuid);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private function ping(string $pingUrl): string
    {
        $ch = curl_init($pingUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response ?: 'no response';
    }

    private function getFlips(string $uuid): array
    {
        $ch = curl_init($this->baseUrl . '/checks/' . $uuid . '/flips/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [];
        }

        return json_decode($response, true)['flips'] ?? [];
    }
}
