// Thin fetch wrapper around api.php. Relative URLs resolve against <base href>, so this
// works from any client route (/media/12-…, /stats, …) and from a sub-directory install.

export class ApiError extends Error {
    constructor(message, status = 0, payload = null) {
        super(message);
        this.status = status;
        this.payload = payload;
    }
}

const clean = (v) => v !== undefined && v !== null && v !== '' && v !== false && !(Array.isArray(v) && v.length === 0);

export function buildQuery(query = {}) {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) {
        if (clean(v)) params.set(k, Array.isArray(v) ? v.join(',') : String(v === true ? 1 : v));
    }
    return params;
}

export async function api(action = '', { method = 'GET', query = {}, body, form } = {}) {
    const params = buildQuery({ action, ...query });
    const opts = { method, headers: { Accept: 'application/json' } };
    if (form) {
        opts.body = form;
    } else if (body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    let res;
    try {
        res = await fetch('api.php?' + params.toString(), opts);
    } catch {
        throw new ApiError('Network error — check your connection.');
    }
    let data = null;
    try {
        data = await res.json();
    } catch {
        throw new ApiError(`Unexpected server response (HTTP ${res.status})`, res.status);
    }
    if (!res.ok || data?.status === 'error') {
        throw new ApiError(data?.message || `HTTP ${res.status}`, res.status, data);
    }
    return data;
}

/** Store filter state → API query params (shared by list, random and export). */
export function filterQuery(f) {
    return {
        keyword: f.keyword?.trim(),
        category_ids: f.categoryIds,
        rating_ids: f.ratingIds,
        statuses: f.statuses,
        tag_ids: f.tagIds,
        list_id: f.listId || '',
        finished_year: f.finishedYear || '',
    };
}

export function exportUrl(format, scope, filters) {
    const q = { action: 'export', format, scope, ...(scope === 'filtered' ? filterQuery(filters) : {}) };
    return 'api.php?' + buildQuery(q).toString();
}
