// Media card (grid), table (list view), mini card + carousel (home rows).
import { Icon, StatusBadge } from './common.js?v=2.1.0';
import {
    formatPublicScore, publicScoreTitle, categoryName, posterUrl, isAdult, mediaPath,
    progressLabel, progressPct, myScore,
} from '../format.js?v=2.1.0';
import { cycleStatus, saveScore, progressPlusOne } from '../actions.js?v=2.1.0';
import { prefs } from '../store.js?v=2.1.0';

const { ref, computed } = Vue;

const coarsePointer = () => window.matchMedia?.('(hover: none)').matches;

/**
 * Compact poster card. By default: poster, title, small badges. Hover / keyboard focus / tap
 * (touch) reveals the overlay with my rating, progress +1 and icon actions.
 */
export const MediaCard = {
    components: { Icon, StatusBadge },
    props: {
        item: { type: Object, required: true },
        selectable: Boolean,
        selected: Boolean,
    },
    emits: ['edit', 'delete', 'toggle-select', 'add-to-list'],
    setup(props, { emit }) {
        const router = VueRouter.useRouter();
        const revealedAdult = ref(false);
        const overlayOpen = ref(false);
        const adult = computed(() => isAdult(props.item));
        const hidden = computed(() => adult.value && !revealedAdult.value);
        const pct = computed(() => progressPct(props.item));
        const progress = computed(() => progressLabel(props.item));
        const scoreDraft = ref(Number(props.item.score) || 0);

        const open = () => router.push(mediaPath(props.item));
        const onCardClick = () => {
            if (props.selectable) return emit('toggle-select', props.item);
            if (coarsePointer() && !overlayOpen.value) {
                overlayOpen.value = true; // first tap reveals actions on touch screens
                return;
            }
            open();
        };
        const commitScore = () => saveScore(props.item, Number(scoreDraft.value));
        return {
            prefs, revealedAdult, overlayOpen, adult, hidden, pct, progress, scoreDraft,
            open, onCardClick, commitScore, cycleStatus, progressPlusOne,
            formatPublicScore, publicScoreTitle, categoryName, posterUrl, myScore,
        };
    },
    template: `
    <article class="card" :class="{ 'overlay-open': overlayOpen, selected, selectable }" tabindex="0"
        :aria-label="item.title" @click="onCardClick" @keydown.enter.self="selectable ? $emit('toggle-select', item) : open()"
        @mouseleave="overlayOpen = false">
        <div class="card-poster">
            <img :src="posterUrl(item)" :alt="''" loading="lazy" class="card-image" :class="{ 'image-blurred': hidden }">
            <div class="card-badges">
                <button type="button" class="badge-btn" @click.stop="cycleStatus(item)" :title="'Status: ' + (item.status || 'not in list') + ' — click to change'">
                    <StatusBadge :status="item.status" :category="item.categories" compact />
                </button>
                <span v-if="item.ratings" class="rating-badge">{{ item.ratings }}</span>
            </div>
            <label v-if="selectable" class="select-check" @click.stop>
                <input type="checkbox" :checked="selected" @change="$emit('toggle-select', item)" :aria-label="'Select ' + item.title">
            </label>
            <div v-if="hidden" class="image-hidden-overlay" @click.stop="revealedAdult = true">
                <button class="reveal-btn" @click.stop="revealedAdult = true" title="Show image"><Icon name="eye" :size="20" /></button>
                <span>Adult · Click to reveal</span>
            </div>
            <div v-if="pct !== null" class="poster-progress" role="progressbar" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100"
                :aria-label="progress"><span :style="{ width: pct + '%' }"></span></div>

            <div class="card-overlay" @click.stop>
                <div class="overlay-score">
                    <span class="overlay-label">My rating</span>
                    <span class="overlay-value">{{ scoreDraft > 0 ? Number(scoreDraft).toFixed(1) + ' ★' : '—' }}</span>
                </div>
                <input type="range" step="0.1" min="0" max="10" class="score-slider" v-model.number="scoreDraft"
                    @change="commitScore" :aria-label="'My rating for ' + item.title">
                <div v-if="progress || item.status" class="overlay-progress">
                    <span>{{ progress || 'No progress yet' }}</span>
                    <button type="button" class="icon-btn small" @click="progressPlusOne(item)" :aria-label="'Add one to progress of ' + item.title" title="+1">+1</button>
                </div>
                <div class="overlay-actions">
                    <button type="button" class="icon-btn" @click="open" :aria-label="'Details of ' + item.title" title="Details"><Icon name="info" /></button>
                    <button type="button" class="icon-btn" @click="$emit('edit', item)" :aria-label="'Edit ' + item.title" title="Edit"><Icon name="edit" /></button>
                    <button type="button" class="icon-btn" @click="$emit('add-to-list', item)" :aria-label="'Add ' + item.title + ' to a list'" title="Add to list"><Icon name="bookmark" /></button>
                    <button type="button" class="icon-btn danger" @click="$emit('delete', item)" :aria-label="'Delete ' + item.title" title="Delete"><Icon name="trash" /></button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <h3 class="card-title" :title="item.title">{{ item.title }}</h3>
            <div class="card-meta">
                <span>{{ categoryName(item) }}</span><span aria-hidden="true">·</span><span>{{ item.release_year || item.year }}</span>
            </div>
            <div class="card-scores">
                <span class="public-score" :class="{ 'no-score': formatPublicScore(item) === '—' }" :title="publicScoreTitle(item)">{{ formatPublicScore(item) }}</span>
                <span v-if="myScore(item)" class="my-score" title="My rating">♥ {{ myScore(item) }}</span>
            </div>
        </div>
    </article>`,
};

