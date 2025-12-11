<?php

declare(strict_types=1);

namespace HotTub\Tests\Bin;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Tests for the generate-cron-jwt.php script functionality.
 *
 * Since the script is a standalone file, we test the core logic
 * by extracting it into a testable function.
 */
class GenerateCronJwtTest extends TestCase
{
    private string $testEnvFile;
    private string $jwtSecret = 'test-secret-for-phpunit-only';

    protected function setUp(): void
    {
        $this->testEnvFile = sys_get_temp_dir() . '/test-env-' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testEnvFile)) {
            unlink($this->testEnvFile);
        }
    }

    public function testGeneratedJwtIsValidAndDecodable(): void
    {
        // Arrange
        file_put_contents($this->testEnvFile, "JWT_SECRET={$this->jwtSecret}\n");

        // Act
        $result = $this->runGenerateCronJwt($this->testEnvFile);

        // Assert
        $this->assertTrue($result['success'], $result['message'] ?? 'Unknown error');

        // Verify the JWT is valid
        $envContent = file_get_contents($this->testEnvFile);
        preg_match('/^CRON_JWT=(.+)$/m', $envContent, $matches);
        $this->assertNotEmpty($matches[1], 'CRON_JWT should be written to .env');

        $jwt = $matches[1];
        $decoded = JWT::decode($jwt, new Key($this->jwtSecret, 'HS256'));

        $this->assertEquals('cron-system', $decoded->sub);
        $this->assertEquals('admin', $decoded->role);
    }

    public function testGeneratedJwtHasLongExpiry(): void
    {
        // Arrange
        file_put_contents($this->testEnvFile, "JWT_SECRET={$this->jwtSecret}\n");

        // Act
        $this->runGenerateCronJwt($this->testEnvFile);

        // Assert
        $envContent = file_get_contents($this->testEnvFile);
        preg_match('/^CRON_JWT=(.+)$/m', $envContent, $matches);
        $jwt = $matches[1];
        $decoded = JWT::decode($jwt, new Key($this->jwtSecret, 'HS256'));

        // Should expire at least 29 years from now (30 year expiry)
        $twentyNineYearsFromNow = time() + (29 * 365 * 24 * 60 * 60);
        $this->assertGreaterThan($twentyNineYearsFromNow, $decoded->exp);
    }

    public function testAppendsToEnvWhenCronJwtNotPresent(): void
    {
        // Arrange
        $initialContent = "JWT_SECRET={$this->jwtSecret}\nOTHER_VAR=value\n";
        file_put_contents($this->testEnvFile, $initialContent);

        // Act
        $this->runGenerateCronJwt($this->testEnvFile);

        // Assert
        $envContent = file_get_contents($this->testEnvFile);
        $this->assertStringContainsString('OTHER_VAR=value', $envContent);
        $this->assertStringContainsString('CRON_JWT=', $envContent);
    }

    public function testUpdatesCronJwtWhenAlreadyPresent(): void
    {
        // Arrange
        $initialContent = "JWT_SECRET={$this->jwtSecret}\nCRON_JWT=old-token-value\nOTHER_VAR=value\n";
        file_put_contents($this->testEnvFile, $initialContent);

        // Act
        $this->runGenerateCronJwt($this->testEnvFile);

        // Assert
        $envContent = file_get_contents($this->testEnvFile);
        $this->assertStringNotContainsString('old-token-value', $envContent);
        $this->assertStringContainsString('OTHER_VAR=value', $envContent);

        // Should only have one CRON_JWT line
        $matches = [];
        preg_match_all('/^CRON_JWT=/m', $envContent, $matches);
        $this->assertCount(1, $matches[0], 'Should have exactly one CRON_JWT line');
    }

    public function testFailsWhenJwtSecretMissing(): void
    {
        // Arrange
        file_put_contents($this->testEnvFile, "OTHER_VAR=value\n");

        // Act
        $result = $this->runGenerateCronJwt($this->testEnvFile);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JWT_SECRET', $result['message']);
    }

    public function testFailsWhenEnvFileNotFound(): void
    {
        // Act
        $result = $this->runGenerateCronJwt('/nonexistent/path/.env');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', strtolower($result['message']));
    }

    /**
     * Run the JWT generation logic using the actual script.
     */
    private function runGenerateCronJwt(string $envPath): array
    {
        require_once dirname(__DIR__, 2) . '/bin/generate-cron-jwt.php';
        return generateCronJwt($envPath);
    }
}
