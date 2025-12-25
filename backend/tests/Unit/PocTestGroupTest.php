<?php

declare(strict_types=1);

/**
 * Unit test to verify POC tests are properly tagged for live API testing.
 *
 * This test ensures that POC tests that hit real external APIs are properly
 * excluded from default test runs and included in the live test suite.
 */

namespace HotTub\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PocTestGroupTest extends TestCase
{
    /**
     * Verify that HealthchecksIoTest has the correct group annotation.
     *
     * POC tests that hit real external APIs must be tagged so they are:
     * 1. Excluded from default `composer test` runs (fast feedback, no API calls)
     * 2. Included in `composer test:live` runs (which runs cleanup afterward)
     */
    public function testHealthchecksIoPocTestHasCorrectGroupAnnotation(): void
    {
        $testFile = dirname(__DIR__) . '/Poc/HealthchecksIoTest.php';
        $this->assertFileExists($testFile, 'HealthchecksIoTest.php should exist');

        $content = file_get_contents($testFile);

        // The test should have @group live annotation at the class level
        $hasLiveGroup = preg_match('/@group\s+live/', $content);

        $this->assertEquals(
            1,
            $hasLiveGroup,
            'HealthchecksIoTest.php must have @group live annotation to prevent ' .
            'accidental API calls during default test runs and ensure cleanup runs afterward'
        );
    }

    /**
     * Verify that the POC test loads API key properly for live mode.
     *
     * The test should skip when HEALTHCHECKS_IO_KEY is not available,
     * ensuring it only runs in live test mode with proper credentials.
     */
    public function testHealthchecksIoPocTestSkipsWhenNoApiKey(): void
    {
        $testFile = dirname(__DIR__) . '/Poc/HealthchecksIoTest.php';
        $content = file_get_contents($testFile);

        // Should have logic to skip when API key is not configured
        $hasSkipLogic = strpos($content, 'markTestSkipped') !== false;

        $this->assertTrue(
            $hasSkipLogic,
            'HealthchecksIoTest.php should skip when API key is not configured'
        );
    }
}
