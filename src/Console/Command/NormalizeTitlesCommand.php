<?php

namespace App\Console\Command;

use App\Console\Batch\Checkpoint;
use App\Console\Batch\CsvReport;
use App\Text\TitleNormalizer;
use Closure;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:normalize-titles',
    description: 'NFKC-normalize + trim titles, fill sort_title, report all-lowercase titles',
)]
final class NormalizeTitlesCommand extends Command
{
    private const MAX_TITLE = 255;

    /** @param Closure(): PDO $pdo */
    public function __construct(private Closure $pdo, private string $varDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change; write reports but no DB updates')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch', '500')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Ignore the saved checkpoint and start from the first id')
            ->addOption('revert', null, InputOption::VALUE_REQUIRED, 'Undo a previous run using its title-changes CSV')
            ->addOption('full-nfkc', null, InputOption::VALUE_NONE, 'Apply plain NFKC to whole titles (turns "C³" into "C3", "½" into "1⁄2" — not recommended)')
            ->setHelp(<<<'HELP'
By default only fake-styled characters are normalized (Unicode bold/italic letters,
full-width forms) plus zero-width characters and surrounding whitespace; genuine
characters like "C³", "DNA²", "½" or "…" are kept. --full-nfkc applies plain NFKC.

Every run writes to var/reports/:
  title-changes-*.csv     id, old_title, new_title  (use with --revert to undo)
  lowercase-titles-*.csv  all-lowercase titles to review by hand (never auto title-cased)
  title-skipped-*.csv     titles that could not be normalized safely (left untouched)

An interrupted run resumes from var/checkpoints/normalize-titles.json.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $pdo    = ($this->pdo)();
        $dryRun = (bool) $input->getOption('dry-run');
        $full   = (bool) $input->getOption('full-nfkc');

        if ($revert = $input->getOption('revert')) {
            return $this->revert($io, $pdo, $revert, $dryRun);
        }

        $batchSize  = max(1, (int) $input->getOption('batch-size'));
        $checkpoint = new Checkpoint($this->varDir, 'normalize-titles');
        if ($input->getOption('restart')) {
            $checkpoint->clear();
        }
        $lastId = $dryRun ? 0 : $checkpoint->lastId();

        $io->title('Normalize titles' . ($full ? ' [full NFKC]' : '') . ($dryRun ? ' (dry run — no changes written)' : ''));
        if ($lastId > 0) {
            $io->note("Resuming after id $lastId (use --restart to start over).");
        }

        $changes   = new CsvReport($this->varDir, 'title-changes' . ($dryRun ? '-dryrun' : ''), ['id', 'old_title', 'new_title']);
        $lowercase = new CsvReport($this->varDir, 'lowercase-titles', ['id', 'title', 'category', 'year', 'suggested_title_case']);
        $skipped   = new CsvReport($this->varDir, 'title-skipped', ['id', 'title', 'reason']);

        $select = $pdo->prepare(
            'SELECT m.id, m.title, m.sort_title, m.year, c.name AS category
             FROM media m LEFT JOIN categories c ON c.id = m.category_id
             WHERE m.id > :last ORDER BY m.id LIMIT ' . $batchSize
        );
        $update = $pdo->prepare('UPDATE media SET title = :title, sort_title = :sort WHERE id = :id');

        $stats   = ['scanned' => 0, 'title_changed' => 0, 'sort_title_set' => 0];
        $samples = [];

        while (true) {
            $select->execute(['last' => $lastId]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            if (!$dryRun) {
                $pdo->beginTransaction();
            }
            foreach ($rows as $row) {
                $stats['scanned']++;
                $id       = (int) $row['id'];
                $oldTitle = (string) $row['title'];
                $newTitle = TitleNormalizer::normalize($oldTitle, $full);

                if ($newTitle === '') {
                    $skipped->add([$id, $oldTitle, 'would become empty']);
                    $newTitle = $oldTitle;
                } elseif (mb_strlen($newTitle, 'UTF-8') > self::MAX_TITLE) {
                    $skipped->add([$id, $oldTitle, 'longer than ' . self::MAX_TITLE . ' chars after NFKC']);
                    $newTitle = $oldTitle;
                }

                $newSort      = TitleNormalizer::sortTitle($newTitle);
                $titleChanged = $newTitle !== $oldTitle;
                $sortChanged  = $newSort !== $row['sort_title'];

                if ($titleChanged) {
                    $stats['title_changed']++;
                    $changes->add([$id, $oldTitle, $newTitle]);
                    if (count($samples) < 15) {
                        $samples[] = [$id, $oldTitle, $newTitle];
                    }
                }
                if ($sortChanged) {
                    $stats['sort_title_set']++;
                }
                if (TitleNormalizer::isAllLowercase($newTitle)) {
                    $lowercase->add([$id, $newTitle, $row['category'] ?? '', $row['year'] ?? '', mb_convert_case($newTitle, MB_CASE_TITLE, 'UTF-8')]);
                }

                if (!$dryRun && ($titleChanged || $sortChanged)) {
                    $update->execute(['title' => $newTitle, 'sort' => $newSort, 'id' => $id]);
                }
                $lastId = $id;
            }
            if (!$dryRun) {
                $pdo->commit();
                $checkpoint->save($lastId);
            }
            $io->writeln(sprintf('  … processed up to id %d (%d rows)', $lastId, $stats['scanned']), OutputInterface::VERBOSITY_VERBOSE);
        }

        if (!$dryRun) {
            $checkpoint->clear();
        }

        if ($samples) {
            $io->section('Sample title changes');
            $io->table(['id', 'old', 'new'], $samples);
        }

        $io->table(['metric', 'count'], [
            ['rows scanned', $stats['scanned']],
            [($dryRun ? 'titles that would change' : 'titles changed'), $stats['title_changed']],
            [($dryRun ? 'sort_title values that would be set' : 'sort_title values set'), $stats['sort_title_set']],
            ['all-lowercase titles (for review)', $lowercase->count()],
            ['skipped (left untouched)', $skipped->count()],
        ]);

        foreach ([$changes, $lowercase, $skipped] as $report) {
            $report->close();
        }
        $io->listing([
            'Changes:   ' . $changes->path,
            'Lowercase: ' . $lowercase->path,
            'Skipped:   ' . $skipped->path,
        ]);

        $dryRun ? $io->success('Dry run complete. Nothing was written to the database.')
                : $io->success('Done. Undo with: php bin/console app:normalize-titles --revert=' . $changes->path);

        return Command::SUCCESS;
    }

    private function revert(SymfonyStyle $io, PDO $pdo, string $file, bool $dryRun): int
    {
        if (!is_readable($file)) {
            $io->error("Cannot read $file");
            return Command::FAILURE;
        }
        $io->title('Revert title changes from ' . basename($file) . ($dryRun ? ' (dry run)' : ''));

        $h = fopen($file, 'r');
        $header = fgetcsv($h);
        if ($header && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        if ($header !== ['id', 'old_title', 'new_title']) {
            $io->error('Not a title-changes CSV (expected header id,old_title,new_title).');
            return Command::FAILURE;
        }

        // Only revert rows whose title is still what this run set — never clobber later edits.
        $update = $pdo->prepare('UPDATE media SET title = :old, sort_title = :sort WHERE id = :id AND title COLLATE utf8mb4_bin = :new');
        $check  = $pdo->prepare('SELECT COUNT(*) FROM media WHERE id = :id AND title COLLATE utf8mb4_bin = :new');
        $reverted = $notMatching = 0;

        if (!$dryRun) {
            $pdo->beginTransaction();
        }
        while (($row = fgetcsv($h)) !== false) {
            if (count($row) < 3) {
                continue;
            }
            [$id, $old, $new] = $row;
            if ($dryRun) {
                $check->execute(['id' => (int) $id, 'new' => $new]);
                $check->fetchColumn() ? $reverted++ : $notMatching++;
                continue;
            }
            $update->execute(['old' => $old, 'sort' => TitleNormalizer::sortTitle($old), 'id' => (int) $id, 'new' => $new]);
            $update->rowCount() ? $reverted++ : $notMatching++;
        }
        if (!$dryRun) {
            $pdo->commit();
        }
        fclose($h);

        $io->table(['metric', 'count'], [
            [$dryRun ? 'would revert' : 'reverted', $reverted],
            ['skipped (title edited since, or row gone)', $notMatching],
        ]);
        $io->success($dryRun ? 'Dry run complete.' : 'Revert complete.');
        return Command::SUCCESS;
    }
}
