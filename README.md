# MMR — My Media Ranking

Personal media watchlist: Vue 3 (CDN, no build step) frontend, plain-PHP JSON API (`api.php`), MySQL.

## Requirements

- PHP ≥ 8.1 with `intl`, `mbstring`, `pdo_mysql`
- MySQL 8+
- Composer (runtime + tests). Node ≥ 20 only for lint/tests — the site needs no build.

## Setup

```bash
composer install            # on the server: composer install --no-dev --optimize-autoloader
cp .env.example .env        # optional; config.php still works for DB credentials
php bin/console migrations:migrate
```

Bump the `?v=` query string in `index.html` / `app.js` imports on each deploy so browsers fetch new files.

## Database migrations (Doctrine Migrations)

Every schema change is a reversible migration in `migrations/`.

| Command | What it does |
|---|---|
| `php bin/console migrations:status` | Show current / pending versions |
| `php bin/console migrations:migrate` | Apply all pending migrations |
| `php bin/console migrations:migrate prev` | Roll back the last migration |
| `php bin/console migrations:execute --down 'App\Migrations\VersionXXXX'` | Roll back one specific migration |
| `php bin/console migrations:generate` | Create an empty migration class |

**Always back up first:** `mysqldump --single-transaction DBNAME > backup.sql`

| Version | Change | Rollback notes |
|---|---|---|
| `Version20261121000001` | `media.sort_title`, `public_score`, `score_source`, `deleted_at` (+ indexes) | Lossless for titles/scores (`score_mal` is kept in sync). Soft-deleted items reappear. |
| `Version20261121000002` | Progress (`progress_current/total/unit`), `started_at`, `finished_at`, `rewatch_count`; `rewatch_log` table | Drops tracking data entered since |
| `Version20261121000003` | `release_year` (generated from `year`), `external_source/id`, `synopsis`, `backdrop_url`, `last_synced_at`; `genres`, `media_genre` | Drops fetched metadata/genres |
| `Version20261121000004` | `tags`, `media_tag` | Drops tags |
| `Version20261121000005` | `user_lists`, `user_list_media` (ordered) | Drops lists |
| `Version20261121000006` | Indexes on `status`, `category_id`, `rating_id`, `created_at` | Indexes only |

Join tables cascade on a **hard** delete of a media row (`app:purge-deleted`); a soft delete keeps
them, so Undo restores tags/lists/genres too.

### Statuses

`media.status` stays a `VARCHAR(20)` holding display values; the allowed set lives in
`App\Domain\MediaStatus`: `Plan to Watch`, `Plan to Read`, `Watching`, `Reading`, `Watched`, `Read`,
`On Hold`, `Dropped`, `Rewatching` (NULL or `''` = not in list). Unknown values are rejected with HTTP 400.
Before deploying, check production has no other values:

```sql
SELECT status, COUNT(*) FROM media GROUP BY status;
```

## Console commands

All batch commands are resumable (checkpoint in `var/checkpoints/`), support `--dry-run`, and write
CSV reports to `var/reports/`. **Run with `--dry-run` first.**

### `app:normalize-titles`

Cleans titles and fills `sort_title` (used for A–Z sorting and search).

- Default ("safe") mode: Unicode NFC, plus NFKC **only** for fake-styled characters
  (Mathematical bold/italic letters such as `𝐓𝐡𝐞 𝐁𝐥𝐚𝐜𝐤 𝐏𝐡𝐨𝐧𝐞`, full-width forms such as `Ｒｅ：ＺＥＲＯ`),
  removes zero-width characters and trims whitespace. Real characters like `C³`, `DNA²`, `Ranma ½`, `…` are kept.
- `--full-nfkc`: plain NFKC on whole titles (turns `C³` into `C3` — not recommended).
- Never changes letter case. All-lowercase titles are listed in `lowercase-titles-*.csv` for manual review.
- `sort_title` = full NFKC, lowercased, leading `The/A/An` removed.

