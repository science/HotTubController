# WirelessTag OAuth Implementation Guide

This document outlines the OAuth authentication process for the WirelessTag API. Based on analysis of the existing Tasker implementation, WirelessTag uses a non-standard OAuth 2.0 implementation that requires specific handling.

## Overview

WirelessTag's OAuth implementation differs from standard OAuth 2.0 flows in several ways:
- Non-standard token acquisition process
- Bearer token format and structure
- Token refresh mechanisms
- Session management requirements

**Note**: The exact OAuth flow details need to be determined through testing and API exploration, as WirelessTag's documentation may be incomplete or outdated.

## Authentication Requirements

### Headers Required for API Calls
```
Authorization: Bearer {oauth_token}
Content-Type: application/json; charset=utf-8
```

### Token Format
From the Tasker analysis, tokens are stored as Bearer tokens in the format:
- Variable name in Tasker: `%WTAG_API_KEY` 
- Usage: `Authorization: Bearer {token_value}`
- Token appears to be a long-lived credential (not short-lived access tokens)

## Token Management Strategy

### Secure Token Storage

```php
<?php

class WirelessTagTokenManager
{
    private string $tokenFile;
    private ?string $encryptionKey;
    
    public function __construct(string $tokenFile, ?string $encryptionKey = null)
    {
        $this->tokenFile = $tokenFile;
        $this->encryptionKey = $encryptionKey;
    }
    
    /**
     * Store OAuth token securely
     */
    public function storeToken(string $token, array $metadata = []): void
    {
        $tokenData = [
            'token' => $token,
            'created_at' => time(),
            'metadata' => $metadata
        ];
        
        $jsonData = json_encode($tokenData);
        
        if ($this->encryptionKey) {
            $jsonData = $this->encrypt($jsonData);
        }
        
        if (file_put_contents($this->tokenFile, $jsonData, LOCK_EX) === false) {
            throw new RuntimeException("Failed to store OAuth token");
        }
        
        // Set restrictive permissions
        chmod($this->tokenFile, 0600);
    }
    
    /**
     * Retrieve stored OAuth token
     */
    public function getToken(): ?string
    {
        if (!file_exists($this->tokenFile)) {
            return null;
        }
        
        $data = file_get_contents($this->tokenFile);
        
        if ($data === false) {
            return null;
        }
        
        if ($this->encryptionKey) {
            $data = $this->decrypt($data);
            if ($data === false) {
                return null;
            }
        }
        
        $tokenData = json_decode($data, true);
        
        if (!$tokenData || !isset($tokenData['token'])) {
            return null;
        }
        
        return $tokenData['token'];
    }
    
    /**
     * Check if stored token exists and is potentially valid
     */
    public function hasValidToken(): bool
    {
        $token = $this->getToken();
        return $token !== null && strlen($token) > 0;
    }
    
    private function encrypt(string $data): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    private function decrypt(string $encryptedData): string|false
    {
        $data = base64_decode($encryptedData);
        if ($data === false || strlen($data) < 16) {
            return false;
        }
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
    }
}
```

## OAuth Flow Investigation

### Manual Token Acquisition Process

Since WirelessTag's OAuth flow is non-standard, manual token acquisition may be required initially:

