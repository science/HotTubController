<?php

declare(strict_types=1);

namespace HotTub\Tests\Bin;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Tests for the mint-jwt.php script's core logic.
 */
class MintJwtTest extends TestCase
{
    private string $jwtSecret = 'test-secret-for-phpunit-only';

    private function loadScript(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/mint-jwt.php';
    }

    public function testMintedTokenDecodesToRequestedSubjectAndRole(): void
    {
        $this->loadScript();

        $token = mintJwt($this->jwtSecret, 'homeassistant', 'readonly', 10);
        $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));

        $this->assertEquals('homeassistant', $decoded->sub);
        $this->assertEquals('readonly', $decoded->role);
    }

    public function testMintedTokenHasRequestedExpiry(): void
    {
        $this->loadScript();

        $token = mintJwt($this->jwtSecret, 'homeassistant', 'readonly', 10);
        $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));

        $nineYears = time() + (9 * 365 * 24 * 60 * 60);
        $elevenYears = time() + (11 * 365 * 24 * 60 * 60);
        $this->assertGreaterThan($nineYears, $decoded->exp);
        $this->assertLessThan($elevenYears, $decoded->exp);
    }

    public function testReadJwtSecretFailsWhenFileMissing(): void
    {
        $this->loadScript();

        $result = readJwtSecret('/nonexistent/path/.env');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', strtolower($result['message']));
    }

    public function testReadJwtSecretExtractsSecret(): void
    {
        $this->loadScript();

        $envFile = sys_get_temp_dir() . '/mint-jwt-test-' . uniqid() . '.env';
        file_put_contents($envFile, "JWT_SECRET={$this->jwtSecret}\nOTHER=1\n");

        $result = readJwtSecret($envFile);

        $this->assertTrue($result['success']);
        $this->assertEquals($this->jwtSecret, $result['secret']);

        unlink($envFile);
    }
}
