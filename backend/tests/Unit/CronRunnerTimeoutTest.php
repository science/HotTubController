<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit;

use HotTub\Services\IotApiClientFactory;
use HotTub\Services\TargetTemperatureService;
use PHPUnit\Framework\TestCase;

/**
 * cron-runner.sh's curl ceiling must outlast the slowest request it can make.
 *
 * If curl gives up first, the cron log records "FAILED: API returned 000" for a
 * request the backend is still carrying out — exactly the false signal that
 * sends an investigation the wrong way (issue #154). The ceiling therefore has
 * to cover every device call one request can chain, at the iot-api client's
 * full timeout each, plus the request's non-device overhead.
 */
class CronRunnerTimeoutTest extends TestCase
{
    /**
     * Non-device time inside one cron request: JWT/user-store checks, crontab
     * edits, Healthchecks.io calls. Production api.log shows a scheduled
     * heat-to-target start at 15.2 s with a 0.5 s device call (2026-11-07).
     */
    private const NON_DEVICE_HEADROOM_SECONDS = 15;

    private function cronRunnerSource(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../storage/bin/cron-runner.sh');
    }

    private function declaredApiMaxTime(): int
    {
        $matched = preg_match('/^API_MAX_TIME_SECONDS=(\d+)\s*$/m', $this->cronRunnerSource(), $m);
        $this->assertSame(1, $matched, 'cron-runner.sh must declare API_MAX_TIME_SECONDS=<n>');

        return (int) $m[1];
    }

    public function testApiCallCeilingCoversTheWorstCaseChainOfDeviceCalls(): void
    {
        $worstCase = TargetTemperatureService::MAX_DEVICE_CALLS_PER_REQUEST
            * IotApiClientFactory::LIVE_TIMEOUT_SECONDS
            + self::NON_DEVICE_HEADROOM_SECONDS;

        $this->assertGreaterThanOrEqual($worstCase, $this->declaredApiMaxTime());
    }

    public function testTheApiCurlActuallyUsesTheDeclaredCeiling(): void
    {
        $source = $this->cronRunnerSource();

        $this->assertStringContainsString('--max-time "$API_MAX_TIME_SECONDS"', $source);
        $this->assertDoesNotMatchRegularExpression(
            // [^)]* keeps the match inside the API curl's $( ... ) — the later
            // Healthchecks.io calls legitimately keep their own 10 s ceilings.
            '/-X POST "\$FULL_URL"[^)]*--max-time \d+/',
            $source,
            'the API call must not hard-code its own --max-time'
        );
    }
}
