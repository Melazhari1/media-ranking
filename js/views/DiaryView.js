// /diary — timeline of finished items and logged rewatches, grouped by month.
import { api } from '../api.js?v=2.1.0';
import { Icon, StatusBadge } from '../components/common.js?v=2.1.0';
import { posterUrl, isAdult, mediaPath, formatDate, categoryName, myScore } from '../format.js?v=2.1.0';
import { mediaVersion } from '../store.js?v=2.1.0';

const { ref, computed, watch, onMounted } = Vue;

export const DiaryView = {
    components: { Icon, StatusBadge },
    setup() {
        const route = VueRouter.useRoute();
        const router = VueRouter.useRouter();
        const entries = ref([]);
        const years = ref([]);
        const loading = ref(true);
        const error = ref('');
        const year = ref(route.query.year ? String(route.query.year) : String(new Date().getFullYear()));

        const load = async () => {
            loading.value = true;
            try {
                const res = (await api('diary', { query: { year: year.value } })).data;
                entries.value = res.entries;
                years.value = res.years;
            } catch (e) {
                error.value = e.message;
            } finally {
                loading.value = false;
            }
        };
        watch(year, (y) => { router.replace({ query: { year: y } }); load(); });
        watch(mediaVersion, load);
        onMounted(() => { load(); document.title = 'Diary · MMR'; });

        const groups = computed(() => {
            const out = [];
            for (const e of entries.value) {
                const key = e.entry_date.slice(0, 7);
                let g = out[out.length - 1];
                if (!g || g.key !== key) {
                    const d = new Date(key + '-01T00:00:00');
                    g = { key, label: d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }), items: [] };
                    out.push(g);
                }
                g.items.push(e);
            }
            return out;
        });

        return { entries, years, loading, error, year, groups, posterUrl, isAdult, mediaPath, formatDate, categoryName, myScore };
    },
    template: `
    <div class="diary-page">
        <div class="page-header">
            <div class="page-title"><h2>Diary</h2><span class="muted" v-if="!loading">{{ entries.length }} entries</span></div>
            <div class="view-controls">
                <label class="sr-only" for="diary-year">Year</label>
                <select id="diary-year" v-model="year" class="compact-select">
                    <option value="all">All years</option>
                    <option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option>
                    <option v-if="!years.includes(Number(year)) && year !== 'all'" :value="year">{{ year }}</option>
                </select>
            </div>
        </div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <div v-if="loading" class="loading-container"><div class="spinner"></div></div>
        <div v-else-if="!entries.length" class="empty-state">
            <p>Nothing finished {{ year === 'all' ? 'yet' : 'in ' + year }}.</p>
            <p class="muted">Entries appear when you mark something Watched/Read (finish date) or log a rewatch.</p>
        </div>
        <section v-for="g in groups" :key="g.key" class="diary-month">
            <h3>{{ g.label }} <span class="muted">{{ g.items.length }}</span></h3>
            <ol class="diary-list">
                <li v-for="e in g.items" :key="e.kind + e.entry_id">
                    <span class="diary-day">{{ new Date(e.entry_date + 'T00:00:00').getDate() }}</span>
                    <router-link :to="mediaPath(e)" class="diary-item">
                        <img :src="posterUrl(e)" alt="" loading="lazy" :class="{ 'image-blurred': isAdult(e) }">
                        <span class="diary-text">
                            <strong>{{ e.title }}</strong>
                            <span class="muted">{{ categoryName(e) }} · {{ e.release_year || e.year }}</span>
                            <span v-if="e.notes" class="diary-note">“{{ e.notes }}”</span>
                        </span>
                        <span class="diary-badges">
                            <span v-if="e.kind === 'rewatch'" class="chip"><Icon name="repeat" :size="14" /> Rewatch</span>
                            <span v-if="myScore(e)" class="my-score">♥ {{ myScore(e) }}</span>
                        </span>
                    </router-link>
                </li>
            </ol>
        </section>
    </div>`,
};
