<?php

namespace App\Tests\Unit;

use MediaManager;
use PHPUnit\Framework\TestCase;

final class PublicScoreTest extends TestCase
{
    public function testZeroAndEmptyMeanNoScore(): void
    {
        foreach ([null, '', 'null', 0, '0', 0.0, -1, 'abc'] as $v) {
            self::assertNull(MediaManager::normalizePublicScore($v), var_export($v, true));
        }
    }

    public function testScoresAreRoundedAndClamped(): void
    {
        self::assertSame(6.7, MediaManager::normalizePublicScore('6.7'));
        self::assertSame(8.76, MediaManager::normalizePublicScore(8.7555));
        self::assertSame(10.0, MediaManager::normalizePublicScore(42));
    }

    public function testSourceIsValidatedAndDroppedWithoutScore(): void
    {
        self::assertSame('tmdb', MediaManager::normalizeScoreSource('TMDB', 7.4));
        self::assertSame('manual', MediaManager::normalizeScoreSource('rotten', 7.4));
        self::assertSame('manual', MediaManager::normalizeScoreSource(null, 7.4));
        self::assertNull(MediaManager::normalizeScoreSource('mal', null));
    }
}
