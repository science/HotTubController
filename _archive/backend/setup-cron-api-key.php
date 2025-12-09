<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Services\CronSecurityManager;

echo "Hot Tub Controller - Cron API Key Setup\n";
echo "=====================================\n\n";

try {
    $securityManager = new CronSecurityManager();
    
    // Check if API key already exists
    if ($securityManager->apiKeyExists()) {
        $keyInfo = $securityManager->getApiKeyInfo();
        
        echo "Existing API key found:\n";
        echo "  File: {$keyInfo['file_path']}\n";
        echo "  Modified: {$keyInfo['modified']}\n";
        echo "  Permissions: {$keyInfo['permissions']}\n";
        echo "  Valid format: " . ($keyInfo['valid_format'] ? 'Yes' : 'No') . "\n";
        
        if (isset($keyInfo['key_preview'])) {
            echo "  Key preview: {$keyInfo['key_preview']}\n";
        }
        
        echo "\n";
        
        // Ask if user wants to regenerate
        echo "Do you want to regenerate the API key? (y/N): ";
        $input = trim(fgets(STDIN));
        
        if (strtolower($input) !== 'y' && strtolower($input) !== 'yes') {
            echo "Using existing API key.\n";
            exit(0);
        }
        
        echo "\nRegenerating API key...\n";
        $rotationResult = $securityManager->rotateApiKey();
        
        echo "API key rotated successfully!\n";
        echo "Old key backed up.\n";
        echo "New key preview: " . substr($rotationResult['new_key'], 0, 20) . "...\n";
        
    } else {
        echo "No existing API key found. Generating new key...\n";
        $apiKey = $securityManager->generateApiKey(false);
        
        echo "API key generated successfully!\n";
        echo "Key preview: " . substr($apiKey, 0, 20) . "...\n";
    }
    
    // Display security information
    $keyInfo = $securityManager->getApiKeyInfo();
    echo "\nSecurity Information:\n";
    echo "  File location: {$keyInfo['file_path']}\n";
    echo "  File permissions: {$keyInfo['permissions']} (should be 0600)\n";
    echo "  File size: {$keyInfo['size']} bytes\n";
    echo "  Valid format: " . ($keyInfo['valid_format'] ? 'Yes' : 'No') . "\n";
    
    // Validate permissions
    if ($keyInfo['permissions'] !== '0600') {
        echo "\nWARNING: File permissions are not secure!\n";
        echo "Run: chmod 600 {$keyInfo['file_path']}\n";
    } else {
        echo "\n✓ File permissions are secure.\n";
    }
    
    // Test key validation
    echo "\nTesting API key validation...\n";
    $currentKey = $securityManager->getCurrentApiKey();
    $isValid = $securityManager->verifyApiKey($currentKey);
    
    if ($isValid) {
        echo "✓ API key validation test passed.\n";
    } else {
        echo "✗ API key validation test failed!\n";
        exit(1);
    }
    
    echo "\nSetup completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. The API key is now available for cron jobs\n";
    echo "2. Test the heating control endpoints\n";
    echo "3. Create your first heating schedule\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nSetup failed. Please check permissions and try again.\n";
    exit(1);
}