```php
<?php

class WirelessTagOAuthHelper
{
    private string $baseUrl = 'https://wirelesstag.net';
    
    /**
     * Attempt to obtain OAuth token through web authentication flow
     * 
     * NOTE: This is a placeholder - actual implementation depends on
     * WirelessTag's specific OAuth endpoints and requirements
     */
    public function initiateOAuthFlow(string $username, string $password): array
    {
        // Step 1: Attempt login to get session
        $loginResponse = $this->performLogin($username, $password);
        
        // Step 2: Extract OAuth token from session/response
        // (Implementation depends on WirelessTag's specific flow)
        
        // Step 3: Validate token with test API call
        $token = $this->extractTokenFromResponse($loginResponse);
        
        if ($this->validateToken($token)) {
            return [
                'success' => true,
                'token' => $token,
                'expires_at' => $this->determineTokenExpiry($loginResponse)
            ];
        }
        
        return ['success' => false, 'error' => 'Token validation failed'];
    }
    
    /**
     * Validate OAuth token by making test API call
     */
    public function validateToken(string $token): bool
    {
        $testUrl = $this->baseUrl . '/ethClient.asmx/GetTagManagerSettings';
        
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=utf-8'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode >= 200 && $httpCode < 300;
    }
    
    private function performLogin(string $username, string $password): string
    {
        // Implementation needed based on WirelessTag's login process
        // This might involve:
        // 1. Form-based login
        // 2. Session cookie extraction
        // 3. OAuth redirect handling
        
        throw new RuntimeException("Login implementation requires WirelessTag OAuth flow analysis");
    }
    
    private function extractTokenFromResponse(string $response): string
    {
        // Extract Bearer token from login response
        // Format depends on WirelessTag's specific response structure
        
        throw new RuntimeException("Token extraction requires WirelessTag response format analysis");
    }
    
    private function determineTokenExpiry(string $response): ?int
    {
        // Attempt to determine token expiration from response
        // May not be available for long-lived tokens
        
        return null; // Assume long-lived token
    }
}
```

## Configuration Management

### OAuth Configuration Structure

```php
<?php

class WirelessTagOAuthConfig
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig();
    }
    
    public static function fromFile(string $configFile): self
    {
        if (!file_exists($configFile)) {
            throw new RuntimeException("OAuth config file not found: {$configFile}");
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        
        if (!$config) {
            throw new RuntimeException("Invalid OAuth config file format");
        }
        
        return new self($config);
    }
    
    public function getTokenStoragePath(): string
    {
        return $this->config['token_storage_path'] ?? './storage/wirelesstag-token.json';
    }
    
    public function getEncryptionKey(): ?string
    {
        return $this->config['encryption_key'] ?? null;
    }
    
    public function getCredentials(): array
    {
        return [
            'username' => $this->config['username'] ?? null,
            'password' => $this->config['password'] ?? null
        ];
    }
    
    public function getTokenRefreshSettings(): array
    {
        return [
            'auto_refresh' => $this->config['auto_refresh'] ?? false,
            'refresh_threshold_hours' => $this->config['refresh_threshold_hours'] ?? 24,
            'max_age_days' => $this->config['max_age_days'] ?? 30
        ];
    }
    
    private function validateConfig(): void
    {
        // Validate required configuration fields
        $required = ['username', 'password'];
        
        foreach ($required as $field) {
            if (empty($this->config[$field])) {
                throw new InvalidArgumentException("Missing required OAuth config field: {$field}");
            }
        }
    }
}
```

### Example Configuration File

```json
{
  "username": "your_wirelesstag_username",
  "password": "your_wirelesstag_password",
  "token_storage_path": "./storage/wirelesstag-oauth-token.json",
  "encryption_key": "your_32_character_encryption_key_here",
  "auto_refresh": false,
  "refresh_threshold_hours": 24,
  "max_age_days": 30
}
```

## Integration with API Client

### Enhanced WirelessTag Client with OAuth Management

