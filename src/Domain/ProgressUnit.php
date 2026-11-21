<?php

namespace App\Domain;

use InvalidArgumentException;

enum ProgressUnit: string
{
    case Episode = 'episode';
    case Chapter = 'chapter';
    case Volume  = 'volume';
    case Page    = 'page';

    public static function fromInput(mixed $value): ?self
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        return self::tryFrom(strtolower(trim((string) $value)))
            ?? throw new InvalidArgumentException("Unknown progress unit '$value'");
    }

    /** Sensible default unit for a category name. */
    public static function defaultFor(?string $categoryName): self
    {
        $c = strtolower(trim((string) $categoryName));
        return match (true) {
            in_array($c, ['manga', 'manhwa', 'manhua', 'web novel'], true) => self::Chapter,
            in_array($c, ['light novel', 'novel'], true)                    => self::Volume,
            $c === 'book'                                                   => self::Page,
            default                                                         => self::Episode,
        };
    }
}
