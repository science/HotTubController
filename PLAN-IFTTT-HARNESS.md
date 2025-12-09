# IFTTT Testing Harness Plan

## Overview

This plan outlines the architecture and TDD implementation steps for building an IFTTT testing harness that allows the backend to work with either stub (simulated) or live IFTTT webhooks, while keeping the frontend completely unaware of the difference.

## Architecture

### Key Design Principles

1. **Interface-based design**: All IFTTT clients implement a common interface
2. **Factory pattern**: Environment-based client creation
3. **Console visibility**: PHP console clearly reports stub vs live mode
4. **Frontend blindness**: API responses are identical regardless of mode
5. **Safety first**: Default to stub mode unless explicitly configured for live

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Frontend (SvelteKit)                         │
│                    (completely unaware of mode)                      │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     EquipmentController                              │
│                                                                      │
│  heaterOn() ─────► IftttClientInterface::trigger('heater_on')       │
│  heaterOff() ────► IftttClientInterface::trigger('heater_off')      │
│  pumpRun() ──────► IftttClientInterface::trigger('pump_run')        │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    ▼                               ▼
┌─────────────────────────────┐   ┌─────────────────────────────┐
│     StubIftttClient         │   │     LiveIftttClient         │
│                             │   │                             │
│ - Logs to console: [STUB]   │   │ - Logs to console: [LIVE]   │
│ - Simulates 100ms delay     │   │ - Makes real HTTP calls     │
│ - Always returns success    │   │ - Returns actual response   │
│ - Records to audit log      │   │ - Records to audit log      │
└─────────────────────────────┘   └─────────────────────────────┘
                    │                               │
                    └───────────────┬───────────────┘
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      IftttClientFactory                              │
│                                                                      │
│  create(mode: 'stub'|'live'|'auto') → IftttClientInterface          │
│                                                                      │
│  Mode resolution:                                                    │
│  - 'stub': Always returns StubIftttClient                           │
│  - 'live': Returns LiveIftttClient (requires API key)               │
│  - 'auto': Checks APP_ENV and IFTTT_WEBHOOK_KEY                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Console Output Examples

**Stub Mode:**
```
[STUB] IFTTT trigger: heater_on (simulated, 102ms)
[STUB] IFTTT trigger: heater_off (simulated, 98ms)
[STUB] IFTTT trigger: pump_run (simulated, 105ms)
```

**Live Mode:**
```
[LIVE] IFTTT trigger: heater_on → HTTP 200 (342ms)
[LIVE] IFTTT trigger: heater_off → HTTP 200 (298ms)
[LIVE] IFTTT trigger: pump_run → HTTP 200 (315ms)
```

### Environment Configuration

```bash
# .env file options

# Mode selection: stub, live, or auto (default: auto)
IFTTT_MODE=auto

# Required for live mode
IFTTT_WEBHOOK_KEY=your-webhook-key-here

# Application environment: development, testing, production
APP_ENV=development
```

### Mode Resolution Logic

```
IF IFTTT_MODE == 'stub':
    → Use StubIftttClient

ELSE IF IFTTT_MODE == 'live':
    IF IFTTT_WEBHOOK_KEY is set:
        → Use LiveIftttClient
    ELSE:
        → Throw error: "Live mode requires IFTTT_WEBHOOK_KEY"

ELSE (auto mode):
    IF APP_ENV == 'testing':
        → Use StubIftttClient (safety)
    ELSE IF IFTTT_WEBHOOK_KEY is set:
        → Use LiveIftttClient
    ELSE:
        → Use StubIftttClient (fallback)
```

## IFTTT Event Mapping

Based on archived implementation, these are the IFTTT events:

| Action | IFTTT Event Name | Description |
|--------|-----------------|-------------|
| Heater ON | `hot-tub-heat-on` | Starts pump → waits → activates heater |
| Heater OFF | `hot-tub-heat-off` | Stops heater → pump cooling → stops pump |
| Pump Run | `pump-run-2hr` | Runs pump for 2 hours (new event) |

## File Structure

