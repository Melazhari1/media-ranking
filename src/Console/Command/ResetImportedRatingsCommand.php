<?php

namespace App\Console\Command;

use App\Console\Batch\CsvReport;
use Closure;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The legacy MAL importer copied the MAL score into "My Rating" (media.score).
 * This resets those copies to 0 for items NOT in the list (status NULL/''), writing every
 * old value to a backup CSV first. Items in the list are only reported, never changed.
 */
#[AsCommand(
    name: 'app:reset-imported-ratings',
    description: 'Reset "My Rating" values that were copied from the MAL score on items not in the list (reversible)',
)]
final class ResetImportedRatingsCommand extends Command
{
    private const COPY_CONDITION = 'score > 0 AND ABS(score - score_mal) < 0.001';
    private const NOT_IN_LIST    = "(status IS NULL OR status = '')";

    /** @param Closure(): PDO $pdo */
    public function __construct(private Closure $pdo, private string $varDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count and write the reports')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch', '1000')
            ->addOption('revert', null, InputOption::VALUE_REQUIRED, 'Restore scores from a rating-reset backup CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $pdo    = ($this->pdo)();
        $dryRun = (bool) $input->getOption('dry-run');

        if ($file = $input->getOption('revert')) {
            return $this->revert($io, $pdo, $file, $dryRun);
        }

        $io->title('Reset imported ratings' . ($dryRun ? ' (dry run)' : ''));

        // Report in-list items with a copied-looking score: never modified, for manual review.
        $inList = new CsvReport($this->varDir, 'ratings-equal-mal-in-list', ['id', 'title', 'status', 'my_score', 'mal_score']);
        foreach ($pdo->query('SELECT id, title, status, score, score_mal FROM media WHERE ' . self::COPY_CONDITION . ' AND NOT ' . self::NOT_IN_LIST . ' ORDER BY id') as $r) {
            $inList->add(array_values($r));
        }
        $inList->close();

        $backup = new CsvReport($this->varDir, 'rating-reset-backup' . ($dryRun ? '-dryrun' : ''), ['id', 'old_score']);
        $batch  = max(1, (int) $input->getOption('batch-size'));
        $select = $pdo->prepare('SELECT id, score FROM media WHERE id > :last AND ' . self::COPY_CONDITION . ' AND ' . self::NOT_IN_LIST . " ORDER BY id LIMIT $batch");
        $update = $pdo->prepare('UPDATE media SET score = 0 WHERE id = :id AND ' . self::COPY_CONDITION . ' AND ' . self::NOT_IN_LIST);

        $last = 0;
        $count = 0;
        while (true) {
            $select->execute(['last' => $last]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            if (!$dryRun) {
                $pdo->beginTransaction();
            }
            foreach ($rows as $r) {
                $backup->add([$r['id'], $r['score']]); // written before the update
                if (!$dryRun) {
                    $update->execute(['id' => $r['id']]);
                }
                $count++;
                $last = (int) $r['id'];
            }
            if (!$dryRun) {
                $pdo->commit();
            }
        }
        $backup->close();

        $io->table(['metric', 'count'], [
            [$dryRun ? 'ratings that would be reset (not in list)' : 'ratings reset (not in list)', $count],
            ['in-list items with rating = MAL score (reported only)', $inList->count()],
        ]);
        $io->listing(['Backup: ' . $backup->path, 'In-list report: ' . $inList->path]);
        $io->success($dryRun ? 'Dry run complete. Nothing was written.' : 'Done. Undo with: php bin/console app:reset-imported-ratings --revert=' . $backup->path);
        return Command::SUCCESS;
    }

    private function revert(SymfonyStyle $io, PDO $pdo, string $file, bool $dryRun): int
    {
        if (!is_readable($file)) {
            $io->error("Cannot read $file");
            return Command::FAILURE;
        }
        $h = fopen($file, 'r');
        $header = fgetcsv($h);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? ''));
        if ($header !== ['id', 'old_score']) {
            $io->error('Not a rating-reset backup CSV.');
            return Command::FAILURE;
        }
        // Only restore rows still at 0 — never overwrite a rating set since.
        $update = $pdo->prepare('UPDATE media SET score = :score WHERE id = :id AND score = 0');
        $n = 0;
        if (!$dryRun) {
            $pdo->beginTransaction();
        }
        while (($row = fgetcsv($h)) !== false) {
            if (count($row) < 2) {
                continue;
            }
            if (!$dryRun) {
                $update->execute(['score' => (float) $row[1], 'id' => (int) $row[0]]);
                $n += $update->rowCount();
            } else {
                $n++;
            }
        }
        if (!$dryRun) {
            $pdo->commit();
        }
        $io->success(($dryRun ? 'Would restore up to ' : 'Restored ') . "$n ratings.");
        return Command::SUCCESS;
    }
}
