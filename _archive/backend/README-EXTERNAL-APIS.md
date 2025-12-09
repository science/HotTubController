# External API Integration

This document explains how to set up and use the WirelessTag and IFTTT API integrations for the hot tub controller.

## Overview

The hot tub controller uses two external APIs:
- **WirelessTag API**: Temperature monitoring from wireless sensors
- **IFTTT Webhooks**: Equipment control through SmartLife automation

## Quick Setup

### 1. Configure Environment Variables

Copy your actual tokens into the `.env` file (already git-ignored):

```bash
cp .env.example .env
```

Then edit `.env` and add your real tokens:

```env
# WirelessTag API for temperature monitoring
WIRELESSTAG_OAUTH_TOKEN=your_actual_bearer_token_here
WIRELESSTAG_HOT_TUB_DEVICE_ID=your_hot_tub_device_uuid
WIRELESSTAG_AMBIENT_DEVICE_ID=your_ambient_sensor_uuid

# IFTTT Webhooks for equipment control  
IFTTT_WEBHOOK_KEY=your_ifttt_webhook_key_here
```

### 2. Set Secure Permissions

```bash
chmod 600 .env
```

### 3. Test Configuration

```bash
php test-external-apis.php
```

This will verify your tokens and test API connectivity without triggering any equipment.

## API Clients

### WirelessTag Client

```php
use HotTubController\Config\ExternalApiConfig;
use HotTubController\Services\WirelessTagClient;

$config = new ExternalApiConfig();
$wirelessTag = new WirelessTagClient($config->getWirelessTagToken());

// Get cached temperature (battery-friendly)
$tempData = $wirelessTag->getCachedTemperatureData($config->getHotTubDeviceId());
$processed = $wirelessTag->processTemperatureData($tempData);

echo "Water: " . $processed['water_temperature']['fahrenheit'] . "°F\n";
echo "Ambient: " . $processed['ambient_temperature']['fahrenheit'] . "°F\n";

// Get fresh reading (uses battery - use sparingly)
$freshData = $wirelessTag->getFreshTemperatureData($config->getHotTubDeviceId());
```

### IFTTT Client

```php
use HotTubController\Services\IftttWebhookClient;

$ifttt = new IftttWebhookClient($config->getIftttWebhookKey());

// Control hot tub heating
$ifttt->startHeating();  // Starts pump, then heater
$ifttt->stopHeating();   // Stops heater, cools down, stops pump

// Control ionizer
$ifttt->startIonizer();
$ifttt->stopIonizer();
```

## Battery Conservation

WirelessTag sensors run on battery, so use these patterns:

- **Routine Monitoring**: Use `getCachedTemperatureData()` every 2-5 minutes
- **Critical Decisions**: Use `getFreshTemperatureData()` only when starting/stopping heating
- **Near Target**: Use fresh readings every 15 seconds when close to target temperature

## Error Handling

Both clients include comprehensive error handling:

- **Automatic Retries**: Exponential backoff for failed requests
- **Validation**: Temperature bounds checking and sensor health monitoring  
- **Logging**: Detailed logging without exposing token values
- **Graceful Degradation**: Fallback behavior when APIs are unavailable

## Temperature Data Structure

```php
$processed = $wirelessTag->processTemperatureData($rawData);

// Structure:
[
    'device_id' => 'sensor-uuid',
    'water_temperature' => [
        'celsius' => 37.8,
        'fahrenheit' => 100.0,
        'source' => 'primary_probe'
    ],
    'ambient_temperature' => [
        'celsius' => 21.1,
        'fahrenheit' => 70.0,
        'source' => 'capacitive_sensor'
    ],
    'sensor_info' => [
        'battery_voltage' => 3.1,
        'signal_strength_dbm' => -65
    ],
    'data_timestamp' => 1693932000
]
```

## Safety Features

- **Temperature Validation**: Bounds checking (32-120°F)
- **Token Protection**: Never logged or exposed in error messages
- **Equipment Safety**: IFTTT scenes handle proper heating/cooling sequences
- **API Rate Limiting**: Prevents excessive calls to preserve battery life

## Testing

### Test Scripts

```bash
# Basic connectivity test
php test-external-apis.php

# Detailed test with verbose output
php test-external-apis.php --detailed

# Interactive usage example (safe - no actual control)
php example-usage.php
```

### Expected Test Output

```
=== Configuration Test ===
✓ PASS Configuration loaded
✓ PASS WIRELESSTAG_OAUTH_TOKEN - length: 128, preview: abcd1234...
✓ PASS IFTTT_WEBHOOK_KEY - length: 22, preview: dGhpc19p...

=== IFTTT Webhook Test ===
✓ PASS IFTTT connectivity - responded in 234ms

=== WirelessTag API Test ===  
✓ PASS WirelessTag connectivity - authenticated, responded in 456ms
✓ PASS Temperature data retrieval - cached data retrieved
✓ PASS Water temperature validation - 95.2°F is reasonable

🎉 All systems ready! You can now integrate the external APIs.
```

## Production Deployment

### GitHub Actions Secrets

When deploying to production, add these as repository secrets:

- `WIRELESSTAG_OAUTH_TOKEN`
- `WIRELESSTAG_HOT_TUB_DEVICE_ID` 
- `WIRELESSTAG_AMBIENT_DEVICE_ID`
- `IFTTT_WEBHOOK_KEY`

### Environment Variable Access

```yaml
# In your deployment workflow
env:
  WIRELESSTAG_OAUTH_TOKEN: ${{ secrets.WIRELESSTAG_OAUTH_TOKEN }}
  IFTTT_WEBHOOK_KEY: ${{ secrets.IFTTT_WEBHOOK_KEY }}
```

## Security Considerations

- ✅ Tokens stored in `.env` (git-ignored)
- ✅ File permissions set to 600 (owner-only)
- ✅ No tokens in logs or error messages
- ✅ Encrypted secrets in CI/CD
- ✅ Validation without exposure of token values

## Files Created

```
/backend/
├── src/
│   ├── Config/
│   │   └── ExternalApiConfig.php     # Secure token management
│   └── Services/
│       ├── IftttWebhookClient.php    # IFTTT webhook client
│       └── WirelessTagClient.php     # WirelessTag API client
├── test-external-apis.php            # Configuration validation
├── example-usage.php                 # Usage examples
└── .env.example                      # Updated with API config
```

## Troubleshooting

### "Missing required environment variables"
- Check that `.env` file exists and contains all required tokens
- Verify file is readable: `ls -la .env`

### "WirelessTag connectivity failed"
- Verify OAuth token is valid and not expired
- Check device IDs are correct
- Test with: `php test-external-apis.php --detailed`

### "IFTTT webhooks unavailable"
- Verify webhook key is correct
- Check IFTTT service status
- Ensure webhook events are properly configured in IFTTT

### Temperature readings out of range
- Check sensor placement and calibration
- Verify device IDs match actual sensors
- Consider ambient temperature calibration for thermal influence

## Next Steps

1. **Verify Setup**: Run `php test-external-apis.php` and ensure all tests pass
2. **Integration**: Use the client classes in your hot tub controller endpoints
3. **Monitoring**: Implement health checks using the built-in connectivity tests  
4. **Production**: Deploy with environment variables or encrypted secrets

The external API integration is now complete and ready for use in your hot tub heating control system!