```
backend/
├── src/
│   ├── Controllers/
│   │   └── EquipmentController.php  # Updated to use IftttClient
│   ├── Contracts/
│   │   └── IftttClientInterface.php # Interface definition
│   ├── Services/
│   │   ├── EventLogger.php          # Existing
│   │   ├── ConsoleLogger.php        # NEW: Console output helper
│   │   ├── StubIftttClient.php      # NEW: Test/stub implementation
│   │   ├── LiveIftttClient.php      # NEW: Live HTTP implementation
│   │   └── IftttClientFactory.php   # NEW: Factory for client creation
│   └── Support/
│       └── Config.php               # NEW: Environment config helper
├── tests/
│   ├── Unit/
│   │   ├── Services/
│   │   │   ├── StubIftttClientTest.php
│   │   │   ├── LiveIftttClientTest.php
│   │   │   └── IftttClientFactoryTest.php
│   │   └── Controllers/
│   │       └── EquipmentControllerTest.php  # Updated tests
│   └── Integration/
│       └── IftttIntegrationTest.php  # Full flow tests
└── public/
    └── index.php                     # Updated to initialize factory
```

## TDD Implementation Steps

### Step 1: IftttClientInterface (RED → GREEN)

**1.1 RED - Write failing test:**
```php
// tests/Unit/Contracts/IftttClientInterfaceTest.php
public function testInterfaceDefinesTriggerMethod(): void
{
    $reflection = new ReflectionClass(IftttClientInterface::class);
    $this->assertTrue($reflection->hasMethod('trigger'));

    $method = $reflection->getMethod('trigger');
    $this->assertEquals(1, $method->getNumberOfParameters());
    $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());
    $this->assertEquals('bool', $method->getReturnType()->getName());
}

public function testInterfaceDefinesGetModeMethod(): void
{
    $reflection = new ReflectionClass(IftttClientInterface::class);
    $this->assertTrue($reflection->hasMethod('getMode'));
    $this->assertEquals('string', $reflection->getMethod('getMode')->getReturnType()->getName());
}
```

**1.2 GREEN - Implement interface:**
```php
// src/Contracts/IftttClientInterface.php
interface IftttClientInterface
{
    public function trigger(string $eventName): bool;
    public function getMode(): string;
}
```

### Step 2: ConsoleLogger (RED → GREEN)

**2.1 RED - Write failing test:**
```php
// tests/Unit/Services/ConsoleLoggerTest.php
public function testLogStubMessage(): void
{
    $output = new StringWriter();
    $logger = new ConsoleLogger($output);

    $logger->stub('test_event', 100);

    $this->assertStringContainsString('[STUB]', $output->getContents());
    $this->assertStringContainsString('test_event', $output->getContents());
    $this->assertStringContainsString('100ms', $output->getContents());
}

public function testLogLiveMessage(): void
{
    $output = new StringWriter();
    $logger = new ConsoleLogger($output);

    $logger->live('test_event', 200, 250);

    $this->assertStringContainsString('[LIVE]', $output->getContents());
    $this->assertStringContainsString('HTTP 200', $output->getContents());
}
```

**2.2 GREEN - Implement ConsoleLogger:**
```php
// src/Services/ConsoleLogger.php
class ConsoleLogger
{
    public function stub(string $event, int $durationMs): void
    {
        $this->output("[STUB] IFTTT trigger: {$event} (simulated, {$durationMs}ms)");
    }

    public function live(string $event, int $httpCode, int $durationMs): void
    {
        $this->output("[LIVE] IFTTT trigger: {$event} → HTTP {$httpCode} ({$durationMs}ms)");
    }
}
```

### Step 3: StubIftttClient (RED → GREEN)

**3.1 RED - Write failing tests:**
```php
// tests/Unit/Services/StubIftttClientTest.php
public function testImplementsInterface(): void
{
    $client = new StubIftttClient();
    $this->assertInstanceOf(IftttClientInterface::class, $client);
}

public function testGetModeReturnsStub(): void
{
    $client = new StubIftttClient();
    $this->assertEquals('stub', $client->getMode());
}

public function testTriggerReturnsTrue(): void
{
    $client = new StubIftttClient();
    $this->assertTrue($client->trigger('heater_on'));
}

public function testTriggerLogsToConsole(): void
{
    $output = new StringWriter();
    $client = new StubIftttClient(new ConsoleLogger($output));

    $client->trigger('heater_on');

    $this->assertStringContainsString('[STUB]', $output->getContents());
    $this->assertStringContainsString('heater_on', $output->getContents());
}

public function testTriggerSimulatesDelay(): void
{
    $client = new StubIftttClient();

    $start = microtime(true);
    $client->trigger('heater_on');
    $duration = (microtime(true) - $start) * 1000;

    $this->assertGreaterThan(50, $duration); // At least 50ms simulated delay
}
```

