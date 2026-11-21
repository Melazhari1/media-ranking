<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * Allowed values of media.status. Backed by the exact strings already stored in the DB,
 * so existing rows need no conversion. NULL / '' means "not in list".
 *
 * Watch/Read pairs are the same state with a category-aware label
 * (Book, Manga, Light Novel, Novel use the "Read" wording).
 */
enum MediaStatus: string
{
    case PlanToWatch = 'Plan to Watch';
    case PlanToRead  = 'Plan to Read';
    case Watching    = 'Watching';
    case Reading     = 'Reading';
    case Watched     = 'Watched';
    case Read        = 'Read';
    case OnHold      = 'On Hold';
    case Dropped     = 'Dropped';
    case Rewatching  = 'Rewatching';

    public const READING_CATEGORIES = ['book', 'manga', 'light novel', 'novel', 'manhwa', 'manhua'];

    /** Parse request input. Empty / 'null' → null; unknown values throw. */
    public static function fromInput(mixed $value): ?self
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        if ($value instanceof self) {
            return $value;
        }
        $status = self::tryFrom((string) $value)
            ?? self::tryFromKey((string) $value);
        if ($status === null) {
            throw new InvalidArgumentException("Unknown status '$value'");
        }
        return $status;
    }

    /** Also accept machine keys like 'plan', 'watching', 'on_hold' (used by importers). */
    public static function tryFromKey(string $key): ?self
    {
        return match (strtolower(str_replace([' ', '-'], '_', trim($key)))) {
            'plan_to_watch', 'plan' => self::PlanToWatch,
            'plan_to_read'          => self::PlanToRead,
            'watching', 'current'   => self::Watching,
            'reading'               => self::Reading,
            'watched', 'completed'  => self::Watched,
            'read'                  => self::Read,
            'on_hold', 'paused'     => self::OnHold,
            'dropped'               => self::Dropped,
            'rewatching', 'rereading' => self::Rewatching,
            default                 => null,
        };
    }

    /** Group shared by the Watch/Read variants — used for colors, stats and filters. */
    public function group(): string
    {
        return match ($this) {
            self::PlanToWatch, self::PlanToRead => 'plan',
            self::Watching, self::Reading       => 'active',
            self::Watched, self::Read           => 'done',
            self::OnHold                        => 'on_hold',
            self::Dropped                       => 'dropped',
            self::Rewatching                    => 'rewatching',
        };
    }

    public function isDone(): bool
    {
        return $this->group() === 'done';
    }

    /** The equivalent status with the wording that fits the category (Watching ↔ Reading…). */
    public function forCategory(?string $categoryName): self
    {
        $reading = self::isReadingCategory($categoryName);
        return match ($this) {
            self::PlanToWatch, self::PlanToRead => $reading ? self::PlanToRead : self::PlanToWatch,
            self::Watching, self::Reading       => $reading ? self::Reading : self::Watching,
            self::Watched, self::Read           => $reading ? self::Read : self::Watched,
            default                             => $this,
        };
    }

    public static function isReadingCategory(?string $categoryName): bool
    {
        return in_array(strtolower(trim((string) $categoryName)), self::READING_CATEGORIES, true);
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