```bash
php bin/console app:normalize-titles --dry-run
php bin/console app:normalize-titles                    # resumes automatically if interrupted
php bin/console app:normalize-titles --restart          # ignore checkpoint
php bin/console app:normalize-titles --revert=var/reports/title-changes-YYYYMMDD-HHMMSS.csv
```

`--revert` only restores rows whose title is still the normalized value (later edits are kept).

### `app:backfill-public-scores`

Copies `score_mal` → `public_score` with `score_source = mal`. Scores of 0 stay `NULL` (shown as "—").
Only touches rows where `public_score IS NULL`; never overwrites a score set by hand.

```bash
php bin/console app:backfill-public-scores --dry-run
php bin/console app:backfill-public-scores
```

Until it runs, the API falls back to `score_mal`, so the order of deploy steps doesn't matter.

### `app:report-uncategorized`

Read-only. Lists media with no category or a missing category id (`uncategorized-*.csv`).
These are shown under **Unknown** in the Categories filter.

### `app:purge-deleted` (destructive)

Deleting from the UI is a soft delete (Undo toast; `POST api.php?action=restore&id=N`).
This permanently removes rows deleted more than N days ago.

```bash
php bin/console app:purge-deleted --older-than=30 --dry-run
php bin/console app:purge-deleted --older-than=30       # asks for confirmation (or --force)
```

### `app:reset-imported-ratings` (reversible)

The legacy MAL importer copied the MAL score into "My Rating". This resets those copies to 0 on items
**not in your list** (status empty), after writing every old value to `rating-reset-backup-*.csv`.
In-list items with a rating equal to the MAL score are only listed in `ratings-equal-mal-in-list-*.csv`.

```bash
php bin/console app:reset-imported-ratings --dry-run
php bin/console app:reset-imported-ratings
php bin/console app:reset-imported-ratings --revert=var/reports/rating-reset-backup-YYYYMMDD-HHMMSS.csv
```

### `app:backfill-metadata`

Re-matches items without an external id whose public score is missing, or whose poster is missing, tiny
(< 4 KB), low-resolution (< 150 px wide) or from a watermarked stock site (`alamy`). Anime/manga categories
search Jikan, TV Series/Documentary/Web Series search TMDB, Movie/unknown search both.

- Confidence ≥ `--min-confidence` (default 0.9) → applied, **filling empty fields only** (poster only when
  it has a quality problem). Status, my rating, notes and progress are never touched.
- 0.5 – 0.9 → `metadata-review-*.csv`. Put `yes` in the `approve` column, then
  `php bin/console app:backfill-metadata --apply-review=<file>`.
- Below 0.5 / errors → `metadata-no-match-*.csv`.

```bash
php bin/console app:backfill-metadata --dry-run --limit=50   # sample run, writes reports only
php bin/console app:backfill-metadata                         # resumable (var/checkpoints/)
php bin/console app:backfill-metadata --ids=12,34 --include-matched
```

Jikan is limited to 3 requests/s and 60/min (enforced across processes), so ~10 000 candidates take ≈ 3 h.
TMDB needs `TMDB_API_KEY` in `.env` (a v3 key or a v4 read token); it is only ever used server-side.

### `app:import` / `app:export`

```bash
php bin/console app:import animelist.xml --dry-run     # MyAnimeList XML export (anime or manga, .xml or .xml.gz)
php bin/console app:import diary.csv                   # Letterboxd: watched/ratings/diary/watchlist.csv
php bin/console app:import mmr-export.json             # MMR's own JSON/CSV export
php bin/console app:export --format=csv --in-list -o my-list.csv
```

Preview shows create / update / skip counts. Duplicates are detected by external id first, then
title + year (title alone only when unambiguous), and repeated rows inside one file are skipped.
Updates never blank out existing values and never overwrite existing notes.

## API changes

