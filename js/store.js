// Shared reactive state: preferences (persisted), lookups, filters and global UI (toast, confirm, modals).
import { api } from './api.js?v=2.1.0';

const { reactive, watch, ref } = Vue;

// localStorage can throw (private mode, blocked storage) — never let that break the app.
export const storage = {
    get(key, fallback) {
        try {
            const v = localStorage.getItem(key);
            return v === null ? fallback : JSON.parse(v);
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* ignore */ }
    },
    raw(key) {
        try { return localStorage.getItem(key); } catch { return null; }
    },
    setRaw(key, value) {
        try { localStorage.setItem(key, value); } catch { /* ignore */ }
    },
};

export const PAGE_SIZES = [20, 40, 60, 100];

export const prefs = reactive({
    viewMode: storage.get('mmr.viewMode', 'grid'),            // grid | list
    pageSize: storage.get('mmr.pageSize', 20),
    collapsedRows: storage.get('mmr.collapsedRows', {}),
    showHomeRows: storage.get('mmr.showHomeRows', true),
    dark: storage.raw('theme') === 'dark',                    // legacy key kept
});
if (!PAGE_SIZES.includes(prefs.pageSize)) prefs.pageSize = 20;

watch(() => prefs.viewMode, v => storage.set('mmr.viewMode', v));
watch(() => prefs.pageSize, v => storage.set('mmr.pageSize', v));
watch(() => prefs.collapsedRows, v => storage.set('mmr.collapsedRows', v), { deep: true });
watch(() => prefs.showHomeRows, v => storage.set('mmr.showHomeRows', v));
watch(() => prefs.dark, v => {
    storage.setRaw('theme', v ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark-theme', v);
}, { immediate: true });

export const lookups = reactive({ categories: [], ratings: [], lists: [], tags: [], loaded: false });

export async function loadLookups() {
    const [cats, rats] = await Promise.all([api('categories'), api('ratings')]);
    lookups.categories = cats.data;
    lookups.ratings = rats.data;
    lookups.loaded = true;
    await Promise.all([loadLists(), loadTags()]).catch(() => {});
}
export async function loadLists() {
    try { lookups.lists = (await api('lists')).data; } catch { lookups.lists = []; }
}
export async function loadTags() {
    try { lookups.tags = (await api('tags', { query: { limit: 200 } })).data; } catch { lookups.tags = []; }
}

export const categoryName = (id) => lookups.categories.find(c => Number(c.id) === Number(id))?.name ?? null;

export const emptyFilters = () => ({
    keyword: '', categoryIds: [], ratingIds: [], statuses: [], tagIds: [], listId: 0, finishedYear: 0,
    orderBy: 'm.created_at DESC',
});
export const filters = reactive(emptyFilters());

export const hasActiveFilters = () =>
    !!(filters.keyword.trim() || filters.categoryIds.length || filters.ratingIds.length || filters.statuses.length
        || filters.tagIds.length || filters.listId || filters.finishedYear);

export function resetFilters() {
    Object.assign(filters, emptyFilters());
}

// Bumped after any write so views can refresh.
export const mediaVersion = ref(0);
export const bumpMedia = () => { mediaVersion.value++; };

// ── Toast ───────────────────────────────────────────────────────────────────
export const toast = reactive({ current: null });
let toastTimer = null;
export function showToast(message, action = null, duration = 8000) {
    clearTimeout(toastTimer);
    toast.current = { message, action };
    toastTimer = setTimeout(() => { toast.current = null; }, duration);
}
export function pauseToast() { clearTimeout(toastTimer); }
export function resumeToast() {
    if (!toast.current) return;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.current = null; }, 3000);
}
export function dismissToast() { clearTimeout(toastTimer); toast.current = null; }

// ── Confirm (promise-based) ─────────────────────────────────────────────────
export const confirmState = reactive({ current: null });
/** await confirmDialog({ title, message, confirmLabel, danger }) → true/false */
export function confirmDialog(options) {
    return new Promise(resolve => {
        confirmState.current = { confirmLabel: 'Confirm', danger: false, ...options, resolve };
    });
}
export function settleConfirm(result) {
    const c = confirmState.current;
    confirmState.current = null;
    c?.resolve(result);
}

// ── Global modals / UI ──────────────────────────────────────────────────────
export const modals = reactive({ form: null, importExport: false, pickOne: false, settings: false });
/** sidebarOpen: mobile filter drawer; addToList: items to add to a list (opens the picker). */
export const ui = reactive({ sidebarOpen: false, addToList: null });
/** Open Add (item = null) or Edit. */
export function openForm(item = null) { modals.form = { item }; }
