<?php

namespace App\Console\Command;

use App\Import\ExportService;
use App\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:export', description: 'Export media as JSON or CSV (re-importable with app:import)')]
final class ExportCommand extends Command
{
    public function __construct(private Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'json|csv', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file (default: var/exports/mmr-<date>.<format>)')
            ->addOption('in-list', null, InputOption::VALUE_NONE, 'Only items in the list (any status)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Comma-separated statuses');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format') === 'csv' ? 'csv' : 'json';
        $path = $input->getOption('output') ?: $this->services->varDir() . '/exports/mmr-' . date('Ymd-His') . ".$format";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $filters = [];
        if ($s = $input->getOption('status')) {
            $filters['statuses'] = array_map('trim', explode(',', $s));
        } elseif ($input->getOption('in-list')) {
            $filters['statuses'] = \App\Domain\MediaStatus::values();
        }

        $s = $this->services;
        $export = new ExportService($s->media(), $s->genres(), $s->tags());
        $h = fopen($path, 'w');
        $n = $format === 'csv' ? $export->writeCsv($h, $filters) : $export->writeJson($h, $filters);
        fclose($h);
        $io->success("Exported $n items to $path");
        return Command::SUCCESS;
    }
}
