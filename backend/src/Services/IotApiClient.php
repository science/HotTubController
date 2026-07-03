<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\IftttClientInterface;
use HotTub\Contracts\JsonHttpClientInterface;

/**
 * iot-api adapter for the IFTTT-deprecation cutover.
 *
 * Implements IftttClientInterface so HeaterControlService and
 * BlindsController swap over with a one-line DI change and zero consumer
 * edits: legacy IFTTT event names are translated to iot-api command
 * bodies ({device, action}) and POSTed to
 * https://misuse.org/iot/public/api/v1/command, which relays to Home
 * Assistant over the Tailscale Funnel. See
 * ~/dev/home-assistant/docs/plans/ifttt-deprecation-iot-api.md.
 *
 * Same late-binding strategy as IftttClient: business logic is identical
 * in stub and live modes; only the injected JSON HTTP client differs.
 */
class IotApiClient implements IftttClientInterface
{
    /**
     * Legacy IFTTT event name -> iot-api command body.
     *
     * Only events this backend actually triggers are mapped (the webapp's
     * five call sites). Devices "hot-tub-heater", "hot-tub-cycle-ionizer"
     * and "dining-blinds" require the HA-side iot_internal Phase-1
     * generalization before live cutover — until then HA answers 404
     * unknown_device, which trigger() reports as failure.
     */
    private const EVENT_MAP = [
        'hot-tub-heat-on' => ['device' => 'hot-tub-heater', 'action' => 'turn_on'],
        'hot-tub-heat-off' => ['device' => 'hot-tub-heater', 'action' => 'turn_off'],
        'cycle_hot_tub_ionizer' => ['device' => 'hot-tub-cycle-ionizer', 'action' => 'run'],
        'open-dining-room-blinds' => ['device' => 'dining-blinds', 'action' => 'open'],
        'close-dining-room-blinds' => ['device' => 'dining-blinds', 'action' => 'close'],
    ];

    private string $mode;

    public function __construct(
        private string $apiUrl,
        private string $apiJwt,
        private JsonHttpClientInterface $httpClient,
        private ConsoleLogger $console,
        private EventLogger $logger
    ) {
        $this->mode = $httpClient instanceof StubJsonHttpClient ? 'stub' : 'live';
    }

    public function trigger(string $eventName): bool
    {
        $body = self::EVENT_MAP[$eventName] ?? null;
        if ($body === null) {
            $this->logger->log('iot_api_unknown_event', ['event' => $eventName]);
            return false;
        }

        $start = microtime(true);
        $response = $this->httpClient->postJson(
            $this->apiUrl,
            $body,
            ['Authorization: Bearer ' . $this->apiJwt]
        );
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $httpCode = $response->getStatusCode();
        $success = $response->isSuccess();

        if ($this->mode === 'stub') {
            $this->console->stub($eventName, $durationMs);
        } else {
            $this->console->live($eventName, $httpCode, $durationMs);
        }

        $data = [
            'event' => $eventName,
            'device' => $body['device'],
            'action' => $body['action'],
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'success' => $success,
        ];
        if ($this->mode === 'stub') {
            $data['simulated'] = true;
        }
        $this->logger->log($this->mode === 'stub' ? 'iot_api_stub' : 'iot_api_live', $data);

        return $success;
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}