const COLUMNS = [
    { key: 'title', label: 'Title', sort: 'm.title' },
    { key: 'category', label: 'Category', sort: 'category' },
    { key: 'year', label: 'Year', sort: 'm.year' },
    { key: 'status', label: 'Status', sort: 'm.status' },
    { key: 'score', label: 'My score', sort: 'm.score' },
    { key: 'public', label: 'Public', sort: 'm.public_score' },
    { key: 'progress', label: 'Progress', sort: 'progress' },
];

/** List view: sortable columns bound to the same order_by keys as the sidebar. */
export const MediaTable = {
    components: { Icon, StatusBadge },
    props: { items: { type: Array, required: true }, orderBy: String, selectable: Boolean, selectedIds: { type: Object, default: () => ({}) } },
    emits: ['sort', 'edit', 'delete', 'toggle-select', 'add-to-list'],
    setup(props, { emit }) {
        const sortState = (col) => {
            const [key, dir] = (props.orderBy || '').split(' ');
            return key === col.sort ? (dir === 'ASC' ? 'ascending' : 'descending') : 'none';
        };
        const toggleSort = (col) => {
            const state = sortState(col);
            const textual = ['m.title', 'category', 'm.status'].includes(col.sort);
            const dir = state === 'none' ? (textual ? 'ASC' : 'DESC') : (state === 'ascending' ? 'DESC' : 'ASC');
            emit('sort', `${col.sort} ${dir}`);
        };
        return {
            COLUMNS, sortState, toggleSort, mediaPath,
            categoryName, formatPublicScore, progressLabel, progressPct, myScore, progressPlusOne,
        };
    },
    template: `
    <div class="table-wrap">
        <table class="media-table">
            <thead>
                <tr>
                    <th v-if="selectable" class="col-check"><span class="sr-only">Select</span></th>
                    <th v-for="col in COLUMNS" :key="col.key" :aria-sort="sortState(col)" :class="'col-' + col.key">
                        <button type="button" class="th-sort" @click="toggleSort(col)">
                            {{ col.label }}
                            <span class="sort-arrow" aria-hidden="true">{{ sortState(col) === 'ascending' ? '▲' : sortState(col) === 'descending' ? '▼' : '' }}</span>
                        </button>
                    </th>
                    <th class="col-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in items" :key="item.id" :class="{ selected: selectedIds[item.id] }">
                    <td v-if="selectable" class="col-check">
                        <input type="checkbox" :checked="!!selectedIds[item.id]" @change="$emit('toggle-select', item)" :aria-label="'Select ' + item.title">
                    </td>
                    <td class="col-title"><router-link :to="mediaPath(item)">{{ item.title }}</router-link></td>
                    <td class="col-category">{{ categoryName(item) }}</td>
                    <td class="col-year">{{ item.release_year || item.year }}</td>
                    <td class="col-status"><StatusBadge :status="item.status" :category="item.categories" compact /></td>
                    <td class="col-score">{{ myScore(item) ?? '—' }}</td>
                    <td class="col-public">{{ formatPublicScore(item) }}</td>
                    <td class="col-progress">
                        <div v-if="progressLabel(item)" class="table-progress">
                            <span>{{ progressLabel(item) }}</span>
                            <span v-if="progressPct(item) !== null" class="mini-bar"><span :style="{ width: progressPct(item) + '%' }"></span></span>
                            <button type="button" class="icon-btn small" @click="progressPlusOne(item)" :aria-label="'Add one to progress of ' + item.title">+1</button>
                        </div>
                        <span v-else class="muted">—</span>
                    </td>
                    <td class="col-actions">
                        <button type="button" class="icon-btn" @click="$emit('edit', item)" :aria-label="'Edit ' + item.title"><Icon name="edit" :size="16" /></button>
                        <button type="button" class="icon-btn" @click="$emit('add-to-list', item)" :aria-label="'Add ' + item.title + ' to a list'"><Icon name="bookmark" :size="16" /></button>
                        <button type="button" class="icon-btn danger" @click="$emit('delete', item)" :aria-label="'Delete ' + item.title"><Icon name="trash" :size="16" /></button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>`,
};

