// Add/Edit form with metadata auto-fill, and the tag input.
import { api } from '../api.js?v=2.1.0';
import { Modal, Icon } from './common.js?v=2.1.0';
import { lookups, categoryName, modals, showToast, bumpMedia, loadTags } from '../store.js?v=2.1.0';
import { statusOptionsFor, statusForCategory } from '../status.js?v=2.1.0';
import { SCORE_SOURCES, posterUrl } from '../format.js?v=2.1.0';

const { ref, reactive, computed, watch, onMounted } = Vue;

const UNITS = [
    { value: 'episode', label: 'Episodes' },
    { value: 'chapter', label: 'Chapters' },
    { value: 'volume', label: 'Volumes' },
    { value: 'page', label: 'Pages' },
];

/** Chips + autocomplete over existing tags; Enter or comma adds a new one. */
export const TagInput = {
    props: { modelValue: { type: Array, default: () => [] }, inputId: { type: String, default: 'tag-input' } },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const text = ref('');
        const open = ref(false);
        const active = ref(-1);
        const suggestions = computed(() => {
            const q = text.value.trim().toLowerCase();
            const chosen = new Set(props.modelValue.map(t => t.toLowerCase()));
            return lookups.tags.filter(t => !chosen.has(t.name.toLowerCase()) && (!q || t.name.toLowerCase().includes(q))).slice(0, 8);
        });
        const add = (name) => {
            name = String(name || '').trim().replace(/,+$/, '');
            if (!name || name.length > 64) return;
            if (!props.modelValue.some(t => t.toLowerCase() === name.toLowerCase())) emit('update:modelValue', [...props.modelValue, name]);
            text.value = '';
            active.value = -1;
        };
        const remove = (name) => emit('update:modelValue', props.modelValue.filter(t => t !== name));
        const onKey = (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                add(active.value >= 0 ? suggestions.value[active.value]?.name : text.value);
            } else if (e.key === 'Backspace' && !text.value && props.modelValue.length) {
                remove(props.modelValue[props.modelValue.length - 1]);
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                open.value = true;
                active.value = Math.min(active.value + 1, suggestions.value.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                active.value = Math.max(active.value - 1, -1);
            } else if (e.key === 'Escape') {
                open.value = false;
            }
        };
        onMounted(() => { if (!lookups.tags.length) loadTags(); });
        return { text, open, active, suggestions, add, remove, onKey };
    },
    template: `
    <div class="tag-input" @click="$refs.input.focus()">
        <span v-for="t in modelValue" :key="t" class="tag-chip">{{ t }}
            <button type="button" @click.stop="remove(t)" :aria-label="'Remove tag ' + t">×</button></span>
        <input ref="input" :id="inputId" v-model="text" @keydown="onKey" @focus="open = true" @blur="setTimeout(() => open = false, 150)"
            placeholder="Add tag…" role="combobox" :aria-expanded="open && suggestions.length > 0" aria-autocomplete="list"
            :aria-controls="inputId + '-list'" autocomplete="off">
        <ul v-if="open && suggestions.length" :id="inputId + '-list'" class="suggest-list small" role="listbox">
            <li v-for="(s, i) in suggestions" :key="s.id" role="option" :aria-selected="i === active" :class="{ active: i === active }"
                @mousedown.prevent="add(s.name)">{{ s.name }} <span class="muted">{{ s.media_count }}</span></li>
        </ul>
    </div>`,
    methods: { setTimeout: (fn, ms) => window.setTimeout(fn, ms) },
};

const blankForm = () => ({
    id: null, title: '', image: '', year: String(new Date().getFullYear()), score: 0,
    public_score: null, score_source: 'manual', status: null, infos: '', category_id: null, rating_id: null,
    progress_current: null, progress_total: null, progress_unit: '', started_at: '', finished_at: '', rewatch_count: 0,
    external_source: '', external_id: '', synopsis: '', backdrop_url: '', genres: [], tags: [], image_url: '',
});

