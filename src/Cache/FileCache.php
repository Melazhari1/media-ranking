<?php

namespace App\Cache;

/** Tiny JSON file cache under var/cache/<namespace>/ with per-entry TTL. */
final class FileCache
{
    private string $dir;

    public function __construct(string $varDir, string $namespace)
    {
        $this->dir = rtrim($varDir, '/\\') . '/cache/' . preg_replace('/[^a-z0-9_-]/i', '', $namespace);
    }

    /**
     * @template T
     * @param callable():T $compute
     * @return T
     */
    public function remember(string $key, int $ttl, callable $compute)
    {
        $hit = $this->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $value = $compute();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function get(string $key)
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || ($data['expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $data['value'];
    }

    public function set(string $key, $value, int $ttl): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $tmp = $this->path($key) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode(['expires' => time() + $ttl, 'value' => $value]));
        @rename($tmp, $this->path($key));
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    public function clear(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.json';
    }
}
