# Hot Tub Controller Target Architecture Proposal

*Proposed web-based architecture to replace the Android Tasker system*

## Executive Summary

This proposal outlines a modern, cross-platform web application to replace the existing Android Tasker-based hot tub controller. The system will maintain all current functionality while providing improved accessibility, maintainability, and extensibility.

## Architecture Overview

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Web Client    │◄──►│   PHP Proxy     │◄──►│  External APIs  │
│   (Svelte SPA)  │    │   (Slim/Lumen)  │    │ (IFTTT/Sensors) │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         ▲                       ▲
         │                       │
         ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│  Local Storage  │    │   Config Files  │
│   (Browser)     │    │     (JSON)      │
└─────────────────┘    └─────────────────┘
```

## Backend Architecture Recommendation

### Technology Choice: PHP as Pure CORS Proxy

**Rationale:**
- **Deploy Once**: Set up proxy on cheap hosting and never touch again
- **Zero Business Logic**: Frontend handles all control algorithms
- **Universal Compatibility**: Works with any frontend framework
- **Minimal Attack Surface**: Simple proxy reduces security concerns
- **Easy Debugging**: All logic visible in frontend developer tools

### Backend Components

#### 1. Generic API Proxy (`/api/v1/proxy`)
**Single Universal Endpoint:**
```php
POST /api/v1/proxy
Content-Type: application/json

{
  "auth": "user_session_token",
  "endpoint": "https://wirelesstag.net/ethClient.asmx/GetTagList",
  "method": "POST", 
  "headers": {
    "Authorization": "Bearer 843e484b-c032-4cdb-9358-a77f82d4dc45",
    "Content-Type": "application/json"
  },
  "body": {"id": "0"}
}
```

**Proxy Responsibilities:**
- Validate user authentication token
- Forward request to external API
- Add CORS headers for browser access
- Return response unchanged
- Log requests for debugging (optional)

**What the Proxy Does NOT Do:**
- ❌ Temperature control logic
- ❌ Business rules or validation  
- ❌ Device state management
- ❌ Scheduling algorithms
- ❌ Error handling beyond HTTP errors

#### 2. Token-Based Authentication System

**Admin Creates New Users:**
```php
POST /api/v1/admin/user
Content-Type: application/json

{
  "master_password": "admin_deployment_password", 
  "name": "Steve"
}

Response:
{
  "token": "tk_8f3a2b1c9d4e5f6g",
  "user_id": "usr_abc123",
  "created": "2025-01-15T10:30:00Z"
}
```

**Users Access via Simple Tokens:**
```php  
POST /api/v1/proxy
{
  "token": "tk_8f3a2b1c9d4e5f6g",
  "endpoint": "https://maker.ifttt.com/trigger/hot-tub-heat-on/with/key/...",
  "method": "POST",
  "headers": {},
  "body": {}
}
```

**Token Management:**
```php
// List all users (admin only)
GET /api/v1/admin/users?master_password=...

