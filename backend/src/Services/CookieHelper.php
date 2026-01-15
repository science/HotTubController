<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Helper for generating cookie options with proper path scoping.
 *
 * This class ensures auth cookies are scoped to the deployment path,
 * preventing conflicts when multiple apps share the same domain.
 *
 * Example: On misuse.org, movie-night at /dinner-and-a-movie and
 * hot-tub-controller at /tub each need their own cookie scope.
 *
 * The cookie path is derived automatically from the deployment structure,
 * not configured manually, ensuring it always matches the actual deploy path.
 */
class CookieHelper
{
    private const COOKIE_NAME = 'auth_token';
    private const BACKEND_SUFFIX = '/backend/public';

    private string $cookiePath;

    /**
     * @param string $cookiePath The base path for cookie scoping (e.g., /tub)
     */
    public function __construct(string $cookiePath = '/')
    {
        $this->cookiePath = $cookiePath;
    }

    /**
     * Derive the app base path from the current request's SCRIPT_NAME.
     *
     * Our codebase convention places index.php at ./backend/public/index.php.
     * This method strips that known suffix from the deployment path to get
     * the app's root URL path.
     *
     * Examples:
     *   /dinner-and-a-movie/backend/public -> /dinner-and-a-movie
     *   /tub/backend/public -> /tub
     *   /apps/tub/backend/public -> /apps/tub
     *   / (dev server at root) -> /
     */
    public static function deriveAppBasePath(): string
    {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');

        // Strip the known /backend/public suffix to get the app root
        if (str_ends_with($scriptDir, self::BACKEND_SUFFIX)) {
            $basePath = substr($scriptDir, 0, -strlen(self::BACKEND_SUFFIX));
            return $basePath === '' ? '/' : $basePath;
        }

        // Development or non-standard deployment: default to root
        // (In dev, apps run on different ports so root path is fine)
        return '/';
    }

    /**
     * Get cookie name for auth token.
     */
    public function getCookieName(): string
    {
        return self::COOKIE_NAME;
    }

    /**
     * Get cookie options for setting an auth token.
     *
     * @param string $token The JWT token value
     * @param int $expirySeconds Seconds until expiry
     * @return array<string, mixed> Options array for setcookie()
     */
    public function getAuthCookieOptions(string $token, int $expirySeconds): array
    {
        return [
            'expires' => time() + $expirySeconds,
            'path' => $this->cookiePath,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => isset($_SERVER['HTTPS']),
        ];
    }

    /**
     * Get cookie options for clearing the auth token.
     *
     * @return array<string, mixed> Options array for setcookie()
     */
    public function getClearCookieOptions(): array
    {
        return [
            'expires' => time() - 3600,
            'path' => $this->cookiePath,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => isset($_SERVER['HTTPS']),
        ];
    }

    /**
     * Get the configured cookie path.
     */
    public function getCookiePath(): string
    {
        return $this->cookiePath;
    }
}
