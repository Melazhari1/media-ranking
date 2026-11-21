// Detail page: /media/:id-slug — replaces the old Infos modal.
import { api } from '../api.js?v=2.1.0';
import { Icon, StatusBadge, Modal } from '../components/common.js?v=2.1.0';
import { Carousel } from '../components/cards.js?v=2.1.0';
import { TagInput } from '../components/forms.js?v=2.1.0';
import { openForm, mediaVersion, showToast, ui, loadTags, bumpMedia } from '../store.js?v=2.1.0';
import { statusOptionsFor, statusLabel, statusGroup } from '../status.js?v=2.1.0';
import {
    posterUrl, isAdult, slugify, formatPublicScore, publicScoreTitle, categoryName, progressLabel, progressPct,
    formatDate, unitPlural, todayIso,
} from '../format.js?v=2.1.0';
import { setStatus, saveScore, progressPlusOne, completeItem, deleteWithUndo } from '../actions.js?v=2.1.0';

const { ref, computed, watch, onMounted, onBeforeUnmount } = Vue;

/** Log a rewatch/reread with dates and a note (increments rewatch_count, keeps history). */
const RewatchModal = {
    components: { Modal },
    props: { item: { type: Object, required: true } },
    emits: ['close', 'saved'],
    setup(props, { emit }) {
        const startedAt = ref('');
        const finishedAt = ref(todayIso());
        const notes = ref('');
        const busy = ref(false);
        const error = ref('');
        const save = async () => {
            busy.value = true;
            error.value = '';
            try {
                await api('log_rewatch', { method: 'POST', query: { id: props.item.id }, body: { started_at: startedAt.value || null, finished_at: finishedAt.value || null, notes: notes.value } });
                emit('saved');
            } catch (e) {
                error.value = e.message;
            } finally {
                busy.value = false;
            }
        };
        return { startedAt, finishedAt, notes, busy, error, save };
    },
    template: `
    <Modal title="Log a rewatch" labelledby="rw-title" @close="$emit('close')">
        <div class="form-row">
            <div class="form-group"><label for="rw-start">Started</label><input id="rw-start" type="date" v-model="startedAt"></div>
            <div class="form-group"><label for="rw-end">Finished</label><input id="rw-end" type="date" v-model="finishedAt"></div>
        </div>
        <div class="form-group"><label for="rw-notes">Notes</label><textarea id="rw-notes" v-model="notes" rows="3" placeholder="How was it this time?"></textarea></div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <template #footer>
            <button class="btn-cancel" @click="$emit('close')">Cancel</button>
            <button class="btn-save" @click="save" :disabled="busy">{{ busy ? 'Saving…' : 'Log rewatch' }}</button>
        </template>
    </Modal>`,
};

