// Home: sidebar filters, home rows, grid/list of media, pagination, bulk selection.
import { api, filterQuery } from '../api.js?v=2.1.0';
import { Icon, Pagination, StatusBadge } from '../components/common.js?v=2.1.0';
import { MediaCard, MediaTable, Carousel } from '../components/cards.js?v=2.1.0';
import { BulkBar } from '../components/bulk.js?v=2.1.0';
import {
    filters, prefs, lookups, hasActiveFilters, resetFilters, mediaVersion, openForm, modals, PAGE_SIZES, ui, emptyFilters,
} from '../store.js?v=2.1.0';
import { FILTER_STATUS_OPTIONS } from '../status.js?v=2.1.0';
import { deleteWithUndo } from '../actions.js?v=2.1.0';

const { ref, reactive, computed, watch, onMounted } = Vue;

const SORT_OPTIONS = [
    { value: 'm.created_at DESC', label: 'Newest added' },
    { value: 'm.created_at ASC', label: 'Oldest added' },
    { value: 'm.title ASC', label: 'Title (A–Z)' },
    { value: 'm.title DESC', label: 'Title (Z–A)' },
    { value: 'm.score DESC', label: 'My rating (high–low)' },
    { value: 'm.public_score DESC', label: 'Public score (high–low)' },
    { value: 'm.year DESC', label: 'Year (newest)' },
    { value: 'm.year ASC', label: 'Year (oldest)' },
    { value: 'progress DESC', label: 'Progress (most done)' },
    { value: 'finished DESC', label: 'Recently finished' },
];

// filters <-> URL query (so links, reloads and Back keep the view)
const toQuery = (f, page) => {
    const q = {};
    if (f.keyword.trim()) q.q = f.keyword.trim();
    if (f.categoryIds.length) q.cat = f.categoryIds.join(',');
    if (f.ratingIds.length) q.rating = f.ratingIds.join(',');
    if (f.statuses.length) q.status = f.statuses.join(',');
    if (f.tagIds.length) q.tag = f.tagIds.join(',');
    if (f.listId) q.list = String(f.listId);
    if (f.finishedYear) q.finished = String(f.finishedYear);
    if (f.orderBy !== 'm.created_at DESC') q.sort = f.orderBy;
    if (page > 1) q.page = String(page);
    return q;
};
const ints = (s) => String(s || '').split(',').map(Number).filter(Boolean);
const fromQuery = (q) => ({
    ...emptyFilters(),
    keyword: q.q || '',
    categoryIds: ints(q.cat), ratingIds: ints(q.rating), tagIds: ints(q.tag),
    statuses: q.status ? String(q.status).split(',') : [],
    listId: Number(q.list) || 0, finishedYear: Number(q.finished) || 0,
    orderBy: q.sort || 'm.created_at DESC',
});

