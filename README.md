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

## API changes

| Request | Notes |
|---|---|
| `GET api.php?...&order_by=m.public_score DESC` | New sort key; legacy `m.score_mal DESC/ASC` still accepted |
| `GET api.php?category_ids=<Unknown id>` | Also returns uncategorized media |
| `POST api.php[?id=N]` with `public_score`, `score_source` (`mal`/`tmdb`/`imdb`/`manual`) | Empty or 0 → no score. Legacy `score_mal` still accepted (as MAL) |
| `DELETE api.php?id=N` | Soft delete; 404 if not found |
| `POST api.php?action=restore&id=N` | Undo a delete |

Responses now include `public_score`, `score_source`, `sort_title`, `created_at` (`score_mal` is no longer returned).

## Tests & lint

```bash
composer test     # PHPUnit: unit + integration (recreates the hostinger_mediarank_test database)
composer lint     # php -l on all project files
npm install       # once
npm run check     # ESLint + node:test for js/
```

Integration tests refuse to run against a database whose name doesn't end in `_test`.
