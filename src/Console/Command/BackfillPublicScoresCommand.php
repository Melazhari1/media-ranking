<?php

namespace App\Console\Command;

use App\Console\Batch\Checkpoint;
use Closure;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-public-scores',
    description: 'Copy score_mal into public_score (score_source=mal). A score of 0 becomes NULL ("—").',
)]
final class BackfillPublicScoresCommand extends Command
{
    /** @param Closure(): PDO $pdo */
    public function __construct(private Closure $pdo, private string $varDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would change')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Ids per batch', '1000')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Ignore the saved checkpoint')
            ->setHelp('Only rows whose public_score is still NULL are touched, so re-running is safe and '
                . 'never overwrites a score you set by hand. score_mal is left unchanged.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $pdo    = ($this->pdo)();
        $dryRun = (bool) $input->getOption('dry-run');
        $batch  = max(1, (int) $input->getOption('batch-size'));

        $where = 'public_score IS NULL AND score_mal IS NOT NULL AND score_mal > 0';

        $total = (int) $pdo->query("SELECT COUNT(*) FROM media WHERE $where")->fetchColumn();
        $zero  = (int) $pdo->query('SELECT COUNT(*) FROM media WHERE public_score IS NULL AND (score_mal IS NULL OR score_mal <= 0)')->fetchColumn();

        $io->title('Backfill public scores' . ($dryRun ? ' (dry run)' : ''));
        $io->table(['metric', 'count'], [
            [$dryRun ? 'rows that would get public_score' : 'rows to update', $total],
            ['rows with score 0 / none (stay NULL, shown as "—")', $zero],
        ]);

        if ($dryRun || $total === 0) {
            $io->success($dryRun ? 'Dry run complete. Nothing was written.' : 'Nothing to do.');
            return Command::SUCCESS;
        }

        $checkpoint = new Checkpoint($this->varDir, 'backfill-public-scores');
        if ($input->getOption('restart')) {
            $checkpoint->clear();
        }
        $lastId = $checkpoint->lastId();
        $maxId  = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM media')->fetchColumn();

        $update = $pdo->prepare(
            "UPDATE media SET public_score = ROUND(score_mal, 2), score_source = 'mal'
             WHERE id > :from AND id <= :to AND $where"
        );

        $io->progressStart($total);
        $done = 0;
        while ($lastId < $maxId) {
            $to = $lastId + $batch;
            $update->execute(['from' => $lastId, 'to' => $to]);
            $done += $update->rowCount();
            $io->progressAdvance($update->rowCount());
            $lastId = $to;
            $checkpoint->save($lastId);
        }
        $io->progressFinish();
        $checkpoint->clear();

        $io->success("Updated $done rows.");
        return Command::SUCCESS;
    }
}