**3.2 GREEN - Implement StubIftttClient:**
```php
// src/Services/StubIftttClient.php
class StubIftttClient implements IftttClientInterface
{
    public function trigger(string $eventName): bool
    {
        $start = microtime(true);
        usleep(100000); // 100ms simulated delay
        $duration = (int)round((microtime(true) - $start) * 1000);

        $this->console->stub($eventName, $duration);
        $this->logger->log('ifttt_stub', ['event' => $eventName]);

        return true;
    }

    public function getMode(): string
    {
        return 'stub';
    }
}
```

### Step 4: LiveIftttClient (RED → GREEN)

**4.1 RED - Write failing tests:**
```php
// tests/Unit/Services/LiveIftttClientTest.php
public function testImplementsInterface(): void
{
    $client = new LiveIftttClient('test-key');
    $this->assertInstanceOf(IftttClientInterface::class, $client);
}

public function testGetModeReturnsLive(): void
{
    $client = new LiveIftttClient('test-key');
    $this->assertEquals('live', $client->getMode());
}

public function testTriggerMakesHttpRequest(): void
{
    // Use mock HTTP client
    $httpClient = $this->createMock(HttpClientInterface::class);
    $httpClient->expects($this->once())
        ->method('post')
        ->with($this->stringContains('maker.ifttt.com'));

    $client = new LiveIftttClient('test-key', $httpClient);
    $client->trigger('heater_on');
}

public function testTriggerBuildsCorrectUrl(): void
{
    $httpClient = $this->createMock(HttpClientInterface::class);
    $httpClient->expects($this->once())
        ->method('post')
        ->with('https://maker.ifttt.com/trigger/heater_on/with/key/test-key');

    $client = new LiveIftttClient('test-key', $httpClient);
    $client->trigger('heater_on');
}
```

**4.2 GREEN - Implement LiveIftttClient:**
```php
// src/Services/LiveIftttClient.php
class LiveIftttClient implements IftttClientInterface
{
    private const BASE_URL = 'https://maker.ifttt.com/trigger';

    public function trigger(string $eventName): bool
    {
        $url = sprintf('%s/%s/with/key/%s', self::BASE_URL, $eventName, $this->apiKey);

        $start = microtime(true);
        $response = $this->httpClient->post($url);
        $duration = (int)round((microtime(true) - $start) * 1000);

        $this->console->live($eventName, $response->getStatusCode(), $duration);
        $this->logger->log('ifttt_live', [
            'event' => $eventName,
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
        ]);

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    public function getMode(): string
    {
        return 'live';
    }
}
```

### Step 5: IftttClientFactory (RED → GREEN)

**5.1 RED - Write failing tests:**
```php
// tests/Unit/Services/IftttClientFactoryTest.php
public function testCreateStubModeReturnsStubClient(): void
{
    $factory = new IftttClientFactory();
    $client = $factory->create('stub');

    $this->assertInstanceOf(StubIftttClient::class, $client);
    $this->assertEquals('stub', $client->getMode());
}

public function testCreateLiveModeReturnsLiveClient(): void
{
    $factory = new IftttClientFactory(['IFTTT_WEBHOOK_KEY' => 'test-key']);
    $client = $factory->create('live');

    $this->assertInstanceOf(LiveIftttClient::class, $client);
    $this->assertEquals('live', $client->getMode());
}

public function testCreateLiveModeWithoutKeyThrowsException(): void
{
    $factory = new IftttClientFactory([]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('IFTTT_WEBHOOK_KEY required');

    $factory->create('live');
}

public function testAutoModeInTestingEnvReturnsStub(): void
{
    $factory = new IftttClientFactory([
        'APP_ENV' => 'testing',
        'IFTTT_WEBHOOK_KEY' => 'test-key'
    ]);

    $client = $factory->create('auto');
    $this->assertEquals('stub', $client->getMode());
}

public function testAutoModeWithKeyReturnsLive(): void
{
    $factory = new IftttClientFactory([
        'APP_ENV' => 'production',
        'IFTTT_WEBHOOK_KEY' => 'real-key'
    ]);

    $client = $factory->create('auto');
    $this->assertEquals('live', $client->getMode());
}

public function testAutoModeWithoutKeyReturnsStub(): void
{
    $factory = new IftttClientFactory(['APP_ENV' => 'production']);

    $client = $factory->create('auto');
    $this->assertEquals('stub', $client->getMode());
}
```

