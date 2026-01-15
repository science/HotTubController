<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\CookieHelper;

/**
 * Tests for CookieHelper - ensures cookies are scoped correctly per deployment.
 *
 * Bug context: When multiple apps (movie-night and hot-tub-controller) are deployed
 * on the same domain (misuse.org) at different paths (/dinner-and-a-movie and /tub),
 * they were both setting auth_token cookies at path="/", causing login conflicts.
 * Logging into one app would overwrite the other app's cookie.
 *
 * Solution: Each app derives its cookie path from its deployment location by
 * stripping the known /backend/public suffix from SCRIPT_NAME.
 */
class CookieHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up any SCRIPT_NAME we set during tests
        unset($_SERVER['SCRIPT_NAME']);
    }

    // =========================================================================
    // Tests for deriveAppBasePath()
    // =========================================================================

    public function testDeriveAppBasePathForProductionTub(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/tub/backend/public/index.php';

        $path = CookieHelper::deriveAppBasePath();

        $this->assertEquals('/tub', $path);
    }

    public function testDeriveAppBasePathForProductionMovieNight(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/dinner-and-a-movie/backend/public/index.php';

        $path = CookieHelper::deriveAppBasePath();

        $this->assertEquals('/dinner-and-a-movie', $path);
    }

    public function testDeriveAppBasePathForNestedDeployment(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/apps/my-app/backend/public/index.php';

        $path = CookieHelper::deriveAppBasePath();

        $this->assertEquals('/apps/my-app', $path);
    }

    public function testDeriveAppBasePathForRootDeployment(): void
    {
        // When deployed directly at domain root (e.g., app.example.com)
        $_SERVER['SCRIPT_NAME'] = '/backend/public/index.php';

        $path = CookieHelper::deriveAppBasePath();

        $this->assertEquals('/', $path);
    }

    public function testDeriveAppBasePathForDevServer(): void
    {
        // php -S localhost:8080 -t public sets SCRIPT_NAME to /index.php
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $path = CookieHelper::deriveAppBasePath();

        $this->assertEquals('/', $path);
    }

    public function testDeriveAppBasePathWithNoScriptName(): void
    {
        unset($_SERVER['SCRIPT_NAME']);

        $path = CookieHelper::deriveAppBasePath();

        $this->assertEquals('/', $path);
    }

    // =========================================================================
    // Tests for cookie options
    // =========================================================================

    public function testGetAuthCookieOptionsUsesProvidedPath(): void
    {
        $helper = new CookieHelper('/tub');

        $options = $helper->getAuthCookieOptions('test-token', 3600);

        $this->assertEquals('/tub', $options['path']);
    }

    public function testGetAuthCookieOptionsDefaultsToRoot(): void
    {
        $helper = new CookieHelper();

        $options = $helper->getAuthCookieOptions('test-token', 3600);

        $this->assertEquals('/', $options['path']);
    }

    public function testGetAuthCookieOptionsIncludesSecuritySettings(): void
    {
        $helper = new CookieHelper('/app');

        $options = $helper->getAuthCookieOptions('test-token', 3600);

        $this->assertTrue($options['httponly']);
        $this->assertEquals('Lax', $options['samesite']);
    }

    public function testGetAuthCookieOptionsCalculatesExpiry(): void
    {
        $helper = new CookieHelper();

        $beforeTime = time();
        $options = $helper->getAuthCookieOptions('test-token', 3600);
        $afterTime = time();

        $this->assertGreaterThanOrEqual($beforeTime + 3600, $options['expires']);
        $this->assertLessThanOrEqual($afterTime + 3600, $options['expires']);
    }

    public function testGetClearCookieOptionsUsesProvidedPath(): void
    {
        $helper = new CookieHelper('/tub');

        $options = $helper->getClearCookieOptions();

        $this->assertEquals('/tub', $options['path']);
    }

    public function testGetClearCookieOptionsHasExpiredTimestamp(): void
    {
        $helper = new CookieHelper();

        $options = $helper->getClearCookieOptions();

        $this->assertLessThan(time(), $options['expires']);
    }

    public function testGetCookieName(): void
    {
        $helper = new CookieHelper();

        $this->assertEquals('auth_token', $helper->getCookieName());
    }

    public function testGetCookiePath(): void
    {
        $helper = new CookieHelper('/my-app');

        $this->assertEquals('/my-app', $helper->getCookiePath());
    }
}
