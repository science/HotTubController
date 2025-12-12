<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\UserRepositoryInterface;

/**
 * User repository backed by a JSON file.
 *
 * Stores users with bcrypt-hashed passwords in a simple JSON format.
 * Supports file locking for safe concurrent access.
 */
class JsonUserRepository implements UserRepositoryInterface
{
    private const VALID_ROLES = ['admin', 'user'];
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function findByUsername(string $username): ?array
    {
        $data = $this->load();

        if (!isset($data['users'][$username])) {
            return null;
        }

        $user = $data['users'][$username];
        return [
            'username' => $username,
            'password_hash' => $user['password_hash'],
            'role' => $user['role'],
            'created_at' => $user['created_at'],
        ];
    }

    public function list(): array
    {
        $data = $this->load();
        $users = [];

        foreach ($data['users'] as $username => $user) {
            $users[] = [
                'username' => $username,
                'role' => $user['role'],
                'created_at' => $user['created_at'],
            ];
        }

        return $users;
    }

    public function create(string $username, string $password, string $role = 'user'): array
    {
        $data = $this->load();

        if (isset($data['users'][$username])) {
            throw new \InvalidArgumentException("User already exists: $username");
        }

        // Validate role, default to 'user' if invalid
        if (!in_array($role, self::VALID_ROLES, true)) {
            $role = 'user';
        }

        $createdAt = date('c');
        $data['users'][$username] = [
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => $role,
            'created_at' => $createdAt,
        ];

        $this->save($data);

        return [
            'username' => $username,
            'role' => $role,
            'created_at' => $createdAt,
        ];
    }

    public function delete(string $username): void
    {
        $data = $this->load();

        if (!isset($data['users'][$username])) {
            throw new \InvalidArgumentException("User not found: $username");
        }

        unset($data['users'][$username]);
        $this->save($data);
    }

    public function updatePassword(string $username, string $newPassword): void
    {
        $data = $this->load();

        if (!isset($data['users'][$username])) {
            throw new \InvalidArgumentException("User not found: $username");
        }

        $data['users'][$username]['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->save($data);
    }

    public function verifyPassword(string $username, string $password): bool
    {
        $user = $this->findByUsername($username);

        if ($user === null) {
            return false;
        }

        return password_verify($password, $user['password_hash']);
    }

    /**
     * Load user data from file.
     */
    private function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [
                'version' => 1,
                'users' => [],
            ];
        }

        $content = file_get_contents($this->filePath);
        $data = json_decode($content, true);

        if ($data === null) {
            return [
                'version' => 1,
                'users' => [],
            ];
        }

        return $data;
    }

    /**
     * Save user data to file with atomic write and file locking.
     */
    private function save(array $data): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Atomic write: write to temp file then rename
        $tempFile = $this->filePath . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT);

        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open file for writing: $tempFile");
        }

        // Exclusive lock for writing
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        // Atomic rename
        rename($tempFile, $this->filePath);
    }
}
