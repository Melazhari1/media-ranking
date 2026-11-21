<?php

namespace App\Console\Command;

use App\Console\Batch\CsvReport;
use Closure;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:report-uncategorized',
    description: 'Report media with no category (NULL, or pointing at a deleted category). Read-only.',
)]
final class ReportUncategorizedCommand extends Command
{
    /** @param Closure(): PDO $pdo */
    public function __construct(private Closure $pdo, private string $varDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $pdo = ($this->pdo)();

        $report = new CsvReport($this->varDir, 'uncategorized', ['id', 'title', 'category_id', 'year', 'status', 'reason']);
        $stmt = $pdo->query(
            "SELECT m.id, m.title, m.category_id, m.year, m.status,
                    IF(m.category_id IS NULL, 'no category', 'category id does not exist') AS reason
             FROM media m LEFT JOIN categories c ON c.id = m.category_id
             WHERE m.deleted_at IS NULL AND c.id IS NULL
             ORDER BY m.id"
        );
        foreach ($stmt as $row) {
            $report->add(array_values($row));
        }
        $report->close();

        $unknown = (int) $pdo->query(
            "SELECT COUNT(*) FROM media m JOIN categories c ON c.id = m.category_id
             WHERE m.deleted_at IS NULL AND c.name = 'Unknown'"
        )->fetchColumn();

        $io->table(['metric', 'count'], [
            ['media with no / missing category', $report->count()],
            ["media in the 'Unknown' category", $unknown],
        ]);
        $io->writeln('Both groups are shown under "Unknown" in the Categories filter.');
        $io->writeln('Report: ' . $report->path);

        return Command::SUCCESS;
    }
}