```php
<?php

class WirelessTagClientWithOAuth extends WirelessTagClient
{
    private WirelessTagTokenManager $tokenManager;
    private WirelessTagOAuthHelper $oauthHelper;
    private WirelessTagOAuthConfig $config;
    
    public function __construct(WirelessTagOAuthConfig $config)
    {
        $this->config = $config;
        $this->tokenManager = new WirelessTagTokenManager(
            $config->getTokenStoragePath(),
            $config->getEncryptionKey()
        );
        $this->oauthHelper = new WirelessTagOAuthHelper();
        
        $token = $this->getValidToken();
        parent::__construct($token);
    }
    
    /**
     * Get valid OAuth token, refreshing if necessary
     */
    private function getValidToken(): string
    {
        $token = $this->tokenManager->getToken();
        
        if (!$token) {
            $token = $this->acquireNewToken();
        } elseif (!$this->oauthHelper->validateToken($token)) {
            error_log("Stored WirelessTag token is invalid, acquiring new token");
            $token = $this->acquireNewToken();
        }
        
        return $token;
    }
    
    /**
     * Acquire new OAuth token
     */
    private function acquireNewToken(): string
    {
        $credentials = $this->config->getCredentials();
        
        $result = $this->oauthHelper->initiateOAuthFlow(
            $credentials['username'],
            $credentials['password']
        );
        
        if (!$result['success']) {
            throw new RuntimeException("Failed to acquire OAuth token: " . $result['error']);
        }
        
        $this->tokenManager->storeToken($result['token'], [
            'acquired_at' => time(),
            'expires_at' => $result['expires_at']
        ]);
        
        return $result['token'];
    }
    
    /**
     * Override parent method to handle token refresh on authentication errors
     */
    protected function makeRequest(string $endpoint, array $payload): ?array
    {
        $response = parent::makeRequest($endpoint, $payload);
        
        // If we get an authentication error, try refreshing the token once
        if ($response === null && $this->lastHttpCode === 401) {
            error_log("WirelessTag authentication failed, refreshing token");
            
            $newToken = $this->acquireNewToken();
            $this->oauthToken = $newToken;
            
            // Retry the request with new token
            $response = parent::makeRequest($endpoint, $payload);
        }
        
        return $response;
    }
}
```

## Security Considerations

### Token Protection

1. **File Permissions**: Store token files with 600 permissions (owner read/write only)
2. **Encryption**: Encrypt stored tokens with a strong encryption key
3. **Environment Variables**: Store encryption keys in environment variables, not config files
4. **Logging**: Never log actual token values, only success/failure status
5. **Rotation**: Implement token rotation for long-term security

### Example Secure Implementation

```php
<?php

// In production, load from environment variable
$encryptionKey = $_ENV['WIRELESSTAG_ENCRYPTION_KEY'] ?? null;

if (!$encryptionKey) {
    throw new RuntimeException("WirelessTag encryption key not configured");
}

$oauthConfig = new WirelessTagOAuthConfig([
    'username' => $_ENV['WIRELESSTAG_USERNAME'],
    'password' => $_ENV['WIRELESSTAG_PASSWORD'],
    'token_storage_path' => __DIR__ . '/storage/wirelesstag-token.enc',
    'encryption_key' => $encryptionKey,
    'auto_refresh' => true
]);

$client = new WirelessTagClientWithOAuth($oauthConfig);
```

## Development and Testing Notes

### OAuth Flow Discovery

To fully implement WirelessTag OAuth, the following need to be determined through testing:

1. **Login Endpoint**: URL and parameters for initial authentication
2. **Token Response Format**: How OAuth token is returned (JSON, headers, cookies)
3. **Token Expiration**: Whether tokens expire and how to detect expiration
4. **Refresh Process**: Whether refresh tokens are used or full re-authentication required
5. **Session Management**: Whether session cookies are required alongside Bearer tokens

### Testing Strategy

```php
<?php

class WirelessTagOAuthTester
{
    public function discoverOAuthFlow(string $username, string $password): array
    {
        $results = [];
        
        // Test various potential OAuth endpoints
        $potentialEndpoints = [
            '/oauth/token',
            '/api/oauth/token', 
            '/ethClient.asmx/SignIn',
            '/account/signin'
        ];
        
        foreach ($potentialEndpoints as $endpoint) {
            $results[$endpoint] = $this->testEndpoint($endpoint, $username, $password);
        }
        
        return $results;
    }
    
    private function testEndpoint(string $endpoint, string $username, string $password): array
    {
        // Test endpoint with various authentication methods
        // Record response format, headers, cookies, etc.
        
        return [
            'tested' => true,
            'response_code' => 0,
            'response_headers' => [],
            'response_body' => '',
            'notes' => 'Endpoint discovery needed'
        ];
    }
}
```

## Migration Path

### From Tasker to Production

1. **Extract Current Token**: Get the working token from Tasker's `%WTAG_API_KEY` variable
2. **Validate Token**: Test the extracted token with API calls
3. **Store Securely**: Save the token using the secure storage system
4. **Monitor Expiration**: Track token validity and plan for refresh/renewal
5. **Document Process**: Record the working authentication flow for future reference

This approach allows immediate implementation using the known-working token while developing the full OAuth flow understanding.