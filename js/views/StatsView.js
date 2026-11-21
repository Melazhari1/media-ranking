// /stats dashboard — one aggregated, cached endpoint.
import { api } from '../api.js?v=2.1.0';
import { Icon } from '../components/common.js?v=2.1.0';
import { GROUPS, GROUP_ORDER } from '../status.js?v=2.1.0';
import { formatNumber, unitPlural } from '../format.js?v=2.1.0';
import { mediaVersion } from '../store.js?v=2.1.0';

const { ref, reactive, computed, onMounted, watch } = Vue;

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Last 24 months as YYYY-MM, oldest first, with zero-filled counts. */
function last24(perMonth) {
    const map = Object.fromEntries(perMonth.map(r => [r.month, r.count]));
    const out = [];
    const d = new Date();
    d.setDate(1);
    for (let i = 23; i >= 0; i--) {
        const x = new Date(d.getFullYear(), d.getMonth() - i, 1);
        const key = `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}`;
        out.push({ key, label: MONTHS[x.getMonth()] + (x.getMonth() === 0 || i === 23 ? ' ' + String(x.getFullYear()).slice(2) : ''), full: `${MONTHS[x.getMonth()]} ${x.getFullYear()}`, count: map[key] || 0 });
    }
    return out;
}

/** Round an axis max up to a clean number. */
function niceMax(n) {
    if (n <= 5) return Math.max(1, n);
    const p = 10 ** Math.floor(Math.log10(n));
    return Math.ceil(n / (p / 2)) * (p / 2);
}

