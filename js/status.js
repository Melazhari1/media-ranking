// Status metadata shared by badges, filters, forms, bulk actions and stats.
// Stored values are the display strings already in the DB; "group" drives colour.

export const READING_CATEGORIES = ['book', 'manga', 'light novel', 'novel', 'manhwa', 'manhua'];

export const GROUPS = {
    plan:       { label: 'Plan' },
    active:     { label: 'In progress' },
    done:       { label: 'Finished' },
    on_hold:    { label: 'On Hold' },
    dropped:    { label: 'Dropped' },
    rewatching: { label: 'Rewatching' },
};
export const GROUP_ORDER = ['plan', 'active', 'done', 'on_hold', 'dropped', 'rewatching'];

export const STATUSES = [
    { value: 'Plan to Watch', group: 'plan' },
    { value: 'Plan to Read',  group: 'plan' },
    { value: 'Watching',      group: 'active' },
    { value: 'Reading',       group: 'active' },
    { value: 'Watched',       group: 'done' },
    { value: 'Read',          group: 'done' },
    { value: 'On Hold',       group: 'on_hold' },
    { value: 'Dropped',       group: 'dropped' },
    { value: 'Rewatching',    group: 'rewatching' },
];

const BY_VALUE = Object.fromEntries(STATUSES.map(s => [s.value, s]));

export const isReadingCategory = (name) => READING_CATEGORIES.includes(String(name || '').trim().toLowerCase());

export function statusGroup(value) {
    return BY_VALUE[value]?.group ?? 'none';
}

/** Display label; Rewatching reads "Rereading" for books/manga. */
export function statusLabel(value, category = null) {
    if (!value) return 'Not in list';
    if (value === 'Rewatching' && isReadingCategory(category)) return 'Rereading';
    return value;
}

/** Status options that fit a category (Watch wording vs Read wording). */
export function statusOptionsFor(category) {
    const reading = isReadingCategory(category);
    const pick = reading
        ? ['Plan to Read', 'Reading', 'Read', 'On Hold', 'Dropped', 'Rewatching']
        : ['Plan to Watch', 'Watching', 'Watched', 'On Hold', 'Dropped', 'Rewatching'];
    return pick.map(v => ({ value: v, label: statusLabel(v, category), group: BY_VALUE[v].group }));
}

/** Map a status to the wording of another category (Watching ↔ Reading…). */
export function statusForCategory(value, category) {
    const group = statusGroup(value);
    if (!['plan', 'active', 'done'].includes(group)) return value;
    return statusOptionsFor(category).find(o => o.group === group)?.value ?? value;
}

/** Every value for the filter, plus "Not in list". */
export const FILTER_STATUS_OPTIONS = [
    ...STATUSES.map(s => ({ value: s.value, label: s.value, group: s.group })),
    { value: 'null', label: 'Not in list', group: 'none' },
];

/** Legacy one-click cycle on the badge: none → plan → done → none. */
export function nextQuickStatus(item) {
    const opts = statusOptionsFor(item.categories);
    const group = statusGroup(item.status);
    if (group === 'none') return opts[0].value;
    if (group === 'plan') return opts[2].value;
    if (group === 'done') return null;
    return opts[2].value; // in progress / on hold / dropped / rewatching → finished
}