export const MediaFormModal = {
    components: { Modal, Icon, TagInput },
    props: { item: { type: Object, default: null } },
    emits: ['close'],
    setup(props, { emit }) {
        const form = reactive(blankForm());
        const file = ref(null);
        const preview = ref(null);
        const saving = ref(false);
        const loading = ref(false);
        const error = ref('');

        // ── auto-fill ──
        const suggestions = ref([]);
        const warnings = ref([]);
        const suggestOpen = ref(false);
        const activeIdx = ref(-1);
        const searching = ref(false);
        const lookupKind = ref('auto');
        let debounce = null;
        let seq = 0;
        let suppressNextLookup = false;

        const category = computed(() => categoryName(form.category_id));
        const statusOptions = computed(() => statusOptionsFor(category.value));

        // Keep the status wording in sync with the category (Watching ↔ Reading)
        watch(category, (c) => { if (form.status) form.status = statusForCategory(form.status, c); });

        const runLookup = async (q) => {
            const mySeq = ++seq;
            searching.value = true;
            try {
                const res = await api('lookup', { query: { q, type: lookupKind.value, category: category.value || '' } });
                if (mySeq !== seq) return; // a newer request superseded this one
                suggestions.value = res.data;
                warnings.value = res.warnings || [];
                suggestOpen.value = true;
                activeIdx.value = -1;
            } catch (e) {
                if (mySeq === seq) warnings.value = [e.message];
            } finally {
                if (mySeq === seq) searching.value = false;
            }
        };

        watch(() => form.title, (q) => {
            clearTimeout(debounce);
            if (suppressNextLookup) { suppressNextLookup = false; return; }
            if (!q || q.trim().length < 2) { suggestions.value = []; suggestOpen.value = false; return; }
            debounce = setTimeout(() => runLookup(q.trim()), 300);
        });

        const choose = async (s) => {
            suggestOpen.value = false;
            loading.value = true;
            try {
                const d = (await api('lookup_details', { query: { source: s.external_source, id: s.external_id } })).data;
                suppressNextLookup = true;
                form.title = d.title;
                if (d.year) form.year = String(d.year);
                if (d.category_id) form.category_id = Number(d.category_id);
                if (d.rating_id) form.rating_id = Number(d.rating_id);
                if (d.public_score) { form.public_score = d.public_score; form.score_source = d.score_source || 'manual'; }
                if (d.total) form.progress_total = d.total;
                if (d.unit) form.progress_unit = d.unit;
                form.synopsis = d.synopsis || '';
                form.backdrop_url = d.backdrop_url || '';
                form.genres = d.genres || [];
                form.external_source = d.external_source;
                form.external_id = d.external_id;
                if (d.poster_url && !file.value) { form.image_url = d.poster_url; preview.value = d.poster_url; }
                if (!d.category_id && d.category) warnings.value = [`Category "${d.category}" will be created.`];
            } catch (e) {
                warnings.value = [e.message];
            } finally {
                loading.value = false;
            }
        };

        const onTitleKey = (e) => {
            if (!suggestOpen.value || !suggestions.value.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx.value = (activeIdx.value + 1) % suggestions.value.length; }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx.value = activeIdx.value <= 0 ? suggestions.value.length - 1 : activeIdx.value - 1; }
            else if (e.key === 'Enter' && activeIdx.value >= 0) { e.preventDefault(); choose(suggestions.value[activeIdx.value]); }
            else if (e.key === 'Escape') { e.stopPropagation(); suggestOpen.value = false; }
        };
        const clearExternal = () => {
            Object.assign(form, { external_source: '', external_id: '', synopsis: '', backdrop_url: '', genres: [], image_url: '' });
            preview.value = form.image ? posterUrl(form) : null;
        };

        // ── load (edit) ──
        onMounted(async () => {
            if (!props.item) return;
            loading.value = true;
            try {
                const d = (await api('detail', { query: { id: props.item.id } })).data;
                suppressNextLookup = true;
                Object.assign(form, blankForm(), {
                    id: d.id, title: d.title, image: d.image, year: d.year, score: Number(d.score) || 0,
                    public_score: d.public_score !== null ? Number(d.public_score) : null, score_source: d.score_source || 'manual',
                    status: d.status || null, infos: d.infos || '',
                    category_id: d.category_id ? Number(d.category_id) : null, rating_id: d.rating_id ? Number(d.rating_id) : null,
                    progress_current: d.progress_current, progress_total: d.progress_total, progress_unit: d.progress_unit || '',
                    started_at: d.started_at || '', finished_at: d.finished_at || '', rewatch_count: Number(d.rewatch_count) || 0,
                    external_source: d.external_source || '', external_id: d.external_id || '', synopsis: d.synopsis || '',
                    backdrop_url: d.backdrop_url || '', genres: d.genres || [], tags: (d.tags || []).map(t => t.name),
                });
                preview.value = d.image ? posterUrl(d) : null;
            } catch (e) {
                error.value = e.message;
            } finally {
                loading.value = false;
            }
        });

        const onFile = (e) => {
            const f = e.target.files?.[0];
            if (!f) return;
            file.value = f;
            form.image_url = '';
            const reader = new FileReader();
            reader.onload = (ev) => { preview.value = ev.target.result; };
            reader.readAsDataURL(f);
        };

        const save = async () => {
            error.value = '';
            if (!form.title.trim()) { error.value = 'Title is required.'; return; }
            if (form.progress_total !== null && form.progress_total !== '' && Number(form.progress_current) > Number(form.progress_total)) {
                error.value = 'Progress cannot be more than the total.'; return;
            }
            if (form.started_at && form.finished_at && form.finished_at < form.started_at) {
                error.value = 'The finish date is before the start date.'; return;
            }
            saving.value = true;
            try {
                const fd = new FormData();
                const put = (k, v) => fd.append(k, v === null || v === undefined ? '' : v);
                put('title', form.title.trim());
                put('year', form.year);
                put('score', form.score || 0);
                put('public_score', form.public_score === null || form.public_score === '' || Number(form.public_score) <= 0 ? '' : form.public_score);
                put('score_source', form.score_source || 'manual');
                put('status', form.status ?? '');
                put('infos', form.infos);
                put('category_ids', form.category_id ?? '');
                put('rating_ids', form.rating_id ?? '');
                ['progress_current', 'progress_total', 'progress_unit', 'started_at', 'finished_at', 'rewatch_count']
                    .forEach(k => put(k, form[k] === '' ? '' : form[k]));
                if (form.external_id) {
                    put('external_source', form.external_source);
                    put('external_id', form.external_id);
                }
                put('synopsis', form.synopsis);
                put('backdrop_url', form.backdrop_url);
                put('genres', JSON.stringify(form.genres));
                put('tags', JSON.stringify(form.tags));
                if (file.value) fd.append('image_file', file.value);
                else if (form.image_url) put('image_url', form.image_url);
                else put('image', form.image || '');

                const res = await api('', { method: 'POST', query: form.id ? { id: form.id } : {}, form: fd });
                (res.warnings || []).forEach(w => showToast(w));
                showToast(form.id ? 'Saved changes' : `Added "${form.title.trim()}"`, null, 3000);
                bumpMedia();
                loadTags();
                emit('close', res.id);
            } catch (e) {
                error.value = e.message;
            } finally {
                saving.value = false;
            }
        };

        return {
            form, preview, saving, loading, error, lookups, statusOptions, category, UNITS, SCORE_SOURCES,
            suggestions, warnings, suggestOpen, activeIdx, searching, lookupKind,
            choose, onTitleKey, clearExternal, onFile, save, modals,
            closeSuggest: () => setTimeout(() => { suggestOpen.value = false; }, 150),
        };
    },
    template: `
    <Modal :title="form.id ? 'Edit Media' : 'Add New Media'" wide labelledby="media-form-title" @close="$emit('close')">
        <form class="media-form" @submit.prevent="save" novalidate>
            <div v-if="loading" class="form-loading" aria-live="polite"><div class="spinner small"></div> Loading…</div>

            <div class="form-row">
                <div class="form-group autocomplete">
                    <label for="mf-title">Title*</label>
                    <div class="input-with-select">
                        <input id="mf-title" type="text" v-model="form.title" required placeholder="Start typing to search MyAnimeList / TMDB"
                            @keydown="onTitleKey" @focus="suggestions.length && (suggestOpen = true)" @blur="closeSuggest"
                            role="combobox" aria-autocomplete="list" :aria-expanded="suggestOpen && suggestions.length > 0"
                            aria-controls="mf-suggest" :aria-activedescendant="activeIdx >= 0 ? 'mf-sg-' + activeIdx : null" autocomplete="off">
                        <select v-model="lookupKind" aria-label="Search source">
                            <option value="auto">Auto</option>
                            <option value="anime">Anime</option>
                            <option value="manga">Manga / novels</option>
                            <option value="screen">Movies &amp; series</option>
                        </select>
                    </div>
                    <div v-if="searching" class="suggest-status muted">Searching…</div>
                    <ul v-if="suggestOpen && suggestions.length" id="mf-suggest" class="suggest-list" role="listbox">
                        <li v-for="(s, i) in suggestions" :id="'mf-sg-' + i" :key="s.external_source + s.external_id" role="option"
                            :aria-selected="i === activeIdx" :class="{ active: i === activeIdx }" @mousedown.prevent="choose(s)">
                            <img v-if="s.poster_url" :src="s.poster_url" alt="" loading="lazy">
                            <span v-else class="suggest-noimg"></span>
                            <span class="suggest-text">
                                <strong>{{ s.title }}</strong>
                                <span class="muted">{{ [s.type, s.year, s.alt_title].filter(Boolean).join(' · ') }}</span>
                            </span>
                            <span class="suggest-src">{{ s.external_source.startsWith('mal') ? 'MAL' : 'TMDB' }}<template v-if="s.public_score"> {{ s.public_score }}★</template></span>
                        </li>
                    </ul>
                    <p v-for="w in warnings" :key="w" class="form-hint warn">{{ w }}</p>
                    <p v-if="form.external_id" class="form-hint">
                        Linked to {{ form.external_source.startsWith('mal') ? 'MyAnimeList' : 'TMDB' }} #{{ form.external_id }}
                        <template v-if="form.genres.length"> · {{ form.genres.join(', ') }}</template>
                        <button type="button" class="link-btn" @click="clearExternal">Unlink</button>
                    </p>
                </div>
                <div class="form-group narrow">
                    <label for="mf-year">Year</label>
                    <input id="mf-year" type="text" v-model="form.year" placeholder="2023-Q1">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="mf-image">Poster</label>
                    <input id="mf-image" type="file" @change="onFile" accept="image/jpeg,image/png,image/webp,image/gif" class="file-input">
                    <p v-if="form.image_url" class="form-hint">Poster will be downloaded from the selected title.</p>
                </div>
                <div v-if="preview" class="image-preview"><img :src="preview" alt="Poster preview"></div>
            </div>

            <div class="form-row three">
                <div class="form-group">
                    <label for="mf-status">Status</label>
                    <select id="mf-status" v-model="form.status">
                        <option :value="null">None (not in list)</option>
                        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mf-score">My score (0–10)</label>
                    <input id="mf-score" type="number" v-model.number="form.score" step="0.1" min="0" max="10">
                </div>
                <div class="form-group">
                    <label for="mf-public">Public score</label>
                    <div class="input-with-select">
                        <input id="mf-public" type="number" v-model.number="form.public_score" step="0.01" min="0" max="10" placeholder="—">
                        <select v-model="form.score_source" aria-label="Score source">
                            <option v-for="src in SCORE_SOURCES" :key="src.value" :value="src.value">{{ src.label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <fieldset class="form-fieldset">
                <legend>Progress &amp; dates</legend>
                <div class="form-row four">
                    <div class="form-group">
                        <label for="mf-cur">Current</label>
                        <input id="mf-cur" type="number" min="0" v-model.number="form.progress_current" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label for="mf-tot">Total</label>
                        <input id="mf-tot" type="number" min="0" v-model.number="form.progress_total" placeholder="?">
                    </div>
                    <div class="form-group">
                        <label for="mf-unit">Unit</label>
                        <select id="mf-unit" v-model="form.progress_unit">
                            <option value="">Auto</option>
                            <option v-for="u in UNITS" :key="u.value" :value="u.value">{{ u.label }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mf-rw">Rewatches</label>
                        <input id="mf-rw" type="number" min="0" v-model.number="form.rewatch_count">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="mf-start">Started</label>
                        <input id="mf-start" type="date" v-model="form.started_at">
                    </div>
                    <div class="form-group">
                        <label for="mf-end">Finished</label>
                        <input id="mf-end" type="date" v-model="form.finished_at">
                    </div>
                </div>
            </fieldset>

            <div class="form-group">
                <span class="label" id="mf-cat-label">Category</span>
                <div class="checkbox-grid" role="radiogroup" aria-labelledby="mf-cat-label">
                    <label v-for="cat in lookups.categories" :key="cat.id" class="checkbox-item">
                        <input type="radio" :value="Number(cat.id)" v-model="form.category_id" name="media_category"> {{ cat.name }}
                    </label>
                </div>
            </div>

            <div class="form-group">
                <span class="label" id="mf-rat-label">Rating (age / content)</span>
                <div class="checkbox-grid" role="radiogroup" aria-labelledby="mf-rat-label">
                    <label v-for="rat in lookups.ratings" :key="rat.id" class="checkbox-item">
                        <input type="radio" :value="Number(rat.id)" v-model="form.rating_id" name="media_rating"> {{ rat.name }}
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="mf-tags">Tags</label>
                <TagInput v-model="form.tags" input-id="mf-tags" />
            </div>

            <div v-if="form.synopsis" class="form-group">
                <label for="mf-syn">Synopsis</label>
                <textarea id="mf-syn" v-model="form.synopsis" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label for="mf-notes">My notes</label>
                <textarea id="mf-notes" v-model="form.infos" rows="3" placeholder="Notes, thoughts…"></textarea>
            </div>
            <p v-if="error" class="form-error" role="alert">{{ error }}</p>
            <button type="submit" hidden></button>
        </form>
        <template #footer>
            <button class="btn-cancel" type="button" @click="$emit('close')">Cancel</button>
            <button class="btn-save" type="button" @click="save" :disabled="saving">{{ saving ? 'Saving…' : (form.id ? 'Update Media' : 'Create Media') }}</button>
        </template>
    </Modal>`,
};
