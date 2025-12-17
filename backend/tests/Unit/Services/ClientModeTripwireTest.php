<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\CurlWirelessTagHttpClient;
use HotTub\Services\StubWirelessTagHttpClient;
use HotTub\Services\CurlHttpClient;
use HotTub\Services\StubHttpClient;
use HotTub\Services\HealthchecksClient;
use HotTub\Services\NullHealthchecksClient;
use RuntimeException;

/**
 * Tests that HTTP clients throw exceptions when instantiated in the wrong mode.
 *
 * This is a "tripwire" pattern - if the factory incorrectly routes to the wrong
 * client type for the configured mode, the test fails loudly rather than silently
 * making (or not making) API calls.
 *
 * The rule is simple:
 * - EXTERNAL_API_MODE=stub → only stub clients allowed, live clients throw
 * - EXTERNAL_API_MODE=live → only live clients allowed, stub clients throw
 */
class ClientModeTripwireTest extends TestCase
{
    private ?string $originalMode = null;

    protected function setUp(): void
    {
        // Capture original mode to restore in tearDown
        $this->originalMode = getenv('EXTERNAL_API_MODE') ?: null;
    }

    protected function tearDown(): void
    {
        // Restore original mode
        if ($this->originalMode !== null) {
            putenv("EXTERNAL_API_MODE={$this->originalMode}");
        } else {
            putenv('EXTERNAL_API_MODE');  // Unset
        }
    }

    // =========================================================================
    // Live clients should throw in stub mode
    // =========================================================================

    /**
     * @test
     */
    public function curlWirelessTagHttpClientThrowsInStubMode(): void
    {
        putenv('EXTERNAL_API_MODE=stub');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXTERNAL_API_MODE=stub');

        new CurlWirelessTagHttpClient('test-token', 60);
    }

    /**
     * @test
     */
    public function curlHttpClientThrowsInStubMode(): void
    {
        putenv('EXTERNAL_API_MODE=stub');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXTERNAL_API_MODE=stub');

        new CurlHttpClient();
    }

    /**
     * @test
     */
    public function healthchecksClientThrowsInStubMode(): void
    {
        putenv('EXTERNAL_API_MODE=stub');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXTERNAL_API_MODE=stub');

        new HealthchecksClient('test-api-key');
    }

    // =========================================================================
    // Stub clients should throw in live mode
    // =========================================================================

    /**
     * @test
     */
    public function stubWirelessTagHttpClientThrowsInLiveMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXTERNAL_API_MODE=live');

        new StubWirelessTagHttpClient();
    }

    /**
     * @test
     */
    public function stubHttpClientThrowsInLiveMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXTERNAL_API_MODE=live');

        new StubHttpClient();
    }

    /**
     * @test
     */
    public function nullHealthchecksClientThrowsInLiveMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXTERNAL_API_MODE=live');

        new NullHealthchecksClient();
    }

    // =========================================================================
    // Clients should work in their intended mode
    // =========================================================================

    /**
     * @test
     */
    public function curlWirelessTagHttpClientAllowedInLiveMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');

        // Should NOT throw
        $client = new CurlWirelessTagHttpClient('test-token', 60);

        $this->assertInstanceOf(CurlWirelessTagHttpClient::class, $client);
    }

    /**
     * @test
     */
    public function curlHttpClientAllowedInLiveMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');

        // Should NOT throw
        $client = new CurlHttpClient();

        $this->assertInstanceOf(CurlHttpClient::class, $client);
    }

    /**
     * @test
     */
    public function healthchecksClientAllowedInLiveMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');

        // Should NOT throw
        $client = new HealthchecksClient('test-api-key');

        $this->assertInstanceOf(HealthchecksClient::class, $client);
    }

    /**
     * @test
     */
    public function stubWirelessTagHttpClientAllowedInStubMode(): void
    {
        putenv('EXTERNAL_API_MODE=stub');

        // Should NOT throw
        $client = new StubWirelessTagHttpClient();

        $this->assertInstanceOf(StubWirelessTagHttpClient::class, $client);
    }

    /**
     * @test
     */
    public function stubHttpClientAllowedInStubMode(): void
    {
        putenv('EXTERNAL_API_MODE=stub');

        // Should NOT throw
        $client = new StubHttpClient();

        $this->assertInstanceOf(StubHttpClient::class, $client);
    }

    /**
     * @test
     */
    public function nullHealthchecksClientAllowedInStubMode(): void
    {
        putenv('EXTERNAL_API_MODE=stub');

        // Should NOT throw
        $client = new NullHealthchecksClient();

        $this->assertInstanceOf(NullHealthchecksClient::class, $client);
    }
}