export const DetailView = {
    components: { Icon, StatusBadge, Carousel, TagInput, RewatchModal },
    setup() {
        const route = VueRouter.useRoute();
        const router = VueRouter.useRouter();
        const item = ref(null);
        const related = ref([]);
        const loading = ref(true);
        const notFound = ref(false);
        const notes = ref('');
        const notesSaving = ref(false);
        const scoreDraft = ref(0);
        const tags = ref([]);
        const revealed = ref(false);
        const showRewatch = ref(false);

        const id = computed(() => parseInt(String(route.params.ref), 10));

        const load = async () => {
            if (!id.value) { notFound.value = true; loading.value = false; return; }
            loading.value = !item.value || item.value.id !== id.value;
            try {
                const res = await api('detail', { query: { id: id.value } });
                item.value = res.data;
                related.value = res.related || [];
                notes.value = res.data.infos || '';
                scoreDraft.value = Number(res.data.score) || 0;
                tags.value = (res.data.tags || []).map(t => t.name);
                notFound.value = false;
                const canonical = `${res.data.id}-${slugify(res.data.title)}`;
                if (route.params.ref !== canonical) router.replace({ params: { ref: canonical } });
                document.title = `${res.data.title} · MMR`;
                document.querySelector('meta[name="description"]')?.setAttribute('content', (res.data.synopsis || `${categoryName(res.data)} · ${res.data.year}`).slice(0, 200));
            } catch (e) {
                if (e.status === 404) notFound.value = true;
                else showToast(e.message);
            } finally {
                loading.value = false;
            }
        };

        const statusOptions = computed(() => statusOptionsFor(item.value?.categories));
        const pct = computed(() => item.value ? progressPct(item.value) : null);
        const adultHidden = computed(() => item.value && isAdult(item.value) && !revealed.value);
        const banner = computed(() => item.value?.backdrop_url || (item.value ? posterUrl(item.value) : ''));
        const isDone = computed(() => statusGroup(item.value?.status) === 'done');

        const changeStatus = (e) => setStatus(item.value, e.target.value || null);
        const commitScore = () => saveScore(item.value, Number(scoreDraft.value));
        const saveNotes = async () => {
            notesSaving.value = true;
            try {
                await api('update_infos', { method: 'POST', query: { id: item.value.id }, body: { infos: notes.value } });
                item.value.infos = notes.value;
                showToast('Notes saved', null, 2500);
            } catch (e) {
                showToast(e.message);
            } finally {
                notesSaving.value = false;
            }
        };
        const saveTags = async (names) => {
            tags.value = names;
            try {
                const res = await api('media_tags', { method: 'POST', query: { id: item.value.id }, body: { tags: names } });
                item.value.tags = res.data;
                loadTags();
                bumpMedia();
            } catch (e) {
                showToast(e.message);
            }
        };
        const minus = () => progressPlusOne(item.value, -1);
        const remove = async () => {
            if (await deleteWithUndo(item.value)) router.push('/');
        };
        const removeFromList = async (list) => {
            try {
                await api('list_remove_item', { method: 'POST', query: { id: list.id }, body: { media_id: item.value.id } });
                item.value.lists = item.value.lists.filter(l => l.id !== list.id);
                bumpMedia();
            } catch (e) {
                showToast(e.message);
            }
        };
        const onRewatchSaved = () => { showRewatch.value = false; showToast('Rewatch logged', null, 3000); bumpMedia(); };

        watch(id, load);
        watch(mediaVersion, () => { if (item.value) load(); });
        onMounted(load);
        onBeforeUnmount(() => { document.title = 'MMR - My Media Ranking'; });

        return {
            item, related, loading, notFound, notes, notesSaving, scoreDraft, tags, revealed, showRewatch,
            statusOptions, pct, adultHidden, banner, isDone, ui,
            changeStatus, commitScore, saveNotes, saveTags, minus, remove, removeFromList, onRewatchSaved,
            openForm, progressPlusOne, completeItem, posterUrl, formatPublicScore, publicScoreTitle, categoryName,
            progressLabel, formatDate, unitPlural, statusLabel,
        };
    },
    template: `
    <div class="detail-page">
        <div v-if="loading" class="loading-container"><div class="spinner"></div></div>
        <div v-else-if="notFound" class="empty-state">
            <h2>Not found</h2>
            <p>This item doesn't exist or was deleted.</p>
            <router-link to="/" class="btn-chip">Back to the list</router-link>
        </div>
        <template v-else-if="item">
            <div class="detail-banner" :class="{ 'from-poster': !item.backdrop_url }">
                <img :src="banner" alt="" :class="{ 'image-blurred': adultHidden }">
            </div>
            <div class="detail-main">
                <div class="detail-poster">
                    <img :src="posterUrl(item)" :alt="'Poster of ' + item.title" :class="{ 'image-blurred': adultHidden }">
                    <button v-if="adultHidden" class="btn-chip" @click="revealed = true"><Icon name="eye" :size="16" /> Reveal</button>
                </div>
                <div class="detail-info">
                    <router-link to="/" class="back-link"><Icon name="chevronLeft" :size="16" /> Back</router-link>
                    <h1>{{ item.title }}</h1>
                    <div class="detail-meta">
                        <span>{{ categoryName(item) }}</span>
                        <span>{{ item.release_year || item.year }}</span>
                        <span v-if="item.ratings" class="rating-badge static">{{ item.ratings }}</span>
                        <span class="public-score" :class="{ 'no-score': formatPublicScore(item) === '—' }" :title="publicScoreTitle(item)">{{ formatPublicScore(item) }}</span>
                        <a v-if="item.external_id && item.external_source.startsWith('mal')" :href="'https://myanimelist.net/' + (item.external_source === 'mal_manga' ? 'manga' : 'anime') + '/' + item.external_id" target="_blank" rel="noopener noreferrer">MyAnimeList ↗</a>
                        <a v-if="item.external_id && item.external_source.startsWith('tmdb')" :href="'https://www.themoviedb.org/' + (item.external_source === 'tmdb_tv' ? 'tv' : 'movie') + '/' + item.external_id" target="_blank" rel="noopener noreferrer">TMDB ↗</a>
                    </div>
                    <div v-if="item.genres.length" class="chip-row" aria-label="Genres">
                        <span v-for="g in item.genres" :key="g" class="chip">{{ g }}</span>
                    </div>

                    <div class="detail-controls">
                        <div class="form-group">
                            <label for="d-status">Status</label>
                            <select id="d-status" :value="item.status || ''" @change="changeStatus">
                                <option value="">Not in list</option>
                                <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="d-score">My rating: <strong>{{ scoreDraft > 0 ? Number(scoreDraft).toFixed(1) + ' ★' : '—' }}</strong></label>
                            <input id="d-score" type="range" min="0" max="10" step="0.1" class="score-slider" v-model.number="scoreDraft" @change="commitScore">
                        </div>
                    </div>

                    <div class="detail-progress">
                        <div class="progress-head">
                            <strong>{{ progressLabel(item) || 'No progress tracked' }}</strong>
                            <span v-if="pct !== null" class="muted">{{ pct }}%</span>
                        </div>
                        <div v-if="pct !== null" class="progress-bar" role="progressbar" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: pct + '%' }"></span></div>
                        <div class="progress-actions">
                            <button class="btn-chip" @click="minus" :disabled="!item.progress_current">−1</button>
                            <button class="btn-chip primary" @click="progressPlusOne(item)">+1 {{ item.progress_unit ? '' : '' }}</button>
                            <button v-if="!isDone" class="btn-chip" @click="completeItem(item)"><Icon name="check" :size="16" /> Mark finished</button>
                            <button class="btn-chip" @click="showRewatch = true"><Icon name="repeat" :size="16" /> Log rewatch</button>
                        </div>
                    </div>

                    <dl class="detail-dates">
                        <div><dt>Started</dt><dd>{{ formatDate(item.started_at) || '—' }}</dd></div>
                        <div><dt>Finished</dt><dd>{{ formatDate(item.finished_at) || '—' }}</dd></div>
                        <div><dt>Rewatches</dt><dd>{{ item.rewatch_count }}</dd></div>
                        <div><dt>Added</dt><dd>{{ formatDate((item.created_at || '').slice(0, 10)) }}</dd></div>
                    </dl>

                    <div class="detail-actions">
                        <button class="btn-chip" @click="openForm(item)"><Icon name="edit" :size="16" /> Edit</button>
                        <button class="btn-chip" @click="ui.addToList = [item]"><Icon name="bookmark" :size="16" /> Add to list</button>
                        <button class="btn-chip danger" @click="remove"><Icon name="trash" :size="16" /> Delete</button>
                    </div>
                </div>
            </div>

            <div class="detail-sections">
                <section v-if="item.synopsis" class="detail-section">
                    <h2>Synopsis</h2>
                    <p class="synopsis">{{ item.synopsis }}</p>
                </section>

                <section class="detail-section">
                    <h2><label for="d-tags">Tags</label></h2>
                    <TagInput :model-value="tags" @update:model-value="saveTags" input-id="d-tags" />
                </section>

                <section class="detail-section">
                    <h2>Lists</h2>
                    <div class="chip-row">
                        <span v-for="l in item.lists" :key="l.id" class="chip removable">
                            <router-link :to="{ path: '/', query: { list: l.id } }">{{ l.name }}</router-link>
                            <button type="button" @click="removeFromList(l)" :aria-label="'Remove from ' + l.name">×</button>
                        </span>
                        <button class="btn-chip" @click="ui.addToList = [item]">+ Add to list</button>
                    </div>
                </section>

                <section class="detail-section">
                    <h2><label for="d-notes">My notes</label></h2>
                    <textarea id="d-notes" v-model="notes" rows="5" placeholder="Notes, thoughts, favourite moments…"></textarea>
                    <button class="btn-save" @click="saveNotes" :disabled="notesSaving || notes === (item.infos || '')">{{ notesSaving ? 'Saving…' : 'Save notes' }}</button>
                </section>

                <section v-if="item.rewatch_log.length" class="detail-section">
                    <h2>Rewatch history</h2>
                    <ul class="rewatch-list">
                        <li v-for="r in item.rewatch_log" :key="r.id">
                            <strong>{{ formatDate(r.finished_at) || 'Undated' }}</strong>
                            <span v-if="r.started_at" class="muted"> (from {{ formatDate(r.started_at) }})</span>
                            <span v-if="r.notes"> — {{ r.notes }}</span>
                        </li>
                    </ul>
                </section>
            </div>

            <Carousel id="related" title="Related & recommended" :items="related" />
            <RewatchModal v-if="showRewatch" :item="item" @close="showRewatch = false" @saved="onRewatchSaved" />
        </template>
    </div>`,
};
