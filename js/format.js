export const SCORE_SOURCES = [
    { value: 'mal',    label: 'MAL' },
    { value: 'tmdb',   label: 'TMDB' },
    { value: 'imdb',   label: 'IMDb' },
    { value: 'manual', label: 'Manual' },
];

const SOURCE_LABELS = { mal: 'MAL', tmdb: 'TMDB', imdb: 'IMDb', manual: 'Score' };

/** Numeric public score or null when missing / 0. */
export function publicScoreValue(item) {
    const v = item?.public_score;
    const n = Number(v);
    return v === null || v === undefined || v === '' || !Number.isFinite(n) || n <= 0 ? null : n;
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

export function myScore(item) {
    const n = Number(item?.score);
    return Number.isFinite(n) && n > 0 ? Number(n.toFixed(1)) : null;
}

export function posterUrl(item) {
    return item?.image ? 'medias/' + encodeURIComponent(item.image) : 'https://placehold.co/400x600/333/FFF?text=No+Image';
}

export function isAdult(item) {
    return (item?.rating_label || '').toLowerCase() === 'hentai' || item?.ratings === 'Rx';
}

export function slugify(text) {
    return String(text || '')
        .normalize('NFKD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
        .slice(0, 60) || 'media';
}

/** Route path for the detail page: /media/12-the-black-phone */
export function mediaPath(item) {
    return `/media/${item.id}-${slugify(item.title)}`;
}

const UNIT_SHORT = { episode: 'Ep', chapter: 'Ch', volume: 'Vol', page: 'p.' };
const UNIT_PLURAL = { episode: 'episodes', chapter: 'chapters', volume: 'volumes', page: 'pages' };

export const unitShort = (u) => UNIT_SHORT[u] ?? 'Ep';
export const unitPlural = (u) => UNIT_PLURAL[u] ?? 'episodes';

/** "Ep 7 / 24", "Ch 12", or '' when there is nothing to show. */
export function progressLabel(item) {
    const cur = item?.progress_current;
    const tot = item?.progress_total;
    if ((cur === null || cur === undefined) && !tot) return '';
    return `${unitShort(item.progress_unit)} ${Number(cur || 0)}${tot ? ' / ' + Number(tot) : ''}`;
}

/** 0–100, or null without a total. */
export function progressPct(item) {
    const tot = Number(item?.progress_total);
    if (!tot) return null;
    return Math.max(0, Math.min(100, Math.round((Number(item.progress_current || 0) / tot) * 100)));
}

export function formatDate(d) {
    if (!d) return '';
    const date = new Date(d + (d.length === 10 ? 'T00:00:00' : ''));
    return Number.isNaN(date.getTime()) ? d : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export const todayIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

export function formatNumber(n) {
    return Number(n || 0).toLocaleString();
}