// Revoke user access (admin only) 
DELETE /api/v1/admin/user/usr_abc123?master_password=...
```

#### 3. Configuration Files

**Static Configuration** (`config.json`) - deployed once:
```json
{
  "auth": {
    "master_password_hash": "bcrypt_hash_here"
  },
  "cors": {
    "allowed_origins": ["https://username.github.io"],
    "allowed_methods": ["POST", "GET", "OPTIONS", "DELETE"]
  },
  "rate_limit": {
    "requests_per_minute": 60,
    "requests_per_hour": 1000
  }
}
```

**User Database** (`tokens.json`) - managed by PHP:
```json
{
  "tokens": [
    {
      "id": "usr_abc123",
      "token": "tk_8f3a2b1c9d4e5f6g",
      "name": "Steve", 
      "created": "2025-01-15T10:30:00Z",
      "active": true,
      "last_used": "2025-01-15T14:22:00Z"
    },
    {
      "id": "usr_def456", 
      "token": "tk_9g6h5i4j3k2l1m0n",
      "name": "Sarah",
      "created": "2025-01-16T09:15:00Z", 
      "active": false,
      "last_used": "2025-01-16T12:45:00Z"
    }
  ]
}
```

**That's it!** The PHP backend has zero knowledge of:
- Hot tub devices or sensors
- Temperature control algorithms  
- IFTTT webhooks or WirelessTag APIs
- Scheduling logic or business rules

### Backend Security Features
- Input validation and sanitization
- Rate limiting (prevent API spam)
- CORS headers for specific origins only
- Request logging for debugging
- Error handling without information leakage

## Frontend Architecture Recommendation

### Technology Choice: Svelte

**Why Svelte over React/Vue:**

1. **Simplicity**: Easiest learning curve, closest to vanilla HTML/CSS/JS
2. **Performance**: Compiles to optimized vanilla JS, no runtime overhead
3. **Size**: Smallest bundle sizes for simple applications
4. **Developer Experience**: Excellent tooling, hot reload, TypeScript support
5. **Semantic Events**: Native event handling, no synthetic event system
6. **Reactive Programming**: Built-in reactivity without complex state management

### Frontend Structure

```
src/
├── App.svelte           # Main application shell
├── components/
│   ├── TemperatureDisplay.svelte
│   ├── ControlPanel.svelte
│   ├── SchedulePicker.svelte
│   ├── StatusIndicator.svelte
│   └── HistoryChart.svelte
├── stores/
│   ├── auth.js          # Authentication state
│   ├── temperature.js   # Temperature data
│   └── schedule.js      # Scheduling state
├── services/
│   ├── api.js           # API communication
│   └── websocket.js     # Real-time updates
└── utils/
    ├── time.js          # Time/date utilities
    └── temperature.js   # Temperature calculations
```

### Core Control Logic (Moved from Backend)

#### 1. Temperature Control Algorithm
The complex Tasker "Turn Off Hot Tub At Temp" task (60+ actions) becomes clean TypeScript:

```typescript
class TemperatureController {
  async monitorHeatingCycle(targetTemp: number): Promise<HeatingResult> {
    let loopCount = 0;
    const maxLoops = 20;
    
    // Add buffer for high temps (preserves Tasker logic)
    const adjustedTarget = targetTemp > 102 ? targetTemp + 0.2 : targetTemp;
    
    while (loopCount < maxLoops) {
      const currentTemp = await this.getCurrentTemperature();
      const tempDiff = adjustedTarget - currentTemp;
      
      // Success - target reached!
      if (tempDiff <= 0) {
        await this.turnOffHeat();
        await this.sendNotification(`Hot Tub Ready ${currentTemp}°F`);
        return { status: 'success', loops: loopCount };
      }
      
      // Adaptive wait times based on temperature difference  
      const waitSeconds = 
        tempDiff > 10 ? 19 * 60 + 45 :  // 19min 45sec (far from target)
        tempDiff > 5  ? 9 * 60 + 45 :   // 9min 45sec (approaching) 
        tempDiff > 1  ? 1 * 60 + 45 :   // 1min 45sec (close)
                        15;              // 15sec (very close)
      
      loopCount++;
      await this.sleep(waitSeconds * 1000);
    }
    
    // Safety timeout reached
    await this.turnOffHeat();
    await this.logError(`Heating timeout after ${loopCount} loops`);
    return { status: 'timeout', loops: loopCount };
  }
  
  private async callAPI(endpoint: string, options: APIOptions) {
    return fetch('/api/v1/proxy', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        auth: this.userToken,
        endpoint,
        method: options.method,
        headers: options.headers,
        body: options.body
      })
    });
  }
}
```

#### 2. API Integration Services
```typescript
class HotTubService {
  async turnHeatOn() {
    return this.callIFTTT('hot-tub-heat-on');
  }
  
