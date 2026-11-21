<?php

namespace App\Metadata;

use App\Text\TitleNormalizer;

/** Fuzzy title comparison + match confidence used by the backfill and importers. */
final class TitleMatcher
{
    /** Comparison key: sort_title without punctuation and repeated spaces. */
    public static function key(string $title): string
    {
        $t = TitleNormalizer::sortTitle($title);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** 0..1 similarity between two keys (1 = identical). */
    public static function ratio(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $percent);
        // levenshtein() is byte-based and capped at 255 chars; fine for titles.
        $lev = 1 - levenshtein(substr($a, 0, 255), substr($b, 0, 255)) / max(strlen($a), strlen($b), 1);
        return round(max(0, min(1, ($percent / 100 + $lev) / 2)), 3);
    }

    /**
     * Confidence that $candidate is the same work as a stored media row.
     * Title similarity dominates; year and category agreement adjust it.
     *
     * @param array{title:string, year:?int, category:?string} $media
     */
    public static function confidence(array $media, MetadataResult $candidate): float
    {
        $title = MetadataService::similarity($media['title'], $candidate);
        $score = $title;

        if ($media['year'] && $candidate->year) {
            $diff = abs($media['year'] - $candidate->year);
            $score += $diff === 0 ? 0.1 : ($diff === 1 ? 0.03 : -0.15);
        }
        if ($media['category'] && $candidate->category) {
            $score += strcasecmp($media['category'], $candidate->category) === 0 ? 0.05 : -0.05;
        }
        return round(max(0, min(1, $score)), 3);
    }
}
