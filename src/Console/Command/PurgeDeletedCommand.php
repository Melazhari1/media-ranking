<?php

namespace App\Console\Command;

use Closure;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-deleted',
    description: 'Permanently remove media soft-deleted more than N days ago (DESTRUCTIVE)',
)]
final class PurgeDeletedCommand extends Command
{
    /** @param Closure(): PDO $pdo */
    public function __construct(private Closure $pdo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Days since deletion', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $pdo  = ($this->pdo)();
        $days = max(0, (int) $input->getOption('older-than'));

        $cond = 'deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL ' . $days . ' DAY)';
        $rows = $pdo->query("SELECT id, title, deleted_at FROM media WHERE $cond ORDER BY deleted_at")->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $io->success("No media deleted more than $days days ago.");
            return Command::SUCCESS;
        }
        $io->table(['id', 'title', 'deleted_at'], array_slice(array_map('array_values', $rows), 0, 50));
        if (count($rows) > 50) {
            $io->writeln('… and ' . (count($rows) - 50) . ' more');
        }

        if ($input->getOption('dry-run')) {
            $io->success(count($rows) . ' media would be permanently deleted. Nothing was written.');
            return Command::SUCCESS;
        }
        if (!$input->getOption('force') && !$io->confirm('Permanently delete ' . count($rows) . ' media? This cannot be undone.', false)) {
            $io->warning('Aborted.');
            return Command::SUCCESS;
        }

        $deleted = $pdo->exec("DELETE FROM media WHERE $cond");
        $io->success("Purged $deleted media.");
        return Command::SUCCESS;
    }
}
