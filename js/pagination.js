/**
 * Page list with ellipses on both sides:
 *   page 1 of 1487  → 1 2 3 … 1487
 *   page 42 of 1487 → 1 … 41 42 43 … 1487
 *   page 1487       → 1 … 1485 1486 1487
 * A gap of exactly one page shows that page instead of "…" (1 2 3 4 5 …, never 1 … 3 4 5).
 *
 * @param {number} current  1-based current page
 * @param {number} total    total pages
 * @param {number} siblings pages shown on each side of the current one
 * @returns {Array<{type:'page', page:number, key:string} | {type:'ellipsis', key:string}>}
 */
export function paginationItems(current, total, siblings = 1) {
    total = Math.max(0, Math.floor(total));
    if (total === 0) return [];
    current = Math.min(Math.max(1, Math.floor(current) || 1), total);

    const window = siblings * 2 + 1;
    let start = current - siblings;
    let end = current + siblings;
    if (start < 1) { start = 1; end = Math.min(total, window); }
    if (end > total) { end = total; start = Math.max(1, total - window + 1); }

    const pages = new Set([1, total]);
    for (let p = start; p <= end; p++) pages.add(p);
    const sorted = [...pages].sort((a, b) => a - b);

    const items = [];
    let prev = 0;
    for (const p of sorted) {
        if (prev && p - prev === 2) {
            items.push({ type: 'page', page: prev + 1, key: `p${prev + 1}` });
        } else if (prev && p - prev > 2) {
            items.push({ type: 'ellipsis', key: prev === 1 ? 'ellipsis-start' : 'ellipsis-end' });
        }
        items.push({ type: 'page', page: p, key: `p${p}` });
        prev = p;
    }
    return items;
}
