<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Contracts\HttpResponse;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\ConsoleLogger;
use HotTub\Services\EventLogger;
use HotTub\Services\IotApiClient;
use HotTub\Services\StubJsonHttpClient;

/**
 * Tests for the iot-api adapter that replaces IftttClient at the
 * IFTTT-deprecation cutover. Verifies interface compatibility, the
 * event -> {device, action} translation, auth header injection, and
 * failure reporting.
 */
class IotApiClientTest extends TestCase
{
    private const API_URL = 'https://misuse.org/iot/public/api/v1/command';

    private string $testLogFile;
    private StubJsonHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/iot-api-client-test-' . uniqid() . '.log';
        $this->httpClient = new StubJsonHttpClient();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    private function makeClient(): IotApiClient
    {
        $output = fopen('php://memory', 'w+');

        return new IotApiClient(
            self::API_URL,
            'test-jwt-token',
            $this->httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );
    }

    public function testImplementsIftttClientInterface(): void
    {
        // The whole point of the adapter: consumers keep their
        // IftttClientInterface dependency, only DI wiring changes.
        $this->assertInstanceOf(IftttClientInterface::class, $this->makeClient());
    }

    public function testModeIsStubWithStubHttpClient(): void
    {
        $this->assertSame('stub', $this->makeClient()->getMode());
    }

    /**
     * @dataProvider eventTranslationProvider
     */
    public function testTranslatesEventsToCommandBodies(string $event, array $expectedBody): void
    {
        $result = $this->makeClient()->trigger($event);

        $this->assertTrue($result);
        $this->assertSame(self::API_URL, $this->httpClient->lastUrl);
        $this->assertSame($expectedBody, $this->httpClient->lastBody);
    }

    public static function eventTranslationProvider(): array
    {
        return [
            'heat on' => ['hot-tub-heat-on', ['device' => 'hot-tub-heater', 'action' => 'turn_on']],
            'heat off' => ['hot-tub-heat-off', ['device' => 'hot-tub-heater', 'action' => 'turn_off']],
            'cycle ionizer' => ['cycle_hot_tub_ionizer', ['device' => 'hot-tub-cycle-ionizer', 'action' => 'run']],
            'blinds open' => ['open-dining-room-blinds', ['device' => 'dining-blinds', 'action' => 'open']],
            'blinds close' => ['close-dining-room-blinds', ['device' => 'dining-blinds', 'action' => 'close']],
        ];
    }

    public function testSendsBearerAuthorizationHeader(): void
    {
        $this->makeClient()->trigger('hot-tub-heat-on');

        $this->assertContains(
            'Authorization: Bearer test-jwt-token',
            $this->httpClient->lastHeaders
        );
    }

    public function testUnknownEventReturnsFalseWithoutHttpCall(): void
    {
        $result = $this->makeClient()->trigger('no-such-event');

        $this->assertFalse($result);
        $this->assertNull($this->httpClient->lastUrl);

        $log = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('iot_api_unknown_event', $log);
    }

    public function testUpstreamErrorStatusReturnsFalse(): void
    {
        // HA 404 unknown_device passed through by iot-api.
        $this->httpClient->setResponse(new HttpResponse(404, '{"message":"no such device"}'));

        $this->assertFalse($this->makeClient()->trigger('hot-tub-heat-on'));
    }

    public function testTransportFailureReturnsFalse(): void
    {
        $this->httpClient->setResponse(new HttpResponse(0, 'connection timed out'));

        $this->assertFalse($this->makeClient()->trigger('hot-tub-heat-on'));
    }

    public function testLogsAuditTrail(): void
    {
        $this->makeClient()->trigger('hot-tub-heat-on');

        $log = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('iot_api_stub', $log);
        $this->assertStringContainsString('hot-tub-heater', $log);
        $this->assertStringContainsString('"simulated":true', $log);
    }
}
