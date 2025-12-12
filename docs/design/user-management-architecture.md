# User Management Architecture

## Overview

Add multi-user support (4-5 users max) using a JSON file as the user store. Replace the single hardcoded admin in `.env` with a proper user repository that supports password hashing and role-based access.

## Current State

- Single admin user stored in `.env` file (plain-text password)
- JWT-based session tokens via `AuthService`
- No password hashing

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Storage format | JSON file | Zero dependencies, human-readable, simple file locking |
| Password hashing | bcrypt via `password_hash()` | PHP native, secure, includes salt |
| Roles | `admin` and `user` only | Admin can CRUD users; otherwise identical permissions |
| Self-registration | No | Admin creates users, shares credentials manually |
| Password complexity | None | Minimal security system |
| Session invalidation | Delete user only | No "logout everywhere" needed |
| Audit logging | Not yet | Can add later if needed |

## Storage Location

```
backend/
├── public/              # Web root
├── storage/             # Outside web root (not served)
│   ├── users/
│   │   └── users.json   # User database (gitignored, server-only)
│   ├── scheduled-jobs/
│   └── logs/
```

Security note: The `users.json` file has equivalent security to `.env` - both contain secrets, both are outside web root. If `.env` is leaked (JWT secret), the system is already compromised.

## File Format

**`backend/storage/users/users.json`**

```json
{
  "version": 1,
  "users": {
    "admin": {
      "password_hash": "$2y$10$...",
      "role": "admin",
      "created_at": "2025-12-11T10:00:00Z"
    },
    "steve": {
      "password_hash": "$2y$10$...",
      "role": "user",
      "created_at": "2025-12-11T10:05:00Z"
    }
  }
}
```

## Bootstrap & Deployment

### How It Works with GitHub Actions Deploy

The current deploy workflow:
1. Generates `backend/.env` from GitHub Secrets (overwritten every deploy)
2. FTPs `backend/` to server
3. `storage/users/users.json` is server-only (not in repo, not synced)

### Bootstrap Logic

```
On application startup / first auth request:

if users.json does not exist:
    # First deploy or disaster recovery
    Create users.json
    Seed admin user from AUTH_ADMIN_USERNAME/PASSWORD in .env
    Hash password with bcrypt before storing
else:
    # Normal operation
    Use users.json as source of truth
    Ignore AUTH_ADMIN_* in .env
```

### Deployment Scenarios

| Scenario | users.json | Behavior |
|----------|------------|----------|
| First deploy | Missing | Bootstrap admin from `.env` secrets |
| Normal deploy | Exists | `.env` regenerated but admin creds ignored |
| Password change | Exists | Updated in `users.json`, persists across deploys |
| Disaster recovery | Deleted | Re-bootstrap from `.env` (like first deploy) |

### Keep `.env` Admin Credentials

Keep `AUTH_ADMIN_USERNAME` and `AUTH_ADMIN_PASSWORD` in GitHub Secrets and `.env` as a "break glass" recovery mechanism. If admin is locked out, delete `users.json` on server to re-bootstrap.

## Architecture

### New Files

```
backend/src/
├── Contracts/
│   └── UserRepositoryInterface.php
├── Services/
│   └── JsonUserRepository.php
```

### Interface

```php
interface UserRepositoryInterface
{
    public function findByUsername(string $username): ?array;
    public function list(): array;
    public function create(string $username, string $password, string $role = 'user'): array;
    public function delete(string $username): void;
    public function updatePassword(string $username, string $newPassword): void;
    public function verifyPassword(string $username, string $password): bool;
}
```

### Modified AuthService

```php
// Current: plain-text comparison
if ($username !== $this->adminUsername || $password !== $this->adminPassword)

// New: repository with bcrypt verification
if (!$this->userRepository->verifyPassword($username, $password))
```

The `AuthService` constructor changes from receiving admin credentials to receiving a `UserRepositoryInterface`.

## API Endpoints

### New User Management Endpoints (Admin Only)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/users` | List all users |
| POST | `/api/users` | Create user (returns username + plain password for sharing) |
| DELETE | `/api/users/{username}` | Delete user |
| PUT | `/api/users/{username}/password` | Change password (admin or self) |

### Response for User Creation

When admin creates a user, return the credentials so they can share them:

```json
{
  "username": "steve",
  "password": "generated-or-provided-password",
  "message": "Share these credentials with the user"
}
```

Frontend can display this in a modal with a "Copy to clipboard" button for easy texting/sharing.

## Frontend Changes

### Admin User Management UI

Add a user management section (admin only) with:
- List of users with delete buttons
- "Add User" form (username, password, role dropdown)
- Success modal showing credentials with copy button
- Password change option (self or admin-for-others)

## Implementation Plan

### Phase 1: Backend User Repository
1. Create `UserRepositoryInterface`
2. Implement `JsonUserRepository` with file locking and atomic writes
3. Add bootstrap logic (seed from `.env` if no file)
4. Unit tests with temp files

### Phase 2: Integrate with Auth
1. Modify `AuthService` to use `UserRepositoryInterface`
2. Update `index.php` dependency wiring
3. Verify existing login flow works
4. Integration tests

### Phase 3: User Management API
1. Create `UserController` with CRUD endpoints
2. Add routes with admin-only middleware
3. API tests

### Phase 4: Frontend
1. Add user management page/component
2. User list with delete
3. Add user form
4. Credential display modal with clipboard copy
5. E2E tests

## File Permissions

```bash
chmod 600 backend/storage/users/users.json  # Owner read/write only
```

The deploy workflow or bootstrap code should set this.

## Testing Strategy

1. **Unit tests**: `JsonUserRepository` with temp directory
2. **Integration tests**: Auth flow with real file I/O
3. **E2E tests**: Full user management workflow in Playwright
