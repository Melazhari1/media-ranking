<?php

namespace App\Repository;

use InvalidArgumentException;
use PDO;

/** Typed key/value settings. Public profile is opt-in and off by default. */
final class SettingsRepository
{
    public const DEFAULTS = [
        'public_enabled'    => false,
        'username'          => '',
        'display_name'      => '',
        'public_show_adult' => false,
        'public_show_notes' => false,
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $out = self::DEFAULTS;
        foreach ($this->pdo->query('SELECT name, value FROM settings') as $r) {
            if (array_key_exists($r['name'], $out)) {
                $out[$r['name']] = is_bool(self::DEFAULTS[$r['name']]) ? $r['value'] === '1' : (string) $r['value'];
            }
        }
        return $out;
    }

    public function save(array $input): array
    {
        $current = $this->all();
        $new = $current;
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $new[$key] = is_bool($default) ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) : trim((string) $input[$key]);
        }
        if ($new['username'] !== '' && !preg_match('/^[A-Za-z0-9_-]{3,30}$/', $new['username'])) {
            throw new InvalidArgumentException('Username must be 3–30 letters, digits, - or _');
        }
        if ($new['public_enabled'] && $new['username'] === '') {
            throw new InvalidArgumentException('Choose a username to enable the public profile');
        }
        if (mb_strlen($new['display_name']) > 60) {
            throw new InvalidArgumentException('Display name is too long (60 max)');
        }
        $stmt = $this->pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($new as $k => $v) {
            $stmt->execute([$k, is_bool($v) ? ($v ? '1' : '0') : $v]);
        }
        return $new;
    }
}