  async turnHeatOff() {
    return this.callIFTTT('hot-tub-heat-off');
  }
  
  async getCurrentTemperature() {
    const response = await this.callAPI('https://wirelesstag.net/ethClient.asmx/GetTagList', {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${this.wtag_key}` },
      body: { id: this.deviceId }
    });
    
    return this.parseTemperature(response);
  }
  
  private async callIFTTT(trigger: string) {
    return this.callAPI(`https://maker.ifttt.com/trigger/${trigger}/with/key/${this.webhook_key}`, {
      method: 'POST',
      headers: {},
      body: {}
    });
  }
}
```

### Key Frontend Components

#### 3. Temperature Display Component
```svelte
<!-- Replicates current Tasker UI -->
<div class="temperature-panel">
  <div class="current-temp">
    <h2>{$currentTemp}°F</h2>
    <span>Water Temperature</span>
  </div>
  <div class="target-temp">
    <input type="range" min="86" max="104" bind:value={targetTemp} />
    <span>Target: {targetTemp}°F</span>
  </div>
  <div class="ambient-temp">
    <span>{$ambientTemp}°F Outside</span>
  </div>
</div>
```

#### 4. Control Panel Component  
```svelte
<!-- Preset time buttons + manual controls -->
<div class="control-panel">
  <div class="preset-times">
    {#each presetTimes as time}
      <button on:click={() => scheduleHeat(time)}>
        {formatTime(time)}
      </button>
    {/each}
  </div>
  
  <div class="manual-controls">
    <button class="heat-on" on:click={heatOn}>Heat On</button>
    <button class="heat-off" on:click={heatOff}>Heat Off</button>
  </div>
</div>
```

#### 5. Status Indicator Component
```svelte  
<!-- Real-time heating status -->
<div class="status-indicator" class:heating={$isHeating}>
  <div class="status-text">{$heatingStatus}</div>
  <div class="time-to-target">
    {#if $timeToTarget}
      Ready in {$timeToTarget} minutes
    {/if}
  </div>
</div>
```

### Frontend Features

#### Real-time Updates
- WebSocket connection for live temperature data
- Automatic UI updates when heating status changes
- Push notifications when target temperature reached

#### Responsive Design
- Mobile-first design (replacing Android-only interface)
- Touch-friendly controls
- Works on tablets, phones, and desktop

#### Progressive Web App (PWA)
- Installable on mobile home screen
- Offline functionality for basic controls
- Background sync when connection restored

## Data Flow Architecture

### 1. Authentication Flow
```
User → Login Form → PHP Auth → JWT Token → Local Storage → API Headers
```

### 2. Temperature Monitoring Flow  
```
Sensors → WirelessTag API → PHP Proxy → WebSocket → Svelte Store → UI Update
```

### 3. Heating Control Flow
```
UI Button → Svelte Event → API Call → PHP Proxy → IFTTT Webhook → Smart Device
```

### 4. Scheduling Flow
```
Schedule UI → Form Validation → API Call → Config File → Cron/Timer → Heat Control
```

## Deployment Architecture

### Two-Part Deployment Strategy

#### Frontend: GitHub Pages (Free & Automatic)
```yaml
# .github/workflows/deploy.yml
name: Deploy to GitHub Pages

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup Node.js
        uses: actions/setup-node@v3
        with:
          node-version: '18'
          
      - name: Install dependencies
        run: npm install
        
      - name: Build Svelte app
        run: npm run build
        
      - name: Deploy to GitHub Pages
        uses: peaceiris/actions-gh-pages@v3
        with:
          github_token: ${{ secrets.GITHUB_TOKEN }}
          publish_dir: ./dist
```

**Frontend Deployment Process:**
1. `git push` to main branch
2. GitHub Actions automatically builds Svelte app
3. Deploys to `https://username.github.io/hot-tub-controller`
4. **Zero server management required**

#### Backend: One-Time PHP Hosting Setup

**Option 1: Shared PHP Hosting ($5-10/month)**
- Upload 3 PHP files via FTP once
- Set CORS origin to your GitHub Pages URL
- Never touch again

**Option 2: VPS/Cloud Server**
- Deploy PHP proxy once
- Configure SSL and domain
- Set up rate limiting

### File Structure
```
Frontend (GitHub Pages):
hot-tub-controller/
├── src/                 # Svelte source code
├── dist/                # Built files (deployed)
│   ├── index.html      
│   ├── bundle.js       
│   └── bundle.css      
└── .github/workflows/   # Auto-deployment

Backend (PHP Host):  
php-proxy/
├── index.php           # Generic proxy endpoint
├── config.json         # Authentication & CORS
└── .htaccess          # URL rewriting
```

### Deployment Benefits
- ✅ **Frontend Updates**: `git push` → live in 2 minutes
- ✅ **No Backend Changes**: PHP proxy deployed once, runs forever  
- ✅ **Free Hosting**: GitHub Pages costs $0
- ✅ **Automatic SSL**: GitHub provides HTTPS
- ✅ **CDN Distribution**: Fast loading worldwide
- ✅ **Version Control**: Every deployment tracked in Git

## Security Considerations

### 1. Token-Based Authentication Benefits

**Why This Design is Better for MVP:**

✅ **Simple to Implement**: Just random string generation (no JWT complexity)  
✅ **Easy to Share**: Tokens are human-readable and text/email friendly  
✅ **Clear Permissions**: Admin can create/revoke, users can only proxy  
✅ **Audit Trail**: Track which user made which request and when  
✅ **No Password Sharing**: Users never know the master password  
✅ **Easy Revocation**: Just set `"active": false` in JSON file  

**User Workflow:**
1. **Admin**: Uses master password to create token for new user  
2. **Token Handoff**: Admin texts/emails token like `tk_8f3a2b1c9d4e5f6g` to user  
3. **User Setup**: Pastes token into Svelte app settings once  
4. **API Access**: All subsequent requests use the token automatically  

**Token Format:**
- Prefix `tk_` for easy identification
- 16 random hex characters for sufficient security
- Human-readable (no complex base64 or JWT structure)
- Example: `tk_8f3a2b1c9d4e5f6g`

### 2. Frontend Security (New Considerations)
Since all logic now runs in the browser:

**Token Storage:**
- Store user token in browser localStorage
- No complex encryption needed (token is already random)
- Clear token on logout/session timeout
- Display first 4 chars in UI for identification (e.g., "tk_8f3a...")

**API Key Storage:**
- Store IFTTT webhook key and WirelessTag API key in browser localStorage  
- Encrypt keys using Web Crypto API before storage
- Derive encryption key from user token (stable per session)
- Clear keys on logout/session timeout

**Client-Side Validation:**
- All business logic validation happens in TypeScript
- Fail gracefully when external APIs are unavailable
- Log security events to browser console (developer visibility)

### 3. PHP Proxy Security  
**Minimal Attack Surface:**
- Only validates user tokens (simple string lookup)
- Zero knowledge of business logic or API keys
- Rate limiting by IP address and user token
- CORS headers restrict to GitHub Pages domain only

**Configuration:**
```php
// Secure headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');  
header('X-XSS-Protection: 1; mode=block');

// CORS for GitHub Pages only
$allowedOrigins = ['https://username.github.io'];
```

### 4. Deployment Security
**Frontend (GitHub Pages):**
- Automatic HTTPS via GitHub
- No server-side code execution
- Static file hosting only
- Version controlled deployment

**Backend (PHP Host):**
- Single file deployment reduces complexity
- No file uploads or dynamic content
- Input validation on proxy requests only
- No database or file system writes

## Migration Strategy

### Phase 1: Core Replacement (MVP)
- Basic temperature monitoring
- Manual heat on/off controls
- Simple scheduling (preset times)
- Authentication system

### Phase 2: Feature Parity
- Advanced scheduling logic
- Weather condition integration
- Safety shutoff mechanisms
- Push notifications

### Phase 3: Enhancements
- Historical data and analytics
- Multi-user access controls
- Mobile app (Capacitor build)
- Integration with other smart home systems

## Development Recommendations

### Backend Development Stack
```bash
# Local development
composer require slim/slim
composer require firebase/php-jwt
composer require vlucas/phpdotenv

# Production deployment
- PHP 7.4+ (available everywhere)
- No database required
- Standard Apache/Nginx
```

### Frontend Development Stack
```bash
# Create Svelte project
npx create-svelte@latest hot-tub-frontend
cd hot-tub-frontend
npm install

# Add useful packages
npm install --save-dev @tailwindcss/forms
npm install chart.js date-fns
```

### Development Workflow
1. **Local Development**: Svelte dev server + PHP built-in server
2. **Testing**: PHPUnit for backend, Jest for frontend
3. **Build Process**: Vite build + simple deployment script
4. **Version Control**: Git with feature branches

## Cost Analysis

### Development Cost (Estimated)
- PHP Proxy: 10-15 hours (much simpler now)
- Frontend UI: 40-50 hours  
- Integration/Testing: 15-20 hours
- **Total: 65-85 hours** (reduced complexity)

### Operational Cost (Annual)
- Shared hosting: $60-120/year
- Domain name: $15/year
- SSL certificate: $0 (Let's Encrypt)
- **Total: $75-135/year**

### Compared to Current System
- **Hardware**: No Android device required (-$200-500)
- **Maintenance**: Easier updates and debugging (-10 hours/year)
- **Accessibility**: Works from anywhere (+value)

## Risk Assessment

### Low Risk
- ✅ Simple architecture with proven technologies
- ✅ File-based system (no database complexity)
- ✅ Gradual migration possible (run parallel systems)

### Medium Risk  
- ⚠️ Dependency on external APIs (IFTTT, WirelessTag)
- ⚠️ Need reliable internet connection for remote access

### Mitigation Strategies
- Local network fallback mode
- API endpoint health monitoring
- Automated backup of configuration
- Graceful degradation when services unavailable

## Conclusion

This revised architecture provides a **superior** replacement for the Tasker system with true separation of concerns. The PHP backend becomes a minimal, maintainable proxy while Svelte handles all the sophisticated control logic.

**Key Benefits:**
✅ **Deploy Once Backend**: PHP proxy set up once and forgotten  
✅ **Easy Frontend Updates**: `git push` → live in 2 minutes via GitHub Pages  
✅ **Zero Hosting Costs**: GitHub Pages is free forever  
✅ **Better Debugging**: All logic visible in browser developer tools  
✅ **Modern Development**: TypeScript, hot reload, version control  
✅ **No Vendor Lock-in**: Pure web standards, works anywhere

**Development Workflow:**
```bash
# Frontend development
npm run dev          # Local development with hot reload
git push            # Automatic deployment to production

# Backend (one time only)
scp proxy.php host  # Upload once, never change
```

**Architecture Advantages:**
- **Simplicity**: Two separate, focused codebases  
- **Maintainability**: Business logic in readable TypeScript vs 60+ Tasker actions
- **Reliability**: Frontend works offline, backend has minimal failure points
- **Scalability**: Static hosting scales infinitely, proxy handles any load
- **Security**: Minimal attack surface, client-side encryption

**Recommended Next Steps:**
1. Create minimal PHP proxy (1 file, ~100 lines)
2. Build Svelte frontend with temperature control algorithm
3. Test integration with IFTTT and WirelessTag APIs  
4. Set up GitHub Pages deployment workflow
5. Deploy PHP proxy to cheap hosting provider

This architecture transforms a complex, single-device Android automation into a modern, cross-platform web application while preserving all the sophisticated control logic that makes it effective.