**5.2 GREEN - Implement IftttClientFactory:**
```php
// src/Services/IftttClientFactory.php
class IftttClientFactory
{
    public function create(string $mode = 'auto'): IftttClientInterface
    {
        return match ($mode) {
            'stub' => $this->createStub(),
            'live' => $this->createLive(),
            'auto' => $this->createAuto(),
            default => throw new InvalidArgumentException("Invalid mode: {$mode}"),
        };
    }

    private function createAuto(): IftttClientInterface
    {
        if ($this->isTestingEnvironment()) {
            return $this->createStub();
        }

        if ($this->hasApiKey()) {
            return $this->createLive();
        }

        return $this->createStub();
    }
}
```

### Step 6: Wire into EquipmentController (RED → GREEN)

**6.1 RED - Update existing tests:**
```php
// tests/Unit/Controllers/EquipmentControllerTest.php
public function testHeaterOnTriggersIftttClient(): void
{
    $iftttClient = $this->createMock(IftttClientInterface::class);
    $iftttClient->expects($this->once())
        ->method('trigger')
        ->with('hot-tub-heat-on')
        ->willReturn(true);

    $controller = new EquipmentController($this->testLogFile, $iftttClient);
    $response = $controller->heaterOn();

    $this->assertTrue($response['body']['success']);
}
```

**6.2 GREEN - Update EquipmentController:**
```php
// src/Controllers/EquipmentController.php
class EquipmentController
{
    public function __construct(
        string $logFile,
        private IftttClientInterface $iftttClient
    ) {
        $this->logger = new EventLogger($logFile);
    }

    public function heaterOn(): array
    {
        $timestamp = date('c');
        $success = $this->iftttClient->trigger('hot-tub-heat-on');
        $this->logger->log('heater_on', ['ifttt_success' => $success]);

        return [
            'status' => $success ? 200 : 500,
            'body' => [
                'success' => $success,
                'action' => 'heater_on',
                'timestamp' => $timestamp,
            ],
        ];
    }
}
```

### Step 7: Update Router/Index (Integration)

```php
// public/index.php
$config = [
    'APP_ENV' => $_ENV['APP_ENV'] ?? 'development',
    'IFTTT_MODE' => $_ENV['IFTTT_MODE'] ?? 'auto',
    'IFTTT_WEBHOOK_KEY' => $_ENV['IFTTT_WEBHOOK_KEY'] ?? null,
];

$factory = new IftttClientFactory($config);
$mode = $config['IFTTT_MODE'];
$iftttClient = $factory->create($mode);

// Log startup mode to console
echo sprintf("[INIT] IFTTT client mode: %s\n", $iftttClient->getMode());

$controller = new EquipmentController($logFile, $iftttClient);
```

## Test Execution Plan

1. Run tests after each step to verify TDD cycle
2. Use `vendor/bin/phpunit --filter=ClassName` for focused testing
3. Run full suite after each GREEN phase
4. Refactor only when all tests pass

## Success Criteria

1. All unit tests pass (100% coverage on new code)
2. Console clearly shows [STUB] or [LIVE] prefix for each IFTTT call
3. Frontend receives identical responses regardless of mode
4. Live mode requires explicit API key configuration
5. Testing environment defaults to stub mode for safety
6. Audit log captures all IFTTT operations with mode indicator