export const MiniCard = {
    components: { StatusBadge },
    props: { item: { type: Object, required: true } },
    setup(props) {
        const revealed = ref(false);
        return {
            revealed, posterUrl, isAdult, progressLabel, progressPct, mediaPath,
            hidden: computed(() => isAdult(props.item) && !revealed.value),
        };
    },
    template: `
    <router-link :to="mediaPath(item)" class="mini-card" :title="item.title">
        <div class="mini-poster">
            <img :src="posterUrl(item)" alt="" loading="lazy" :class="{ 'image-blurred': hidden }">
            <span v-if="progressPct(item) !== null" class="poster-progress"><span :style="{ width: progressPct(item) + '%' }"></span></span>
        </div>
        <span class="mini-title">{{ item.title }}</span>
        <span class="mini-sub"><StatusBadge :status="item.status" :category="item.categories" compact />
            <span v-if="progressLabel(item)" class="muted">{{ progressLabel(item) }}</span></span>
    </router-link>`,
};

/** Collapsible horizontal row (Netflix-style). Collapsed state persists per row id. */
export const Carousel = {
    components: { Icon, MiniCard },
    props: { id: { type: String, required: true }, title: { type: String, required: true }, items: { type: Array, default: () => [] } },
    setup(props) {
        const track = ref(null);
        const collapsed = computed({
            get: () => !!prefs.collapsedRows[props.id],
            set: (v) => { prefs.collapsedRows = { ...prefs.collapsedRows, [props.id]: v }; },
        });
        const scroll = (dir) => track.value?.scrollBy({ left: dir * track.value.clientWidth * 0.85, behavior: 'smooth' });
        return { track, collapsed, scroll };
    },
    template: `
    <section v-if="items.length" class="carousel" :aria-labelledby="'row-' + id">
        <div class="carousel-head">
            <button type="button" class="carousel-toggle" :aria-expanded="!collapsed" :aria-controls="'track-' + id" @click="collapsed = !collapsed">
                <Icon name="chevronDown" :size="16" :class="{ rotated: collapsed }" />
                <h2 :id="'row-' + id">{{ title }}</h2>
                <span class="muted count">{{ items.length }}</span>
            </button>
            <div v-show="!collapsed" class="carousel-nav">
                <button type="button" class="icon-btn" @click="scroll(-1)" aria-label="Scroll left"><Icon name="chevronLeft" /></button>
                <button type="button" class="icon-btn" @click="scroll(1)" aria-label="Scroll right"><Icon name="chevronRight" /></button>
            </div>
        </div>
        <div v-show="!collapsed" :id="'track-' + id" ref="track" class="carousel-track">
            <MiniCard v-for="item in items" :key="item.id" :item="item" />
        </div>
    </section>`,
};
