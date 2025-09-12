<?php

declare(strict_types=1);

namespace HotTubController\Infrastructure\Persistence;

use HotTubController\Domain\Token\Token;
use HotTubController\Domain\Token\TokenRepositoryInterface;
use Psr\Log\LoggerInterface;

class JsonTokenRepository implements TokenRepositoryInterface
{
    public function __construct(
        private string $filePath,
        private LoggerInterface $logger
    ) {
        $this->ensureFileExists();
    }

    public function findByToken(string $token): ?Token
    {
        $data = $this->loadData();

        foreach ($data['tokens'] as $tokenData) {
            if ($tokenData['token'] === $token) {
                return Token::fromArray($tokenData);
            }
        }

        return null;
    }

    public function findById(string $id): ?Token
    {
        $data = $this->loadData();

        foreach ($data['tokens'] as $tokenData) {
            if ($tokenData['id'] === $id) {
                return Token::fromArray($tokenData);
            }
        }

        return null;
    }

    public function findAll(): array
    {
        $data = $this->loadData();
        $tokens = [];

        foreach ($data['tokens'] as $tokenData) {
            $tokens[] = Token::fromArray($tokenData);
        }

        return $tokens;
    }

    public function save(Token $token): void
    {
        $data = $this->loadData();
        $tokenArray = $token->toArray();

        // Find existing token and update, or add new one
        $found = false;
        foreach ($data['tokens'] as $index => $existingToken) {
            if ($existingToken['id'] === $token->getId()) {
                $data['tokens'][$index] = $tokenArray;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $data['tokens'][] = $tokenArray;
        }

        $this->saveData($data);

        $this->logger->info('Token saved', [
            'token_id' => $token->getId(),
            'token_preview' => $token->getTokenPreview(),
            'action' => $found ? 'updated' : 'created'
        ]);
    }

    public function deleteById(string $id): bool
    {
        $data = $this->loadData();
        $originalCount = count($data['tokens']);

        $data['tokens'] = array_values(array_filter(
            $data['tokens'],
            fn($token) => $token['id'] !== $id
        ));

        if (count($data['tokens']) < $originalCount) {
            $this->saveData($data);
            $this->logger->info('Token deleted', ['token_id' => $id]);
            return true;
        }

        return false;
    }

    public function isValidToken(string $token): bool
    {
        $tokenEntity = $this->findByToken($token);
        return $tokenEntity !== null && $tokenEntity->isActive();
    }

    public function updateLastUsed(string $token): void
    {
        $tokenEntity = $this->findByToken($token);
        if ($tokenEntity === null) {
            return;
        }

        $updatedToken = $tokenEntity->updateLastUsed();
        $this->save($updatedToken);
    }

    private function ensureFileExists(): void
    {
        if (!file_exists($this->filePath)) {
            $directory = dirname($this->filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $initialData = ['tokens' => []];
            file_put_contents($this->filePath, json_encode($initialData, JSON_PRETTY_PRINT));

            $this->logger->info('Token storage file created', ['path' => $this->filePath]);
        }
    }

    private function loadData(): array
    {
        if (!is_readable($this->filePath)) {
            $this->logger->error('Cannot read token file', ['path' => $this->filePath]);
            return ['tokens' => []];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            $this->logger->error('Failed to read token file', ['path' => $this->filePath]);
            return ['tokens' => []];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON in token file', [
                'path' => $this->filePath,
                'json_error' => json_last_error_msg()
            ]);
            return ['tokens' => []];
        }

        return $data ?? ['tokens' => []];
    }

    private function saveData(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($this->filePath, $json) === false) {
            $this->logger->error('Failed to write token file', ['path' => $this->filePath]);
            throw new \RuntimeException('Failed to save token data');
        }
    }
}
