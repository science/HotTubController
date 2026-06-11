<?php

declare(strict_types=1);

/**
 * Mint a JWT for a given subject/role/expiry and print it to stdout.
 *
 * Unlike bin/generate-cron-jwt.php (which writes CRON_JWT into .env), this prints
 * the token so it can be installed into an external client such as Home Assistant.
 * The JWT_SECRET is read from the backend .env file.
 *
 * Usage:
 *   php bin/mint-jwt.php --sub=homeassistant --role=readonly --years=10 [--env=/path/to/.env]
 *
 * IMPORTANT: DB-backed validation requires the subject to exist as a user whose
 * role matches the token's role. Provision the user first (e.g. an admin calling
 * POST /api/users with {"username":"homeassistant","role":"readonly", ...}), or the
 * token will be rejected with 401.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

/**
 * Build a signed JWT for the given subject, role and expiry.
 */
function mintJwt(string $jwtSecret, string $sub, string $role, int $years): string
{
    $payload = [
        'iat' => time(),
        'exp' => time() + ($years * 365 * 24 * 60 * 60),
        'sub' => $sub,
        'role' => $role,
    ];

    return JWT::encode($payload, $jwtSecret, 'HS256');
}

/**
 * Read JWT_SECRET from an .env file.
 *
 * @return array{success: bool, secret?: string, message?: string}
 */
function readJwtSecret(string $envPath): array
{
    if (!file_exists($envPath)) {
        return ['success' => false, 'message' => 'Env file not found: ' . $envPath];
    }

    $envContent = file_get_contents($envPath);
    if (!preg_match('/^JWT_SECRET=(.+)$/m', $envContent, $matches)) {
        return ['success' => false, 'message' => 'JWT_SECRET not found in ' . $envPath];
    }

    return ['success' => true, 'secret' => trim($matches[1])];
}

// Run if executed directly (not included/required by a test).
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $opts = getopt('', ['sub:', 'role:', 'years::', 'env::']);

    $sub = $opts['sub'] ?? null;
    $role = $opts['role'] ?? null;
    $years = (int) ($opts['years'] ?? 10);
    $envPath = $opts['env'] ?? (dirname(__DIR__) . '/.env');

    if ($sub === null || $role === null) {
        fwrite(STDERR, "Usage: php bin/mint-jwt.php --sub=<name> --role=<role> [--years=N] [--env=/path/.env]\n");
        exit(1);
    }

    $secretResult = readJwtSecret($envPath);
    if (!$secretResult['success']) {
        fwrite(STDERR, "✗ Error: {$secretResult['message']}\n");
        exit(1);
    }

    $token = mintJwt($secretResult['secret'], $sub, $role, $years);

    echo "Minted JWT (sub=$sub, role=$role, expires in $years years):\n\n";
    echo $token . "\n\n";

    // Print decoded claims for verification (payload is the middle segment).
    $payloadSegment = explode('.', $token)[1];
    $claims = json_decode(base64_decode(strtr($payloadSegment, '-_', '+/')), true);
    echo "Decoded claims:\n";
    echo json_encode($claims, JSON_PRETTY_PRINT) . "\n\n";
    echo "Reminder: user '$sub' must exist with role '$role' for this token to validate.\n";
    exit(0);
}