| Request | Notes |
|---|---|
| `GET api.php?...&order_by=m.public_score DESC` | New sort key; legacy `m.score_mal DESC/ASC` still accepted |
| `GET api.php?category_ids=<Unknown id>` | Also returns uncategorized media |
| `POST api.php[?id=N]` with `public_score`, `score_source` (`mal`/`tmdb`/`imdb`/`manual`) | Empty or 0 → no score. Legacy `score_mal` still accepted (as MAL) |
| `DELETE api.php?id=N` | Soft delete; 404 if not found |
| `POST api.php?action=restore&id=N` | Undo a delete |
| `GET api.php?action=statuses` | Allowed status values |
| `POST api.php?action=update_tracking&id=N` | JSON with any of `progress_current`, `progress_total`, `progress_unit` (`episode`/`chapter`/`volume`/`page`), `started_at`, `finished_at` (`YYYY-MM-DD`), `rewatch_count`. Partial; validated (400 on error). Returns the updated row |
| `GET api.php?order_by=m.year ASC\|DESC` | Now numeric via `release_year`; unknown years (`N/A`) last |
| any write with an unknown `status` | 400 |
| `GET ?action=lookup&q=…&type=anime\|manga\|movie\|tv\|screen\|all\|auto[&category=…]` | Auto-fill suggestions (Jikan/TMDB), cached 24 h server-side; `warnings` explains a missing TMDB key or a provider outage |
| `GET ?action=lookup_details&source=mal_anime\|mal_manga\|tmdb_movie\|tmdb_tv&id=N` | Full metadata + matching `category_id` / `rating_id`; 502 when the provider is down |
| `POST api.php[?id=N]` extras | `image_url` (poster from MAL/TMDB CDNs only), `external_source`, `external_id`, `synopsis`, `backdrop_url`, `genres` (array/JSON), `tags`, tracking fields |
| `POST ?action=progress&id=N` `{by}` | +N progress; starts tracking (status → Watching/Reading, `started_at`). Returns `reached_total` |
| `POST ?action=complete&id=N` `[{finished_at}]` | Watched/Read, progress = total, `finished_at`; completing a Rewatching item logs the rewatch |
| `GET ?action=stats` | One aggregated dashboard payload, cached 10 min, invalidated by writes |
| `GET ?action=recommendations[&rows=2&limit=12]` | "Because you liked X" rows |
| `GET ?action=random&limit=N` + list filters | Random items respecting filters (`limit=1` = "pick one for me") |
| `GET ?action=detail&id=N` | Item + genres, tags, lists, rewatch log, related titles |
| `GET ?action=home_rows` | Continue watching, recently added, top rated, random picks |
| `GET ?action=export&format=json\|csv[&scope=filtered&…filters]` | File download |
| `POST ?action=import_preview` (multipart `file`) → `POST ?action=import_commit` `{token, create, update}` | Two-step import |

List filters (list, random, export): `keyword`, `category_ids`, `rating_ids`, `statuses`, `tag_ids`,
`genre_ids`, `list_id`, `finished_year`, `in_progress=1`, `exclude_adult=1`. New sort keys: `progress DESC|ASC`,
`m.status ASC|DESC`, `category ASC|DESC`, `finished DESC`.

Security changes: uploads are validated by content (JPEG/PNG/WebP/GIF only; previously any file type
could be uploaded into `medias/`), the wildcard CORS header was removed, and server errors no longer
leak SQL messages.

Responses now include `public_score`, `score_source`, `sort_title`, `created_at`, `release_year`, the progress/date fields and external metadata; `GET ?id=N` also returns `synopsis` (`score_mal` is no longer returned).

## Tests & lint

```bash
composer test     # PHPUnit: unit + integration (recreates the hostinger_mediarank_test database)
composer lint     # php -l on all project files
npm install       # once
npm run check     # ESLint + node:test for js/
```

Integration tests refuse to run against a database whose name doesn't end in `_test`.
