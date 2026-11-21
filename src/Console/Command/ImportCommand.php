<?php

namespace App\Console\Command;

use App\Import\ImportService;
use App\Import\Parsers;
use App\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import', description: 'Import a MyAnimeList XML, Letterboxd CSV or MMR JSON/CSV export')]
final class ImportCommand extends Command
{
    public function __construct(private Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'File to import (.xml, .csv, .json; .gz is fine for MAL)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'auto|' . implode('|', Parsers::FORMATS), 'auto')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only (create/update/skip counts)')
            ->addOption('no-create', null, InputOption::VALUE_NONE, 'Only update items that already exist')
            ->addOption('no-update', null, InputOption::VALUE_NONE, 'Only create new items')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'Rows to list in the preview', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        if (!is_readable($file)) {
            $io->error("Cannot read $file");
            return Command::FAILURE;
        }
        $content = file_get_contents($file);
        if (str_ends_with(strtolower($file), '.gz')) {
            $content = gzdecode($content) ?: '';
            $file = substr($file, 0, -3);
        }

        $records = Parsers::parse($content, basename($file), $input->getOption('format'));
        $service = $this->service();
        $preview = $service->preview($records);

        $io->title('Import ' . basename($file) . ($input->getOption('dry-run') ? ' (dry run)' : ''));
        $io->table(array_keys($preview['summary']), [array_values($preview['summary'])]);
        $shown = array_slice($preview['rows'], 0, (int) $input->getOption('show'));
        $io->table(['line', 'title', 'year', 'action', 'reason', 'changes'], array_map(
            fn ($r) => [$r['line'], mb_strimwidth((string) $r['title'], 0, 50, '…'), $r['year'], $r['action'], $r['reason'], implode(',', $r['changes'] ?? [])],
            $shown
        ));

        if ($input->getOption('dry-run')) {
            $io->success('Dry run complete. Nothing was written.');
            return Command::SUCCESS;
        }
        $result = $service->commit($records, ['create' => !$input->getOption('no-create'), 'update' => !$input->getOption('no-update')]);
        $this->services->invalidateAggregates();
        $io->success(sprintf('Created %d, updated %d, skipped %d.', $result['created'], $result['updated'], $result['skipped']));
        return Command::SUCCESS;
    }

    private function service(): ImportService
    {
        $s = $this->services;
        return new ImportService($s->pdo(), $s->media(), $s->lookups(), $s->tags(), $s->genres(), $s->varDir());
    }
}
