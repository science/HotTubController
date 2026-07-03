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
 * edits: legacy IFTTT event names are translated to the iot-api REST
 * device-path surface (2026-07 normalization) and POSTed to
 * {base}/api/v1/device/{slug}, which relays to Home Assistant over the
 * Tailscale Funnel. See
 * ~/dev/home-assistant/docs/plans/ifttt-deprecation-iot-api.md.
 *
 * Same late-binding strategy as IftttClient: business logic is identical
 * in stub and live modes; only the injected JSON HTTP client differs.
 */
class IotApiClient implements IftttClientInterface
{
    /**
     * Legacy IFTTT event name -> [device slug, request body].
     *
     * Only events this backend actually triggers are mapped (the webapp's
     * five call sites). The heater events use the unified partial-update
     * "set" action (implicit when the body has no action): power on/off
     * dispatches script.hot_tub_heater_on/_off HA-side via the script
     * suffix convention.
     */
    private const EVENT_MAP = [
        'hot-tub-heat-on' => ['hot-tub-heater', ['power' => 'on']],
        'hot-tub-heat-off' => ['hot-tub-heater', ['power' => 'off']],
        'cycle_hot_tub_ionizer' => ['hot-tub-cycle-ionizer', ['action' => 'run']],
        'open-dining-room-blinds' => ['dining-blinds', ['action' => 'open']],
        'close-dining-room-blinds' => ['dining-blinds', ['action' => 'close']],
    ];

    private string $mode;
    private string $apiBase;

    public function __construct(
        string $apiBaseUrl,
        private string $apiJwt,
        private JsonHttpClientInterface $httpClient,
        private ConsoleLogger $console,
        private EventLogger $logger
    ) {
        $this->apiBase = rtrim($apiBaseUrl, '/');
        $this->mode = $httpClient instanceof StubJsonHttpClient ? 'stub' : 'live';
    }

    public function trigger(string $eventName): bool
    {
        $mapped = self::EVENT_MAP[$eventName] ?? null;
        if ($mapped === null) {
            $this->logger->log('iot_api_unknown_event', ['event' => $eventName]);
            return false;
        }
        [$device, $body] = $mapped;

        $start = microtime(true);
        $response = $this->httpClient->postJson(
            $this->apiBase . '/api/v1/device/' . $device,
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
            'device' => $device,
            'body' => $body,
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
