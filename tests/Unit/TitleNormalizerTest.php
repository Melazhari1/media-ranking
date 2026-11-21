<?php

namespace App\Tests\Unit;

use App\Text\TitleNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TitleNormalizerTest extends TestCase
{
    public static function normalizeCases(): array
    {
        return [
            'math bold'            => ['𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞', 'The Black Phone'],
            'math italic'          => ['𝘏𝘦𝘭𝘭𝘰', 'Hello'],
            'full-width'           => ['Ｒｅ：ＺＥＲＯ', 'Re:ZERO'],
            'full-width ampersand' => ['Miyu ＆ Kana', 'Miyu & Kana'],
            'trailing space'       => ['True Legend ', 'True Legend'],
            'nbsp + ideographic'   => ["\u{00A0}\u{3000}Title\u{00A0}", 'Title'],
            'zero-width'           => ["\u{200B}Itsudemo\u{200B}", 'Itsudemo'],
            'lowercase untouched'  => ['the killer', 'the killer'],
            'superscript kept'     => ['C³', 'C³'],
            'squared kept'         => ['DNA²', 'DNA²'],
            'fraction kept'        => ['Ranma ½', 'Ranma ½'],
            'ellipsis kept'        => ['We Are…', 'We Are…'],
            'japanese kept'        => ['進撃の巨人', '進撃の巨人'],
            'nfc composes'         => ["Pok\u{0065}\u{0301}mon", "Pok\u{00E9}mon"],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(string $in, string $expected): void
    {
        self::assertSame($expected, TitleNormalizer::normalize($in));
    }

    public function testFullNfkcFlattensEverything(): void
    {
        self::assertSame('C3', TitleNormalizer::normalize('C³', true));
        self::assertSame('Ranma 1⁄2', TitleNormalizer::normalize('Ranma ½', true));
    }

    public function testInvalidUtf8IsReturnedUnchanged(): void
    {
        $bad = "Bad \xC3\x28 title";
        self::assertSame($bad, TitleNormalizer::normalize($bad));
    }

    public static function sortCases(): array
    {
        return [
            ['The Black Phone', 'black phone'],
            ['𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞', 'black phone'],
            ['A Silent Voice', 'silent voice'],
            ['An Inspector Calls', 'inspector calls'],
            ['Theater of Blood', 'theater of blood'],   // "The" only as a whole word
            ['Akira', 'akira'],
            ['A', 'a'],                                   // never empty
            ['The', 'the'],
            ['DNA²', 'dna2'],                             // searchable as dna2
        ];
    }

    #[DataProvider('sortCases')]
    public function testSortTitle(string $in, string $expected): void
    {
        self::assertSame($expected, TitleNormalizer::sortTitle($in));
    }

    public function testIsAllLowercase(): void
    {
        self::assertTrue(TitleNormalizer::isAllLowercase('the killer'));
        self::assertTrue(TitleNormalizer::isAllLowercase('11eyes'));
        self::assertFalse(TitleNormalizer::isAllLowercase('The Killer'));
        self::assertFalse(TitleNormalizer::isAllLowercase('進撃の巨人'));
        self::assertFalse(TitleNormalizer::isAllLowercase('2001'));
    }
}
