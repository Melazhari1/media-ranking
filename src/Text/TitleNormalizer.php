<?php

namespace App\Text;

use Normalizer;

/**
 * Title clean-up rules shared by the API, importers and app:normalize-titles.
 *
 *  - normalize(): "safe" mode (default) applies NFC to the whole title and NFKC only to
 *    the blocks used for fake styling:
 *      U+1D400–U+1D7FF  Mathematical Alphanumeric Symbols ("𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞" → "The Black Phone")
 *      U+FF00–U+FFEF    Half-width / Full-width forms ("Ｒｅ：ＺＥＲＯ" → "Re:ZERO", "＆" → "&")
 *    then strips zero-width characters and trims whitespace. Real title characters such as
 *    "C³", "DNA²", "Ranma ½", "…" or "℃" are kept — full NFKC would turn them into "C3", "1⁄2"…
 *    Pass $fullNfkc = true for plain NFKC on everything. Case is never changed.
 *  - sortTitle(): full NFKC, lowercased, leading English article (The/A/An) removed.
 *    Never displayed; used for A-Z sorting and search (so "dna2" finds "DNA²").
 */
final class TitleNormalizer
{
    private const STYLED_BLOCKS = '/[\x{1D400}-\x{1D7FF}\x{FF00}-\x{FFEF}]+/u';
    private const ZERO_WIDTH    = '/[\x{200B}\x{2060}\x{FEFF}]/u';
    private const EDGE_SPACE    = '/^\s+|\s+$/u';

    public static function normalize(string $title, bool $fullNfkc = false): string
    {
        if (!mb_check_encoding($title, 'UTF-8')) {
            return $title; // invalid UTF-8: keep the original rather than lose data
        }

        if ($fullNfkc) {
            $t = Normalizer::normalize($title, Normalizer::FORM_KC);
        } else {
            $t = Normalizer::normalize($title, Normalizer::FORM_C);
            $t = $t === false ? false : preg_replace_callback(
                self::STYLED_BLOCKS,
                static fn (array $m) => Normalizer::normalize($m[0], Normalizer::FORM_KC) ?: $m[0],
                $t
            );
        }
        if ($t === false || $t === null) {
            return $title;
        }

        $t = preg_replace(self::ZERO_WIDTH, '', $t) ?? $t;
        return preg_replace(self::EDGE_SPACE, '', $t) ?? $t;
    }

    public static function sortTitle(string $title): string
    {
        $t = mb_strtolower(self::normalize($title, true), 'UTF-8');
        $stripped = preg_replace('/^(the|an|a)\s+(?=\S)/u', '', $t) ?? $t;
        return mb_substr($stripped === '' ? $t : $stripped, 0, 255, 'UTF-8');
    }

    /** Normalized + lowercased form of a search keyword, for matching against sort_title. */
    public static function searchKey(string $keyword): string
    {
        return mb_strtolower(self::normalize($keyword, true), 'UTF-8');
    }

    /**
     * True when a title has cased letters and all of them are lowercase ("the killer").
     * Titles without cased letters (e.g. pure Japanese) are not flagged.
     */
    public static function isAllLowercase(string $title): bool
    {
        return preg_match('/\p{Ll}/u', $title) === 1 && preg_match('/\p{Lu}|\p{Lt}/u', $title) === 0;
    }
}
