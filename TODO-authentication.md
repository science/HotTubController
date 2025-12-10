# Authentication Implementation Plan

## Research Summary

### Recommended Solution: firebase/php-jwt

**Why this library:**
- **Lightweight**: Single-purpose JWT library with no framework dependencies
- **Actively maintained**: Maintained by Google/Firebase team, 294+ commits, conforms to RFC 7519
- **Industry standard**: Used widely in production PHP applications
- **Simple API**: Easy encode/decode with clear exception handling
- **Perfect fit**: Works great with vanilla PHP (no Laravel/Symfony required)

**Installation**: `composer require firebase/php-jwt`

### Token Storage Strategy: httpOnly Cookies

**Why httpOnly cookies over localStorage:**
- Protected from XSS attacks (JavaScript cannot read the token)
- SvelteKit sets `httpOnly: true` and `secure: true` by default
- Automatically sent with requests (no manual header management)
- Industry best practice for SPA authentication

### Architecture Overview

```
┌─────────────────┐         ┌─────────────────┐
│   SvelteKit     │  JWT    │   PHP Backend   │
│   Frontend      │◄────────│   API           │
│                 │ Cookie  │                 │
└────────┬────────┘         └────────┬────────┘
         │                           │
         │                           │
    ┌────▼────┐                ┌─────▼─────┐
    │ handle  │                │ Middleware│
    │ hook    │                │ Auth      │
    └─────────┘                └───────────┘
```

---

## Implementation TODO

### Phase 1: Backend Authentication (PHP)

#### 1.1 Add firebase/php-jwt dependency
```bash
cd backend && composer require firebase/php-jwt
```

#### 1.2 Update .env configuration files
Add to all env config files:
```
# Authentication - Bootstrap Admin User
AUTH_ADMIN_USERNAME=admin
AUTH_ADMIN_PASSWORD=password
JWT_SECRET=development-secret-change-in-production
JWT_EXPIRY_HOURS=24
```

**Note**: Production .env.production.example should use placeholder values and strong secret.

#### 1.3 Create AuthService (TDD)
File: `backend/src/Services/AuthService.php`

Responsibilities:
- Validate username/password against env config
- Generate JWT tokens with configurable expiry
- Decode/validate JWT tokens
- Return user claims from valid tokens

**Test file**: `backend/tests/Services/AuthServiceTest.php`

TDD sequence:
1. RED: Test that valid credentials return a JWT string
2. GREEN: Implement credential validation and token generation
3. RED: Test that invalid credentials throw exception
4. GREEN: Implement validation failure path
5. RED: Test that valid JWT decodes to correct claims
6. GREEN: Implement token decoding
7. RED: Test that expired/invalid JWT throws exception
8. GREEN: Implement token validation with proper error handling

#### 1.4 Create AuthController (TDD)
File: `backend/src/Controllers/AuthController.php`

Endpoints:
- `POST /api/auth/login` - Accept username/password, return JWT in httpOnly cookie
- `POST /api/auth/logout` - Clear the auth cookie
- `GET /api/auth/me` - Return current user info (validates token)

**Test file**: `backend/tests/Controllers/AuthControllerTest.php`

#### 1.5 Create AuthMiddleware (TDD)
File: `backend/src/Middleware/AuthMiddleware.php`

Responsibilities:
- Extract JWT from cookie or Authorization header
- Validate token and attach user to request context
- Return 401 for missing/invalid tokens

**Test file**: `backend/tests/Middleware/AuthMiddlewareTest.php`

#### 1.6 Create TestAuthHelper
File: `backend/tests/Helpers/TestAuthHelper.php`

Purpose: Share authentication between test suite and server
- Load credentials from `config/env.testing`
- Generate valid JWT tokens for authenticated test requests
- Provide helper methods: `getValidToken()`, `getAdminCredentials()`

This ensures tests always use the same credentials as the running server.

#### 1.7 Protect Equipment Endpoints
Update `public/index.php`:
- Add routes for auth endpoints
- Wrap equipment endpoints with AuthMiddleware
- Health endpoint remains public

### Phase 2: Frontend Authentication (SvelteKit)

#### 2.1 Create auth store
File: `frontend/src/lib/stores/auth.ts`

Using Svelte 5 runes:
- `$state` for current user
- `$derived` for isAuthenticated
- Login/logout functions that call API

#### 2.2 Create login page
File: `frontend/src/routes/login/+page.svelte`

Simple form:
- Username input
- Password input
- Submit button
- Error display

#### 2.3 Create server-side auth handling
Files:
- `frontend/src/hooks.server.ts` - Handle hook for auth verification
- `frontend/src/routes/api/auth/+server.ts` - Proxy auth requests to PHP backend

The handle hook will:
- Check for auth cookie on each request
- Verify token validity
- Redirect unauthenticated users from protected routes to /login

#### 2.4 Protect routes
- Main control page requires authentication
- Login page accessible without auth
- Redirect logic in handle hook or layout load functions

#### 2.5 Add logout functionality
- Button in UI header
- Calls logout endpoint
- Clears cookie and redirects to login

### Phase 3: Integration Testing

#### 3.1 Backend integration tests
- Full login flow test
- Protected endpoint access with valid token
- Protected endpoint rejection without token
- Token expiry handling

#### 3.2 Frontend E2E tests (if Playwright configured)
- Login form submission
- Redirect to protected page after login
- Logout flow
- Direct URL access to protected page redirects to login

---

## Security Considerations

1. **JWT Secret**: Must be strong random string in production (32+ characters)
2. **HTTPS Only**: Cookies should only transmit over HTTPS in production
3. **Token Expiry**: 24 hours is reasonable for MVP, consider refresh tokens later
4. **Password Hashing**: For MVP bootstrap user, plain comparison is acceptable. When adding user management later, use `password_hash()` / `password_verify()`
5. **CORS**: Update CORS headers to be more restrictive in production

## Future Enhancements (Not MVP)

- User management (add/remove users)
- Role-based permissions
- Password hashing with bcrypt
- Token refresh mechanism
- Remember me functionality
- Rate limiting on login endpoint

---

## Sources

- [Firebase PHP-JWT GitHub](https://github.com/firebase/php-jwt) - Official library
- [SvelteKit Auth Docs](https://svelte.dev/docs/kit/auth) - Official guidance
- [JWT Best Practices](https://www.sitepoint.com/php-authorization-jwt-json-web-tokens/) - SitePoint guide
- [httpOnly Cookies in SvelteKit](https://www.okupter.com/blog/handling-auth-with-jwt-in-sveltekit) - Implementation guide
- [SvelteKit Cookies Changes](https://dev.to/theether0/sveltekit-changes-session-and-cookies-enb) - Cookie handling
- [REST API Auth Methods Compared](https://www.knowi.com/blog/4-ways-of-rest-api-authentication-methods/) - JWT vs alternatives
