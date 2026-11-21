<?php

namespace App\Tests\Integration;

use App\Console\Command\BackfillPublicScoresCommand;
use App\Console\Command\NormalizeTitlesCommand;
use App\Console\Command\PurgeDeletedCommand;
use App\Console\Command\ReportUncategorizedCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends DatabaseTestCase
{
    private function normalize(): CommandTester
    {
        return new CommandTester(new NormalizeTitlesCommand($this->pdoFactory(), $this->varDir));
    }

    private function seedTitles(): array
    {
        return [
            'bold'  => $this->insertRaw(['title' => '𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞']),
            'space' => $this->insertRaw(['title' => 'True Legend ']),
            'lower' => $this->insertRaw(['title' => 'the killer']),
            'sup'   => $this->insertRaw(['title' => 'C³']),
        ];
    }

    private function reports(string $prefix): array
    {
        return glob($this->varDir . "/reports/$prefix-*.csv") ?: [];
    }

    private function csvRows(string $file): array
    {
        $lines = array_map('str_getcsv', file($file, FILE_IGNORE_NEW_LINES));
        array_shift($lines);
        return $lines;
    }

    public function testDryRunWritesReportsButNoData(): void
    {
        $ids = $this->seedTitles();
        $t = $this->normalize();
        $t->execute(['--dry-run' => true]);
        $t->assertCommandIsSuccessful();

        self::assertSame('𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞', $this->fetchRow($ids['bold'])['title']);
        self::assertNull($this->fetchRow($ids['bold'])['sort_title']);
        self::assertStringContainsString('titles that would change', $t->getDisplay());

        $lower = $this->csvRows($this->reports('lowercase-titles')[0]);
        self::assertSame([(string) $ids['lower'], 'the killer'], array_slice($lower[0], 0, 2));
    }

    public function testRunNormalizesKeepsCaseAndGenuineCharacters(): void
    {
        $ids = $this->seedTitles();
        $t = $this->normalize();
        $t->execute([]);
        $t->assertCommandIsSuccessful();

        self::assertSame('The Black Phone', $this->fetchRow($ids['bold'])['title']);
        self::assertSame('black phone', $this->fetchRow($ids['bold'])['sort_title']);
        self::assertSame('True Legend', $this->fetchRow($ids['space'])['title']);
        self::assertSame('the killer', $this->fetchRow($ids['lower'])['title'], 'never auto title-cased');
        self::assertSame('killer', $this->fetchRow($ids['lower'])['sort_title']);
        self::assertSame('C³', $this->fetchRow($ids['sup'])['title']);
        self::assertSame('c3', $this->fetchRow($ids['sup'])['sort_title']);

        self::assertFileDoesNotExist($this->varDir . '/checkpoints/normalize-titles.json', 'checkpoint cleared on success');
        self::assertCount(2, $this->csvRows($this->reports('title-changes')[0]));
    }

    public function testRunIsIdempotent(): void
    {
        $this->seedTitles();
        $this->normalize()->execute([]);
        $t = $this->normalize();
        $t->execute([]);
        self::assertMatchesRegularExpression('/titles changed\s+0/', $t->getDisplay());
        self::assertMatchesRegularExpression('/sort_title values set\s+0/', $t->getDisplay());
    }

    public function testResumesFromCheckpoint(): void
    {
        $ids = $this->seedTitles();
        @mkdir($this->varDir . '/checkpoints', 0777, true);
        // Pretend a previous run stopped after the first row.
        file_put_contents($this->varDir . '/checkpoints/normalize-titles.json', json_encode(['last_id' => $ids['bold']]));

        $t = $this->normalize();
        $t->execute([]);
        self::assertStringContainsString('Resuming after id', $t->getDisplay());
        self::assertSame('𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞', $this->fetchRow($ids['bold'])['title'], 'rows before the checkpoint are skipped');
        self::assertSame('True Legend', $this->fetchRow($ids['space'])['title']);
    }

    public function testRevertRestoresOriginalTitlesButNotLaterEdits(): void
    {
        $ids = $this->seedTitles();
        $this->normalize()->execute([]);
        // User edits one of the normalized titles afterwards
        self::$pdo->prepare('UPDATE media SET title = ? WHERE id = ?')->execute(['True Legend (edited)', $ids['space']]);

        $changes = $this->reports('title-changes')[0];
        $t = $this->normalize();
        $t->execute(['--revert' => $changes]);
        $t->assertCommandIsSuccessful();

        self::assertSame('𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞', $this->fetchRow($ids['bold'])['title']);
        self::assertSame('True Legend (edited)', $this->fetchRow($ids['space'])['title']);
    }

    public function testBackfillPublicScores(): void
    {
        $scored = $this->insertRaw(['title' => 'Scored', 'score_mal' => 6.7]);
        $zero   = $this->insertRaw(['title' => 'Zero', 'score_mal' => 0]);
        $manual = $this->insertRaw(['title' => 'Manual', 'score_mal' => 5, 'public_score' => 7.4, 'score_source' => 'tmdb']);

        $t = new CommandTester(new BackfillPublicScoresCommand($this->pdoFactory(), $this->varDir));
        $t->execute(['--dry-run' => true]);
        self::assertNull($this->fetchRow($scored)['public_score'], 'dry run writes nothing');

        $t->execute(['--batch-size' => 1]);
        $t->assertCommandIsSuccessful();
        self::assertSame('6.70', $this->fetchRow($scored)['public_score']);
        self::assertSame('mal', $this->fetchRow($scored)['score_source']);
        self::assertNull($this->fetchRow($zero)['public_score']);
        self::assertSame('7.40', $this->fetchRow($manual)['public_score'], 'existing public scores never overwritten');
        self::assertSame('tmdb', $this->fetchRow($manual)['score_source']);
        self::assertEqualsWithDelta(6.7, (float) $this->fetchRow($scored)['score_mal'], 0.001, 'score_mal untouched');
    }

    public function testReportUncategorized(): void
    {
        $this->insertRaw(['title' => 'None', 'category_id' => null]);
        $this->insertRaw(['title' => 'Dangling', 'category_id' => 42]);
        $this->insertRaw(['title' => 'Fine', 'category_id' => 1]);

        $t = new CommandTester(new ReportUncategorizedCommand($this->pdoFactory(), $this->varDir));
        $t->execute([]);
        $t->assertCommandIsSuccessful();
        $rows = $this->csvRows($this->reports('uncategorized')[0]);
        self::assertSame(['None', 'Dangling'], array_column($rows, 1));
    }

    public function testPurgeDeletedOnlyRemovesOldSoftDeletes(): void
    {
        $old    = $this->insertRaw(['title' => 'Old', 'deleted_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $recent = $this->insertRaw(['title' => 'Recent', 'deleted_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $live   = $this->insertRaw(['title' => 'Live']);

        $t = new CommandTester(new PurgeDeletedCommand($this->pdoFactory()));
        $t->execute(['--dry-run' => true]);
        self::assertNotFalse(self::$pdo->query("SELECT 1 FROM media WHERE id = $old")->fetchColumn());

        // Non-interactive without --force must abort (safe default)
        $t->execute([], ['interactive' => false]);
        self::assertNotFalse(self::$pdo->query("SELECT 1 FROM media WHERE id = $old")->fetchColumn());

        $t->execute(['--force' => true]);
        self::assertFalse(self::$pdo->query("SELECT 1 FROM media WHERE id = $old")->fetchColumn());
        self::assertNotFalse(self::$pdo->query("SELECT 1 FROM media WHERE id = $recent")->fetchColumn());
        self::assertNotFalse(self::$pdo->query("SELECT 1 FROM media WHERE id = $live")->fetchColumn());
    }
}
