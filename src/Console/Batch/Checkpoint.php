<?php

namespace App\Console\Batch;

/**
 * Persists the last processed id of a batched command in var/checkpoints/<name>.json
 * so an interrupted run can resume where it stopped.
 */
final class Checkpoint
{
    private string $file;

    public function __construct(string $varDir, string $name)
    {
        $dir = $varDir . '/checkpoints';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->file = $dir . '/' . $name . '.json';
    }

    public function lastId(): int
    {
        if (!is_file($this->file)) {
            return 0;
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        return (int) ($data['last_id'] ?? 0);
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    public function save(int $lastId, array $extra = []): void
    {
        file_put_contents($this->file, json_encode(['last_id' => $lastId, 'updated_at' => date('c')] + $extra));
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }
}
