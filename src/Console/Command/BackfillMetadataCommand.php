<?php

namespace App\Console\Command;

use App\Console\Batch\Checkpoint;
use App\Console\Batch\CsvReport;
use App\Metadata\MetadataResult;
use App\Metadata\MetadataService;
use App\Metadata\TitleMatcher;
use App\Services;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:backfill-metadata',
    description: 'Re-match items missing a poster / public score, or with low-quality images, against Jikan/TMDB',
)]
final class BackfillMetadataCommand extends Command
{
    public function __construct(private Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Look up matches and write reports, but change nothing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N candidates (0 = no limit)', '0')
            ->addOption('min-confidence', null, InputOption::VALUE_REQUIRED, 'Apply matches at or above this confidence (0-1)', '0.9')
            ->addOption('review-floor', null, InputOption::VALUE_REQUIRED, 'Below this confidence a candidate is not even suggested', '0.5')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Only these media ids (comma-separated)')
            ->addOption('include-matched', null, InputOption::VALUE_NONE, 'Also re-check items that already have an external id')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Ignore the saved checkpoint')
            ->addOption('apply-review', null, InputOption::VALUE_REQUIRED, 'Apply rows marked approve=yes in a metadata-review CSV')
            ->setHelp(<<<'HELP'
Candidates: items without an external id whose public score is missing, or whose poster is missing,
tiny, low-resolution or from a watermarked stock source (e.g. "alamy").

Each candidate is searched on Jikan (anime/manga categories), TMDB (TV Series, Documentary…) or both
(Movie / unknown). Matches with confidence >= --min-confidence are applied, filling ONLY empty fields
(the poster only when it has a quality problem). Your status, rating, notes and progress are never touched.

Uncertain matches go to var/reports/metadata-review-*.csv. Put "yes" in the approve column of rows you
accept, then run:  php bin/console app:backfill-metadata --apply-review=<that file>

Jikan is rate-limited to 3 req/s and 60 req/min, so ~10 000 candidates take roughly 3 hours.
The run resumes from var/checkpoints/backfill-metadata.json if interrupted.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($file = $input->getOption('apply-review')) {
            return $this->applyReview($io, $file, $dryRun);
        }

        $pdo     = $this->services->pdo();
        $meta    = $this->services->metadata();
        $posters = $this->services->posters();
        $minConf = (float) $input->getOption('min-confidence');
        $floor   = (float) $input->getOption('review-floor');
        $limit   = (int) $input->getOption('limit');

        $io->title('Backfill metadata' . ($dryRun ? ' (dry run)' : ''));
        if (!$meta->tmdbConfigured()) {
            $io->warning('TMDB_API_KEY is not set: only Jikan (anime/manga) will be searched.');
        }

        $checkpoint = new Checkpoint($this->services->varDir(), 'backfill-metadata');
        if ($input->getOption('restart')) {
            $checkpoint->clear();
        }
        $lastId = $dryRun ? 0 : $checkpoint->lastId();
        if ($lastId) {
            $io->note("Resuming after id $lastId.");
        }

        $applied = new CsvReport($this->services->varDir(), 'metadata-applied' . ($dryRun ? '-dryrun' : ''), ['id', 'title', 'reasons', 'source', 'external_id', 'matched_title', 'confidence', 'fields']);
        $review  = new CsvReport($this->services->varDir(), 'metadata-review', ['id', 'title', 'year', 'category', 'reasons', 'candidate_source', 'candidate_id', 'candidate_title', 'candidate_year', 'candidate_category', 'confidence', 'approve']);
        $nomatch = new CsvReport($this->services->varDir(), 'metadata-no-match', ['id', 'title', 'year', 'category', 'reasons', 'note']);

        $where = 'm.deleted_at IS NULL';
        $params = [];
        if (!$input->getOption('include-matched')) {
            $where .= ' AND m.external_id IS NULL';
        }
        if ($ids = $input->getOption('ids')) {
            $idList = array_values(array_filter(array_map('intval', explode(',', $ids))));
            $where .= ' AND m.id IN (' . implode(',', $idList ?: [0]) . ')';
        }
        $select = $pdo->prepare(
            "SELECT m.id, m.title, m.image, m.release_year, c.name AS category,
                    COALESCE(m.public_score, NULLIF(m.score_mal, 0)) AS public_score
             FROM media m LEFT JOIN categories c ON c.id = m.category_id
             WHERE $where AND m.id > :last ORDER BY m.id LIMIT 200"
        );

        $stats = ['scanned' => 0, 'candidates' => 0, 'applied' => 0, 'review' => 0, 'no_match' => 0, 'errors' => 0];
        while (true) {
            $select->execute(['last' => $lastId] + $params);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $stats['scanned']++;
                $lastId = (int) $row['id'];

                $reasons = [];
                if ($row['public_score'] === null) {
                    $reasons[] = 'no public score';
                }
                if ($problem = $posters->qualityProblem($row['image'])) {
                    $reasons[] = $problem;
                }
                if (!$reasons) {
                    continue;
                }
                $stats['candidates']++;
                $reasonText = implode('; ', $reasons);
                $media = ['title' => $row['title'], 'year' => $row['release_year'] ? (int) $row['release_year'] : null, 'category' => $row['category']];

                try {
                    $search = $meta->search($row['title'], MetadataService::kindForCategory($row['category']), 5);
                    $best = null;
                    $bestConf = 0.0;
                    foreach ($search['results'] as $cand) {
                        $conf = TitleMatcher::confidence($media, $cand);
                        if ($conf > $bestConf) {
                            [$best, $bestConf] = [$cand, $conf];
                        }
                    }

                    if ($best && $bestConf >= $minConf) {
                        $full = str_starts_with($best->externalSource, 'tmdb') ? ($meta->details($best->externalSource, $best->externalId) ?? $best) : $best;
                        $fields = $dryRun ? ['(dry run)'] : $this->services->applier()->apply((int) $row['id'], $full, true);
                        $applied->add([$row['id'], $row['title'], $reasonText, $full->externalSource, $full->externalId, $full->title, $bestConf, implode(' ', $fields)]);
                        $stats['applied']++;
                    } elseif ($best && $bestConf >= $floor) {
                        $review->add([$row['id'], $row['title'], $media['year'], $row['category'], $reasonText, $best->externalSource, $best->externalId, $best->title, $best->year, $best->category, $bestConf, '']);
                        $stats['review']++;
                    } else {
                        $nomatch->add([$row['id'], $row['title'], $media['year'], $row['category'], $reasonText, implode(' | ', $search['warnings']) ?: 'no candidate above ' . $floor]);
                        $stats['no_match']++;
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $nomatch->add([$row['id'], $row['title'], $media['year'], $row['category'], $reasonText, 'error: ' . $e->getMessage()]);
                }

                if (!$dryRun) {
                    $checkpoint->save($lastId);
                }
                if ($stats['candidates'] % 25 === 0) {
                    $io->writeln(sprintf('  … %d candidates (applied %d, review %d, no match %d)', $stats['candidates'], $stats['applied'], $stats['review'], $stats['no_match']));
                }
                if ($limit && $stats['candidates'] >= $limit) {
                    break 2;
                }
            }
        }

        $finished = !$limit || $stats['candidates'] < $limit;
        if (!$dryRun && $finished) {
            $checkpoint->clear();
        }
        if (!$dryRun && $stats['applied']) {
            $this->services->invalidateAggregates();
        }

        foreach ([$applied, $review, $nomatch] as $r) {
            $r->close();
        }
        $io->table(['metric', 'count'], array_map(fn ($k, $v) => [str_replace('_', ' ', $k), $v], array_keys($stats), $stats));
        $io->listing(['Applied:  ' . $applied->path, 'Review:   ' . $review->path, 'No match: ' . $nomatch->path]);
        $io->success($dryRun ? 'Dry run complete. Nothing was written.' : ($finished ? 'Done.' : "Stopped after --limit; run again to continue."));
        return Command::SUCCESS;
    }

    private function applyReview(SymfonyStyle $io, string $file, bool $dryRun): int
    {
        if (!is_readable($file)) {
            $io->error("Cannot read $file");
            return Command::FAILURE;
        }
        $h = fopen($file, 'r');
        $header = fgetcsv($h);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $col = array_flip($header);
        foreach (['id', 'candidate_source', 'candidate_id', 'approve'] as $needed) {
            if (!isset($col[$needed])) {
                $io->error("Not a metadata-review CSV (missing column $needed).");
                return Command::FAILURE;
            }
        }

        $done = $skipped = $failed = 0;
        while (($row = fgetcsv($h)) !== false) {
            $approve = strtolower(trim($row[$col['approve']] ?? ''));
            if (!in_array($approve, ['y', 'yes', '1', 'x', 'true', 'ok'], true)) {
                $skipped++;
                continue;
            }
            try {
                $m = $this->services->metadata()->details($row[$col['candidate_source']], $row[$col['candidate_id']]);
                if (!$m) {
                    $failed++;
                    continue;
                }
                if (!$dryRun) {
                    $this->services->applier()->apply((int) $row[$col['id']], $m, true);
                }
                $done++;
            } catch (Throwable $e) {
                $failed++;
                $io->writeln("  id {$row[$col['id']]}: " . $e->getMessage());
            }
        }
        if (!$dryRun && $done) {
            $this->services->invalidateAggregates();
        }
        $io->table(['metric', 'count'], [[$dryRun ? 'would apply' : 'applied', $done], ['not approved', $skipped], ['failed', $failed]]);
        return Command::SUCCESS;
    }
}
