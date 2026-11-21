<?php

namespace App\Text;

use Transliterator;

final class Slugger
{
    private static ?Transliterator $tr = null;

    /** "Slice of Life" → "slice-of-life", "Shōnen" → "shonen", "進撃" → "jin-ji". */
    public static function slug(string $text, int $max = 64): string
    {
        self::$tr ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $t = self::$tr ? self::$tr->transliterate(TitleNormalizer::normalize($text, true)) : strtolower($text);
        $t = trim(preg_replace('/[^a-z0-9]+/', '-', (string) $t) ?? '', '-');
        $t = substr($t, 0, $max);
        return rtrim($t, '-') ?: substr(sha1($text), 0, 12);
    }
}
