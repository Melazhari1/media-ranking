<?php

namespace App\Metadata;

use App\Media\PosterStore;
use App\Repository\GenreRepository;
use App\Repository\LookupRepository;
use PDO;
use Throwable;

/**
 * Writes a MetadataResult onto a media row.
 *
 * Never touches the user's own data (status, my score, notes, progress_current, dates).
 * With $onlyMissing = true (backfill) it fills empty fields only; the poster is replaced only
 * when PosterStore reports a quality problem.
 */
final class MetadataApplier
{
    public function __construct(
        private PDO $pdo,
        private LookupRepository $lookups,
        private GenreRepository $genres,
        private PosterStore $posters,
    ) {
    }

    /** @return string[] names of the fields that changed */
    public function apply(int $mediaId, MetadataResult $m, bool $onlyMissing = true): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = ?');
        $stmt->execute([$mediaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        $set = [];
        $want = fn (string $col) => !$onlyMissing || $row[$col] === null || $row[$col] === '' ;

        if ($want('external_id') || !$onlyMissing) {
            $set['external_source'] = $m->externalSource;
            $set['external_id'] = $m->externalId;
        }
        if ($m->synopsis && $want('synopsis')) {
            $set['synopsis'] = $m->synopsis;
        }
        if ($m->backdropUrl && $want('backdrop_url')) {
            $set['backdrop_url'] = $m->backdropUrl;
        }
        if ($m->total && $want('progress_total')) {
            $set['progress_total'] = $m->total;
        }
        if ($m->unit && $want('progress_unit')) {
            $set['progress_unit'] = $m->unit;
        }
        if ($m->publicScore && (!$onlyMissing || $row['public_score'] === null)) {
            $set['public_score'] = $m->publicScore;
            $set['score_source'] = $m->scoreSource ?? 'manual';
            $set['score_mal'] = $m->publicScore; // legacy mirror
        }
        if ($m->year && (!$onlyMissing || in_array((string) $row['year'], ['', 'N/A'], true))) {
            $set['year'] = (string) $m->year;
        }
        if ($m->category && (!$onlyMissing || !$row['category_id'])) {
            $set['category_id'] = $this->lookups->categoryId($m->category);
        }
        if ($m->ageRating && (!$onlyMissing || !$row['rating_id'])) {
            $id = $this->lookups->ratingId($m->ageRating);
            if ($id) {
                $set['rating_id'] = $id;
            }
        }
        if ($m->posterUrl && (!$onlyMissing || $this->posters->qualityProblem($row['image']) !== null)) {
            try {
                $set['image'] = $this->posters->download($m->posterUrl);
            } catch (Throwable) {
                // keep the existing poster if the download fails
            }
        }
        $set['last_synced_at'] = date('Y-m-d H:i:s');

        $cols = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($set)));
        $this->pdo->prepare("UPDATE media SET $cols WHERE id = :id")->execute($set + ['id' => $mediaId]);

        if ($m->genres) {
            $has = (int) $this->pdo->query('SELECT COUNT(*) FROM media_genre WHERE media_id = ' . $mediaId)->fetchColumn();
            if (!$onlyMissing || $has === 0) {
                $this->genres->sync($mediaId, $m->genres);
                $set['genres'] = true;
            }
        }
        unset($set['last_synced_at'], $set['score_mal']);
        return array_keys($set);
    }
}