export const StatsView = {
    components: { Icon },
    setup() {
        const data = ref(null);
        const error = ref('');
        const asTables = ref(false);
        const tip = reactive({ show: false, x: 0, y: 0, text: '' });

        const load = async () => {
            try {
                data.value = (await api('stats')).data;
            } catch (e) {
                error.value = e.message;
            }
        };
        onMounted(() => { load(); document.title = 'Stats · MMR'; });
        watch(mediaVersion, load);

        const showTip = (e, text) => {
            const r = e.currentTarget.getBoundingClientRect();
            tip.text = text;
            tip.x = r.left + r.width / 2;
            tip.y = r.top;
            tip.show = true;
        };
        const hideTip = () => { tip.show = false; };

        const groups = computed(() => {
            if (!data.value) return [];
            const g = data.value.totals.by_group;
            return GROUP_ORDER.filter(k => g[k]).map(k => ({ key: k, label: GROUPS[k].label, count: g[k] }));
        });
        const inList = computed(() => data.value?.totals.in_list || 0);
        const categories = computed(() => (data.value?.totals.by_category || []).slice(0, 10));
        const categoryMax = computed(() => niceMax(Math.max(1, ...categories.value.map(c => c.count))));
        const dist = computed(() => (data.value?.ratings.distribution || []).map((count, score) => ({ score, count })).slice(1));
        const distMax = computed(() => niceMax(Math.max(1, ...dist.value.map(d => d.count))));
        const months = computed(() => last24(data.value?.finished.per_month || []));
        const monthMax = computed(() => niceMax(Math.max(1, ...months.value.map(m => m.count))));
        const years = computed(() => data.value?.finished.per_year || []);
        const yearMax = computed(() => niceMax(Math.max(1, ...years.value.map(y => y.count))));
        const genres = computed(() => data.value?.top_genres || []);
        const genreMax = computed(() => niceMax(Math.max(1, ...genres.value.map(g => g.count))));
        const thisYear = new Date().getFullYear();
        const finishedThisYear = computed(() => years.value.find(y => y.year === thisYear)?.count || 0);
        const consumed = computed(() => Object.entries(data.value?.consumed || {}).filter(([, n]) => n > 0));
        const diff = computed(() => {
            const r = data.value?.ratings;
            return r && r.my_avg !== null && r.public_avg !== null ? Math.round((r.my_avg - r.public_avg) * 100) / 100 : null;
        });

        return {
            data, error, asTables, tip, showTip, hideTip, groups, inList, categories, categoryMax, dist, distMax,
            months, monthMax, years, yearMax, genres, genreMax, thisYear, finishedThisYear, consumed, diff,
            formatNumber, unitPlural, pct: (n, max) => (max ? (n / max) * 100 : 0),
        };
    },
    template: `
    <div class="stats-page viz-root">
        <div class="page-header">
            <div class="page-title"><h2>Stats</h2><span v-if="data" class="muted">Your list at a glance</span></div>
            <div class="view-controls">
                <label class="checkbox-item"><input type="checkbox" v-model="asTables"> Show as tables</label>
            </div>
        </div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <div v-if="!data && !error" class="loading-container"><div class="spinner"></div></div>

        <template v-if="data">
            <div class="stat-tiles">
                <div class="stat-tile"><span class="stat-label">In my list</span><span class="stat-value">{{ formatNumber(inList) }}</span>
                    <span class="stat-sub">of {{ formatNumber(data.totals.all) }} in the catalogue</span></div>
                <div class="stat-tile"><span class="stat-label">Finished in {{ thisYear }}</span><span class="stat-value">{{ formatNumber(finishedThisYear) }}</span>
                    <span class="stat-sub">{{ formatNumber(data.rewatches) }} rewatches all-time</span></div>
                <div class="stat-tile"><span class="stat-label">My average rating</span>
                    <span class="stat-value">{{ data.ratings.my_avg ?? '—' }}</span>
                    <span class="stat-sub">over {{ formatNumber(data.ratings.rated_count) }} rated items</span></div>
                <div class="stat-tile"><span class="stat-label">Public average (same items)</span>
                    <span class="stat-value">{{ data.ratings.public_avg ?? '—' }}</span>
                    <span class="stat-sub" v-if="diff !== null">I rate {{ diff >= 0 ? diff + ' higher' : Math.abs(diff) + ' lower' }} on average</span></div>
                <div v-for="[unit, n] in consumed" :key="unit" class="stat-tile"><span class="stat-label">{{ unitPlural(unit) }} {{ unit === 'episode' ? 'watched' : 'read' }}</span>
                    <span class="stat-value">{{ formatNumber(n) }}</span></div>
            </div>

            <p v-if="data.ratings.equal_to_mal > 0" class="notice">
                {{ formatNumber(data.ratings.equal_to_mal) }} of your in-list ratings are identical to the MAL score — probably copied by the old importer.
                Averages may be skewed; see <code>app:reset-imported-ratings</code> in the README.
            </p>

            <section class="chart-card">
                <h3>Status</h3>
                <template v-if="!asTables">
                    <div class="stack-bar" role="img" :aria-label="groups.map(g => g.label + ' ' + g.count).join(', ')">
                        <span v-for="g in groups" :key="g.key" class="stack-seg" :class="'st-' + g.key" :style="{ flexGrow: g.count }"
                            tabindex="0" :aria-label="g.label + ': ' + g.count"
                            @mouseenter="showTip($event, g.label + ': ' + formatNumber(g.count) + ' (' + Math.round(g.count / inList * 100) + '%)')"
                            @mouseleave="hideTip" @focus="showTip($event, g.label + ': ' + formatNumber(g.count))" @blur="hideTip"></span>
                    </div>
                    <ul class="legend">
                        <li v-for="g in groups" :key="g.key"><i class="status-dot" :class="'st-' + g.key"></i>{{ g.label }} <strong>{{ formatNumber(g.count) }}</strong></li>
                    </ul>
                </template>
                <table v-else class="data-table">
                    <thead><tr><th>Status</th><th>Items</th></tr></thead>
                    <tbody><tr v-for="s in data.totals.by_status" :key="s.status"><td>{{ s.status }}</td><td>{{ formatNumber(s.count) }}</td></tr></tbody>
                </table>
            </section>

            <div class="chart-grid">
                <section class="chart-card">
                    <h3>By category <span class="muted">(in list)</span></h3>
                    <div v-if="!asTables" class="hbars">
                        <div v-for="c in categories" :key="c.category" class="hbar-row">
                            <span class="hbar-label">{{ c.category }}</span>
                            <span class="hbar-track"><span class="hbar" :style="{ width: pct(c.count, categoryMax) + '%' }" tabindex="0"
                                :aria-label="c.category + ': ' + c.count" @mouseenter="showTip($event, c.category + ': ' + formatNumber(c.count))"
                                @mouseleave="hideTip" @focus="showTip($event, c.category + ': ' + formatNumber(c.count))" @blur="hideTip"></span></span>
                            <span class="hbar-value">{{ formatNumber(c.count) }}</span>
                        </div>
                    </div>
                    <table v-else class="data-table"><thead><tr><th>Category</th><th>Items</th></tr></thead>
                        <tbody><tr v-for="c in data.totals.by_category" :key="c.category"><td>{{ c.category }}</td><td>{{ formatNumber(c.count) }}</td></tr></tbody></table>
                </section>

                <section class="chart-card">
                    <h3>My rating distribution</h3>
                    <div v-if="!asTables" class="columns" :style="{ '--cols': dist.length }">
                        <div v-for="d in dist" :key="d.score" class="col">
                            <span class="col-value">{{ d.count || '' }}</span>
                            <span class="col-track"><span class="col-bar" :style="{ height: pct(d.count, distMax) + '%' }" tabindex="0"
                                :aria-label="'Rated ' + d.score + ': ' + d.count" @mouseenter="showTip($event, 'Rated ' + d.score + ': ' + formatNumber(d.count))"
                                @mouseleave="hideTip" @focus="showTip($event, 'Rated ' + d.score + ': ' + formatNumber(d.count))" @blur="hideTip"></span></span>
                            <span class="col-label">{{ d.score }}</span>
                        </div>
                    </div>
                    <table v-else class="data-table"><thead><tr><th>Rating (rounded)</th><th>Items</th></tr></thead>
                        <tbody><tr v-for="d in dist" :key="d.score"><td>{{ d.score }}</td><td>{{ d.count }}</td></tr></tbody></table>
                </section>
            </div>

            <section class="chart-card">
                <h3>Finished per month <span class="muted">(last 24 months, incl. rewatches)</span></h3>
                <div v-if="!asTables" class="columns months" :style="{ '--cols': months.length }">
                    <div v-for="m in months" :key="m.key" class="col">
                        <span class="col-value">{{ m.count || '' }}</span>
                        <span class="col-track"><span class="col-bar" :style="{ height: pct(m.count, monthMax) + '%' }" tabindex="0"
                            :aria-label="m.full + ': ' + m.count" @mouseenter="showTip($event, m.full + ': ' + m.count + ' finished')"
                            @mouseleave="hideTip" @focus="showTip($event, m.full + ': ' + m.count + ' finished')" @blur="hideTip"></span></span>
                        <span class="col-label">{{ m.label }}</span>
                    </div>
                </div>
                <table v-else class="data-table"><thead><tr><th>Month</th><th>Finished</th></tr></thead>
                    <tbody><tr v-for="m in months" :key="m.key"><td>{{ m.full }}</td><td>{{ m.count }}</td></tr></tbody></table>
                <p v-if="!months.some(m => m.count)" class="muted empty-chart">No finish dates yet — they're set when you mark something Watched/Read.</p>
            </section>

            <div class="chart-grid">
                <section class="chart-card">
                    <h3>Finished per year</h3>
                    <div v-if="!asTables && years.length" class="hbars">
                        <div v-for="y in years" :key="y.year" class="hbar-row">
                            <span class="hbar-label">{{ y.year }}</span>
                            <span class="hbar-track"><span class="hbar" :style="{ width: pct(y.count, yearMax) + '%' }" tabindex="0" :aria-label="y.year + ': ' + y.count"
                                @mouseenter="showTip($event, y.year + ': ' + y.count + ' finished')" @mouseleave="hideTip"
                                @focus="showTip($event, y.year + ': ' + y.count)" @blur="hideTip"></span></span>
                            <span class="hbar-value">{{ y.count }}</span>
                        </div>
                    </div>
                    <table v-else-if="years.length" class="data-table"><thead><tr><th>Year</th><th>Finished</th></tr></thead>
                        <tbody><tr v-for="y in years" :key="y.year"><td>{{ y.year }}</td><td>{{ y.count }}</td></tr></tbody></table>
                    <p v-else class="muted empty-chart">No finish dates yet.</p>
                </section>

                <section class="chart-card">
                    <h3>Top genres <span class="muted">(in list)</span></h3>
                    <div v-if="!asTables && genres.length" class="hbars">
                        <div v-for="g in genres" :key="g.genre" class="hbar-row">
                            <span class="hbar-label">{{ g.genre }}</span>
                            <span class="hbar-track"><span class="hbar" :style="{ width: pct(g.count, genreMax) + '%' }" tabindex="0"
                                :aria-label="g.genre + ': ' + g.count + ' items'" @mouseenter="showTip($event, g.genre + ': ' + g.count + ' items' + (g.my_avg ? ' · my avg ' + g.my_avg : ''))"
                                @mouseleave="hideTip" @focus="showTip($event, g.genre + ': ' + g.count)" @blur="hideTip"></span></span>
                            <span class="hbar-value">{{ g.count }}<span v-if="g.my_avg" class="muted"> · ★{{ g.my_avg }}</span></span>
                        </div>
                    </div>
                    <table v-else-if="genres.length" class="data-table"><thead><tr><th>Genre</th><th>Items</th><th>My avg</th></tr></thead>
                        <tbody><tr v-for="g in genres" :key="g.genre"><td>{{ g.genre }}</td><td>{{ g.count }}</td><td>{{ g.my_avg ?? '—' }}</td></tr></tbody></table>
                    <p v-else class="muted empty-chart">Genres appear once items are linked to MyAnimeList/TMDB (auto-fill or <code>app:backfill-metadata</code>).</p>
                </section>
            </div>
            <p class="muted small">Updated {{ new Date(data.generated_at).toLocaleString() }} · refreshes after changes</p>
        </template>

        <div v-show="tip.show" class="viz-tooltip" role="tooltip" :style="{ left: tip.x + 'px', top: tip.y + 'px' }">{{ tip.text }}</div>
    </div>`,
};
