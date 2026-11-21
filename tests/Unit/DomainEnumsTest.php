<?php

namespace App\Tests\Unit;

use App\Domain\MediaStatus;
use App\Domain\ProgressUnit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DomainEnumsTest extends TestCase
{
    public function testExistingStoredValuesAreValid(): void
    {
        foreach (['Plan to Watch', 'Plan to Read', 'Watched', 'Read'] as $legacy) {
            self::assertSame($legacy, MediaStatus::fromInput($legacy)->value);
        }
    }

    public function testNewStatuses(): void
    {
        foreach (['Watching', 'Reading', 'On Hold', 'Dropped', 'Rewatching'] as $v) {
            self::assertSame($v, MediaStatus::fromInput($v)->value);
        }
    }

    public function testEmptyMeansNotInList(): void
    {
        self::assertNull(MediaStatus::fromInput(null));
        self::assertNull(MediaStatus::fromInput(''));
        self::assertNull(MediaStatus::fromInput('null'));
    }

    public function testMachineKeys(): void
    {
        self::assertSame(MediaStatus::OnHold, MediaStatus::fromInput('on_hold'));
        self::assertSame(MediaStatus::Watched, MediaStatus::fromInput('completed'));
        self::assertSame(MediaStatus::Dropped, MediaStatus::fromInput('dropped'));
    }

    public function testUnknownStatusThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MediaStatus::fromInput('Binge-watching');
    }

    public function testGroupsAndCategoryWording(): void
    {
        self::assertSame('active', MediaStatus::Reading->group());
        self::assertSame('active', MediaStatus::Watching->group());
        self::assertTrue(MediaStatus::Read->isDone());
        self::assertSame(MediaStatus::Reading, MediaStatus::Watching->forCategory('Manga'));
        self::assertSame(MediaStatus::Watched, MediaStatus::Read->forCategory('Movie'));
        self::assertSame(MediaStatus::PlanToRead, MediaStatus::PlanToWatch->forCategory('Book'));
        self::assertSame(MediaStatus::OnHold, MediaStatus::OnHold->forCategory('Book'));
    }

    public function testProgressUnit(): void
    {
        self::assertSame(ProgressUnit::Chapter, ProgressUnit::fromInput('Chapter'));
        self::assertNull(ProgressUnit::fromInput(''));
        self::assertSame(ProgressUnit::Chapter, ProgressUnit::defaultFor('Manga'));
        self::assertSame(ProgressUnit::Volume, ProgressUnit::defaultFor('Light Novel'));
        self::assertSame(ProgressUnit::Page, ProgressUnit::defaultFor('Book'));
        self::assertSame(ProgressUnit::Episode, ProgressUnit::defaultFor('TV'));

        $this->expectException(InvalidArgumentException::class);
        ProgressUnit::fromInput('minutes');
    }
}
