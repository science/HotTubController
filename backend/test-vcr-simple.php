<?php

declare(strict_types=1);

/**
 * Simple VCR Test for WirelessTag API
 * 
 * Basic test to verify VCR can record/replay HTTP interactions
 */

require_once __DIR__ . '/vendor/autoload.php';

use VCR\VCR;

function testBasicVCR(): void
{
    echo "Testing VCR with basic cURL request...\n";
    
    // Configure VCR
    VCR::configure()
        ->setCassettePath(__DIR__ . '/tests/cassettes')
        ->setMode('new_episodes')
        ->enableLibraryHooks(['curl']);
    
    VCR::turnOn();
    VCR::insertCassette('simple-test.yml');
    
    try {
        // Make a simple HTTP request
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://httpbin.org/json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        echo "HTTP Response: {$httpCode}\n";
        echo "Response Length: " . strlen($response) . " bytes\n";
        
        if ($httpCode === 200) {
            echo "✓ Request successful\n";
        } else {
            echo "✗ Request failed\n";
        }
        
    } finally {
        VCR::eject();
        VCR::turnOff();
    }
}

function main(): void
{
    // Create cassettes directory
    if (!is_dir(__DIR__ . '/tests/cassettes')) {
        mkdir(__DIR__ . '/tests/cassettes', 0755, true);
    }
    
    echo "Simple VCR Test\n";
    echo "===============\n\n";
    
    testBasicVCR();
    
    // Check if cassette was created
    $cassettePath = __DIR__ . '/tests/cassettes/simple-test.yml';
    if (file_exists($cassettePath)) {
        $size = filesize($cassettePath);
        echo "\n✓ Cassette created: simple-test.yml ({$size} bytes)\n";
        
        if ($size > 0) {
            echo "First few lines of cassette:\n";
            $content = file_get_contents($cassettePath);
            echo substr($content, 0, 200) . "...\n";
        }
    } else {
        echo "\n✗ Cassette not found\n";
    }
}

main();