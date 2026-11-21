export const SCORE_SOURCES = [
    { value: 'mal',    label: 'MAL' },
    { value: 'tmdb',   label: 'TMDB' },
    { value: 'imdb',   label: 'IMDb' },
    { value: 'manual', label: 'Manual' },
];

const SOURCE_LABELS = { mal: 'MAL', tmdb: 'TMDB', imdb: 'IMDb', manual: 'Score' };

/** Numeric public score or null when missing / 0. */
export function publicScoreValue(item) {
    const n = Number(item?.public_score);
    return item?.public_score === null || item?.public_score === undefined || item?.public_score === '' || !Number.isFinite(n) || n <= 0
        ? null
        : n;
}

/** "MAL 6.7 ★", "TMDB 7.4 ★", or "—" when there is no score. */
export function formatPublicScore(item) {
    const n = publicScoreValue(item);
    if (n === null) return '—';
    const label = SOURCE_LABELS[item.score_source] ?? 'Score';
    return `${label} ${Number(n.toFixed(2))} ★`;
}

/** Tooltip for the public score badge. */
export function publicScoreTitle(item) {
    const src = SCORE_SOURCES.find(s => s.value === item?.score_source);
    return publicScoreValue(item) === null ? 'No public score' : `Public score (${src ? src.label : 'manual'})`;
}

/** Category name, falling back to "Unknown" for uncategorized media. */
export function categoryName(item) {
    return (item?.categories || '').trim() || 'Unknown';
}