export const HomeView = {
    components: { Icon, Pagination, StatusBadge, MediaCard, MediaTable, Carousel, BulkBar },
    setup() {
        const route = VueRouter.useRoute();
        const router = VueRouter.useRouter();

        const items = ref([]);
        const loading = ref(false);
        const error = ref('');
        const page = ref(Number(route.query.page) || 1);
        const total = ref(0);
        const pages = ref(1);
        const randomMode = ref(false);
        const openGroup = ref(null);
        const rows = ref(null);
        const reco = ref([]);
        const selecting = ref(false);
        const selected = reactive({});
        const selectedItems = computed(() => items.value.filter(i => selected[i.id]));
        const thisYear = new Date().getFullYear();

        Object.assign(filters, fromQuery(route.query));

        const showRows = computed(() => prefs.showHomeRows && !hasActiveFilters() && page.value === 1 && !randomMode.value);

        const fetchPage = async () => {
            loading.value = true;
            error.value = '';
            try {
                const res = await api('', { query: { ...filterQuery(filters), page: page.value, limit: prefs.pageSize, order_by: filters.orderBy } });
                items.value = res.data;
                total.value = res.pagination.total;
                pages.value = res.pagination.pages;
                if (page.value > pages.value && pages.value > 0) { page.value = pages.value; return fetchPage(); }
            } catch (e) {
                error.value = e.message;
            } finally {
                loading.value = false;
            }
        };

        const fetchRows = async () => {
            try {
                const [r, rc] = await Promise.all([api('home_rows'), api('recommendations', { query: { rows: 2, limit: 15 } })]);
                rows.value = r.data;
                reco.value = rc.data;
            } catch { /* rows are optional */ }
        };

        const fetchRandom = async () => {
            loading.value = true;
            try {
                const res = await api('random', { query: { ...filterQuery(filters), limit: 20 } });
                items.value = res.data;
                randomMode.value = true;
                total.value = res.data.length;
                pages.value = 1;
            } catch (e) {
                error.value = e.message;
            } finally {
                loading.value = false;
            }
        };
        const exitRandom = () => { randomMode.value = false; fetchPage(); };

        const syncUrl = () => router.replace({ query: toQuery(filters, page.value) }).catch(() => {});

        let debounce = null;
        watch(() => [filters.categoryIds, filters.ratingIds, filters.statuses, filters.tagIds, filters.listId, filters.finishedYear, filters.orderBy, prefs.pageSize],
            () => { page.value = 1; randomMode.value = false; syncUrl(); fetchPage(); }, { deep: true });
        watch(() => filters.keyword, () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => { page.value = 1; randomMode.value = false; syncUrl(); fetchPage(); }, 400);
        });
        watch(mediaVersion, () => { randomMode.value ? null : fetchPage(); if (showRows.value) fetchRows(); });
        watch(showRows, (v) => { if (v && !rows.value) fetchRows(); });
        // Back/forward or a header search changes the URL: re-read filters
        watch(() => route.query, (q) => {
            if (JSON.stringify(q) === JSON.stringify(toQuery(filters, page.value))) return;
            Object.assign(filters, fromQuery(q));
            page.value = Number(q.page) || 1;
        });

        const changePage = (p) => {
            page.value = p;
            syncUrl();
            fetchPage();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };
        const doReset = () => { resetFilters(); };
        const toggleGroup = (g) => { openGroup.value = openGroup.value === g ? null : g; };

        const label = (selectedArr, options, key, labelKey, all) => {
            if (!selectedArr.length) return all;
            if (selectedArr.length === 1) return options.find(o => String(o[key]) === String(selectedArr[0]))?.[labelKey] ?? '1 selected';
            return `${selectedArr.length} selected`;
        };
        const ratingLabel = computed(() => label(filters.ratingIds, lookups.ratings, 'id', 'label', 'All ratings'));
        const categoryLabel = computed(() => label(filters.categoryIds, lookups.categories, 'id', 'name', 'All categories'));
        const statusLabelText = computed(() => label(filters.statuses, FILTER_STATUS_OPTIONS, 'value', 'label', 'All statuses'));
        const tagLabel = computed(() => label(filters.tagIds, lookups.tags, 'id', 'name', 'All tags'));

        const onDelete = (item) => deleteWithUndo(item);
        const toggleSelect = (item) => { selected[item.id] ? delete selected[item.id] : (selected[item.id] = true); };
        const selectAllOnPage = () => items.value.forEach(i => { selected[i.id] = true; });
        const clearSelection = () => Object.keys(selected).forEach(k => delete selected[k]);
        const stopSelecting = () => { selecting.value = false; clearSelection(); };
        const addToList = (item) => { ui.addToList = [item]; };

        const heading = computed(() => {
            if (randomMode.value) return '20 random picks';
            if (filters.listId) return lookups.lists.find(l => l.id === filters.listId)?.name ?? 'List';
            return hasActiveFilters() ? 'Results' : 'My Watchlist';
        });

        onMounted(() => { fetchPage(); if (showRows.value) fetchRows(); });

        return {
            items, loading, error, page, total, pages, randomMode, openGroup, rows, reco, showRows, heading,
            filters, prefs, lookups, ui, modals, SORT_OPTIONS, PAGE_SIZES, FILTER_STATUS_OPTIONS, thisYear,
            ratingLabel, categoryLabel, statusLabelText, tagLabel,
            selecting, selected, selectedItems,
            fetchRandom, exitRandom, changePage, doReset, toggleGroup, openForm, onDelete,
            toggleSelect, selectAllOnPage, clearSelection, stopSelecting, addToList, hasActiveFilters,
        };
    },
    template: `
    <div class="container">
        <div class="overlay" :class="{ active: ui.sidebarOpen }" @click="ui.sidebarOpen = false"></div>
        <aside :class="{ active: ui.sidebarOpen }" aria-label="Filters">
            <div class="sidebar-header">
                <h3>Filters</h3>
                <button class="close-btn" @click="ui.sidebarOpen = false" aria-label="Close filters">×</button>
            </div>

            <div class="filter-group">
                <label class="filter-title" for="sort-select">Order by</label>
                <select id="sort-select" v-model="filters.orderBy" class="sort-select">
                    <option v-for="o in SORT_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                    <option v-if="filters.listId" value="list_position">List order</option>
                </select>
            </div>

            <div class="filter-group">
                <h3 class="filter-title">Status</h3>
                <button type="button" class="dropdown-toggle" :class="{ open: openGroup === 'status' }" :aria-expanded="openGroup === 'status'" @click="toggleGroup('status')">
                    <span>{{ statusLabelText }}</span><span class="dropdown-arrow">▾</span></button>
                <div class="checkbox-group dropdown-panel" v-show="openGroup === 'status'">
                    <label v-for="opt in FILTER_STATUS_OPTIONS" :key="opt.value" class="checkbox-item">
                        <input type="checkbox" :value="opt.value" v-model="filters.statuses">
                        <i class="status-dot" :class="'st-' + opt.group" aria-hidden="true"></i>{{ opt.label }}
                    </label>
                </div>
            </div>

            <div class="filter-group">
                <h3 class="filter-title">Categories</h3>
                <button type="button" class="dropdown-toggle" :class="{ open: openGroup === 'categories' }" :aria-expanded="openGroup === 'categories'" @click="toggleGroup('categories')">
                    <span>{{ categoryLabel }}</span><span class="dropdown-arrow">▾</span></button>
                <div class="checkbox-group dropdown-panel" v-show="openGroup === 'categories'">
                    <label v-for="cat in lookups.categories" :key="cat.id" class="checkbox-item">
                        <input type="checkbox" :value="Number(cat.id)" v-model="filters.categoryIds"> {{ cat.name }}
                    </label>
                </div>
            </div>

            <div class="filter-group">
                <h3 class="filter-title">Ratings</h3>
                <button type="button" class="dropdown-toggle" :class="{ open: openGroup === 'ratings' }" :aria-expanded="openGroup === 'ratings'" @click="toggleGroup('ratings')">
                    <span>{{ ratingLabel }}</span><span class="dropdown-arrow">▾</span></button>
                <div class="checkbox-group dropdown-panel" v-show="openGroup === 'ratings'">
                    <label v-for="rat in lookups.ratings" :key="rat.id" class="checkbox-item">
                        <input type="checkbox" :value="Number(rat.id)" v-model="filters.ratingIds"> {{ rat.label }}
                    </label>
                </div>
            </div>

            <div v-if="lookups.tags.length" class="filter-group">
                <h3 class="filter-title">Tags</h3>
                <button type="button" class="dropdown-toggle" :class="{ open: openGroup === 'tags' }" :aria-expanded="openGroup === 'tags'" @click="toggleGroup('tags')">
                    <span>{{ tagLabel }}</span><span class="dropdown-arrow">▾</span></button>
                <div class="checkbox-group dropdown-panel" v-show="openGroup === 'tags'">
                    <label v-for="t in lookups.tags" :key="t.id" class="checkbox-item">
                        <input type="checkbox" :value="t.id" v-model="filters.tagIds"> {{ t.name }} <span class="muted">{{ t.media_count }}</span>
                    </label>
                </div>
            </div>

            <div v-if="lookups.lists.length" class="filter-group">
                <label class="filter-title" for="list-select">List</label>
                <select id="list-select" v-model.number="filters.listId" class="sort-select">
                    <option :value="0">All media</option>
                    <option v-for="l in lookups.lists" :key="l.id" :value="l.id">{{ l.name }} ({{ l.media_count }})</option>
                </select>
            </div>

            <label class="checkbox-item filter-check">
                <input type="checkbox" :checked="filters.finishedYear === thisYear" @change="filters.finishedYear = $event.target.checked ? thisYear : 0">
                Finished this year
            </label>

            <div class="sidebar-actions">
                <button class="btn-add btn-secondary" @click="fetchRandom"><Icon name="dice" :size="16" /> Show 20 random</button>
                <button class="btn-add btn-secondary" @click="modals.pickOne = true"><Icon name="star" :size="16" /> Pick one for me</button>
                <button class="btn-add" @click="modals.importExport = true"><Icon name="upload" :size="16" /> Import / Export</button>
                <button class="btn-add" @click="doReset">Reset filters</button>
            </div>
        </aside>

        <main>
            <template v-if="showRows && rows">
                <Carousel id="continue" title="Continue watching" :items="rows.continue" />
                <Carousel v-for="(r, i) in reco" :key="'reco' + r.seed.id" :id="'reco-' + i" :title="'Because you liked ' + r.seed.title" :items="r.items" />
                <Carousel id="recent" title="Recently added" :items="rows.recent" />
                <Carousel id="top" title="Top rated by me" :items="rows.top_rated" />
                <Carousel id="random" title="Random picks from your plan list" :items="rows.random" />
            </template>

            <div class="page-header">
                <div class="page-title">
                    <h2>{{ heading }}</h2>
                    <span class="muted" v-if="!loading">{{ total.toLocaleString() }} {{ total === 1 ? 'item' : 'items' }}</span>
                    <div v-if="loading" class="spinner small" aria-label="Loading"></div>
                </div>
                <div class="view-controls">
                    <button v-if="randomMode" class="btn-chip" @click="fetchRandom"><Icon name="dice" :size="16" /> Shuffle</button>
                    <button v-if="randomMode" class="btn-chip" @click="exitRandom">Back to list</button>
                    <button class="btn-chip" :class="{ active: selecting }" :aria-pressed="selecting" @click="selecting ? stopSelecting() : (selecting = true)">
                        <Icon name="select" :size="16" /> {{ selecting ? 'Done' : 'Select' }}</button>
                    <label class="sr-only" for="page-size">Items per page</label>
                    <select id="page-size" v-model.number="prefs.pageSize" class="compact-select" title="Items per page">
                        <option v-for="n in PAGE_SIZES" :key="n" :value="n">{{ n }} / page</option>
                    </select>
                    <div class="segmented" role="group" aria-label="View mode">
                        <button :class="{ active: prefs.viewMode === 'grid' }" :aria-pressed="prefs.viewMode === 'grid'" @click="prefs.viewMode = 'grid'" title="Grid"><Icon name="grid" :size="16" /><span class="sr-only">Grid</span></button>
                        <button :class="{ active: prefs.viewMode === 'list' }" :aria-pressed="prefs.viewMode === 'list'" @click="prefs.viewMode = 'list'" title="List"><Icon name="list" :size="16" /><span class="sr-only">List</span></button>
                    </div>
                </div>
            </div>

            <div v-if="selecting" class="selection-hint">
                <span>{{ selectedItems.length }} selected</span>
                <button class="link-btn" @click="selectAllOnPage">Select all on page</button>
                <button v-if="selectedItems.length" class="link-btn" @click="clearSelection">Clear</button>
            </div>

            <p v-if="error" class="form-error" role="alert">{{ error }}</p>

            <div v-if="loading && !items.length" class="loading-container"><div class="spinner"></div></div>
            <template v-else>
                <div v-if="prefs.viewMode === 'grid'" class="anime-grid">
                    <MediaCard v-for="item in items" :key="item.id" :item="item" :selectable="selecting" :selected="!!selected[item.id]"
                        @edit="openForm" @delete="onDelete" @toggle-select="toggleSelect" @add-to-list="addToList" />
                </div>
                <MediaTable v-else :items="items" :order-by="filters.orderBy" :selectable="selecting" :selected-ids="selected"
                    @sort="filters.orderBy = $event" @edit="openForm" @delete="onDelete" @toggle-select="toggleSelect" @add-to-list="addToList" />
            </template>

            <div v-if="!loading && !items.length && !error" class="empty-state">
                <p>No results for your filters/search.</p>
                <button v-if="hasActiveFilters()" class="btn-chip" @click="doReset">Reset filters</button>
            </div>

            <Pagination v-if="!randomMode" :page="page" :pages="pages" @change="changePage" />
        </main>

        <BulkBar v-if="selecting && selectedItems.length" :items="selectedItems" @done="clearSelection" />
    </div>`,
};
