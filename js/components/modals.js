// Import/Export, "Pick one for me", and Add-to-list modals.
import { api, exportUrl, filterQuery } from '../api.js?v=2.1.0';
import { Modal, Icon, StatusBadge } from './common.js?v=2.1.0';
import { filters, hasActiveFilters, showToast, bumpMedia, lookups, loadLists } from '../store.js?v=2.1.0';
import { posterUrl, isAdult, mediaPath, formatPublicScore, categoryName } from '../format.js?v=2.1.0';

const { ref, computed, onMounted } = Vue;

export const ImportExportModal = {
    components: { Modal, Icon },
    emits: ['close'],
    setup() {
        const tab = ref('import');
        const file = ref(null);
        const format = ref('auto');
        const busy = ref(false);
        const error = ref('');
        const preview = ref(null);
        const doCreate = ref(true);
        const doUpdate = ref(true);
        const showAll = ref(false);

        const exportFormat = ref('json');
        const exportScope = ref(hasActiveFilters() ? 'filtered' : 'all');
        const exportHref = computed(() => exportUrl(exportFormat.value, exportScope.value, filters));

        const pick = (e) => { file.value = e.target.files?.[0] || null; preview.value = null; error.value = ''; };
        const runPreview = async () => {
            if (!file.value) { error.value = 'Choose a file first.'; return; }
            busy.value = true;
            error.value = '';
            try {
                const fd = new FormData();
                fd.append('file', file.value);
                fd.append('format', format.value);
                preview.value = (await api('import_preview', { method: 'POST', form: fd })).data;
            } catch (e) {
                error.value = e.message;
            } finally {
                busy.value = false;
            }
        };
        const willApply = computed(() => preview.value
            ? (doCreate.value ? preview.value.summary.create : 0) + (doUpdate.value ? preview.value.summary.update : 0) : 0);
        const commit = async () => {
            busy.value = true;
            error.value = '';
            try {
                const r = (await api('import_commit', { method: 'POST', body: { token: preview.value.token, create: doCreate.value, update: doUpdate.value } })).data;
                showToast(`Import done: ${r.created} created, ${r.updated} updated, ${r.skipped} skipped`, null, 6000);
                preview.value = null;
                file.value = null;
                bumpMedia();
            } catch (e) {
                error.value = e.message;
            } finally {
                busy.value = false;
            }
        };
        const rows = computed(() => !preview.value ? [] : (showAll.value ? preview.value.rows : preview.value.rows.filter(r => r.action !== 'skip' || r.reason?.startsWith('duplicate') || r.reason?.startsWith('missing') || r.reason?.startsWith('unknown')).slice(0, 200)));
        const FORMAT_LABELS = { mal: 'MyAnimeList XML', letterboxd: 'Letterboxd CSV', mmr_json: 'MMR JSON', mmr_csv: 'MMR CSV' };

        return { tab, file, format, busy, error, preview, doCreate, doUpdate, showAll, rows, willApply, FORMAT_LABELS,
            exportFormat, exportScope, exportHref, pick, runPreview, commit, hasActiveFilters };
    },
    template: `
    <Modal title="Import / Export" wide labelledby="ie-title" @close="$emit('close')">
        <div class="tabs" role="tablist">
            <button role="tab" :aria-selected="tab === 'import'" :class="{ active: tab === 'import' }" @click="tab = 'import'">Import</button>
            <button role="tab" :aria-selected="tab === 'export'" :class="{ active: tab === 'export' }" @click="tab = 'export'">Export</button>
        </div>

        <div v-if="tab === 'import'" role="tabpanel">
            <p class="muted">MyAnimeList XML export (anime or manga), Letterboxd CSV (watched, ratings, diary or watchlist), or an MMR JSON/CSV export.</p>
            <div class="form-row">
                <div class="form-group">
                    <label for="ie-file">File</label>
                    <input id="ie-file" type="file" accept=".xml,.gz,.csv,.json" @change="pick" class="file-input">
                </div>
                <div class="form-group narrow">
                    <label for="ie-format">Format</label>
                    <select id="ie-format" v-model="format">
                        <option value="auto">Detect</option>
                        <option value="mal">MyAnimeList XML</option>
                        <option value="letterboxd">Letterboxd CSV</option>
                        <option value="mmr_json">MMR JSON</option>
                        <option value="mmr_csv">MMR CSV</option>
                    </select>
                </div>
            </div>
            <button class="btn-save" @click="runPreview" :disabled="busy || !file">{{ busy && !preview ? 'Reading…' : 'Preview' }}</button>

            <div v-if="preview" class="import-preview">
                <h4>{{ FORMAT_LABELS[preview.format] }} · {{ preview.summary.total }} items</h4>
                <div class="summary-chips">
                    <span class="chip create">{{ preview.summary.create }} new</span>
                    <span class="chip update">{{ preview.summary.update }} to update</span>
                    <span class="chip skip">{{ preview.summary.skip }} skipped</span>
                    <span v-if="preview.summary.duplicates" class="chip skip">{{ preview.summary.duplicates }} duplicates in file</span>
                    <span v-if="preview.summary.errors" class="chip error">{{ preview.summary.errors }} invalid</span>
                </div>
                <div class="form-row">
                    <label class="checkbox-item"><input type="checkbox" v-model="doCreate"> Create new items</label>
                    <label class="checkbox-item"><input type="checkbox" v-model="doUpdate"> Update matched items</label>
                    <label class="checkbox-item"><input type="checkbox" v-model="showAll"> Show unchanged rows</label>
                </div>
                <div class="table-wrap preview-table">
                    <table class="media-table">
                        <thead><tr><th>Line</th><th>Title</th><th>Year</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.line">
                                <td>{{ r.line }}</td>
                                <td>{{ r.title }}<span v-if="r.match_title && r.match_title !== r.title" class="muted"> → {{ r.match_title }}</span></td>
                                <td>{{ r.year || '—' }}</td>
                                <td><span class="chip" :class="r.action">{{ r.action }}</span></td>
                                <td class="muted">{{ r.reason }}<template v-if="r.changes && r.changes.length">: {{ r.changes.join(', ') }}</template></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="preview.rows.length >= 500" class="form-hint">Showing the first 500 rows; the import applies to all {{ preview.summary.total }}.</p>
            </div>
            <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        </div>

        <div v-else role="tabpanel">
            <div class="form-row">
                <div class="form-group">
                    <span class="label">Format</span>
                    <label class="checkbox-item"><input type="radio" value="json" v-model="exportFormat"> JSON (full, re-importable)</label>
                    <label class="checkbox-item"><input type="radio" value="csv" v-model="exportFormat"> CSV (spreadsheets)</label>
                </div>
                <div class="form-group">
                    <span class="label">What</span>
                    <label class="checkbox-item"><input type="radio" value="all" v-model="exportScope"> Everything</label>
                    <label class="checkbox-item"><input type="radio" value="filtered" v-model="exportScope" :disabled="!hasActiveFilters()"> Current filtered view</label>
                </div>
            </div>
        </div>

        <template #footer>
            <button class="btn-cancel" @click="$emit('close')">Close</button>
            <button v-if="tab === 'import' && preview" class="btn-save" @click="commit" :disabled="busy || willApply === 0">
                {{ busy ? 'Importing…' : 'Import ' + willApply + ' item' + (willApply === 1 ? '' : 's') }}</button>
            <a v-if="tab === 'export'" class="btn-save" :href="exportHref" download>Download {{ exportFormat.toUpperCase() }}</a>
        </template>
    </Modal>`,
};

export const PickOneModal = {
    components: { Modal, StatusBadge },
    emits: ['close'],
    setup(props, { emit }) {
        const item = ref(null);
        const busy = ref(false);
        const empty = ref(false);
        const revealed = ref(false);
        const router = VueRouter.useRouter();
        const pick = async () => {
            busy.value = true;
            revealed.value = false;
            try {
                const res = await api('random', { query: { ...filterQuery(filters), limit: 1 } });
                item.value = res.data[0] || null;
                empty.value = !item.value;
            } catch (e) {
                showToast(e.message);
            } finally {
                busy.value = false;
            }
        };
        onMounted(pick);
        const open = () => { emit('close'); router.push(mediaPath(item.value)); };
        return { item, busy, empty, revealed, pick, open, posterUrl, isAdult, formatPublicScore, categoryName, hasActiveFilters };
    },
    template: `
    <Modal title="Pick one for me" labelledby="pick-title" @close="$emit('close')">
        <p class="muted">{{ hasActiveFilters() ? 'A random pick from your current filters.' : 'A random pick from everything — set filters (e.g. Plan to Watch + Movie) to narrow it down.' }}</p>
        <div v-if="empty" class="empty-state">Nothing matches your filters.</div>
        <div v-else-if="item" class="pick-card" aria-live="polite">
            <img :src="posterUrl(item)" alt="" :class="{ 'image-blurred': isAdult(item) && !revealed }" @click="revealed = true">
            <div>
                <h4>{{ item.title }}</h4>
                <p class="muted">{{ categoryName(item) }} · {{ item.release_year || item.year }} · {{ formatPublicScore(item) }}</p>
                <StatusBadge :status="item.status" :category="item.categories" />
            </div>
        </div>
        <template #footer>
            <button class="btn-cancel" @click="pick" :disabled="busy">{{ busy ? 'Picking…' : 'Pick again' }}</button>
            <button class="btn-save" @click="open" :disabled="!item">Open</button>
        </template>
    </Modal>`,
};

/** Add one or many items to a list (or create a new list on the fly). */
export const AddToListModal = {
    components: { Modal },
    props: { items: { type: Array, required: true } },
    emits: ['close'],
    setup(props, { emit }) {
        const newName = ref('');
        const busy = ref(false);
        onMounted(loadLists);
        const add = async (list) => {
            busy.value = true;
            try {
                const r = (await api('list_add_items', { method: 'POST', query: { id: list.id }, body: { media_ids: props.items.map(i => i.id) } })).data;
                showToast(r.added ? `Added ${r.added} to "${list.name}"` : `Already in "${list.name}"`, null, 3000);
                await loadLists();
                bumpMedia();
                emit('close');
            } catch (e) {
                showToast(e.message);
            } finally {
                busy.value = false;
            }
        };
        const createAndAdd = async () => {
            if (!newName.value.trim()) return;
            busy.value = true;
            try {
                const list = (await api('list_create', { method: 'POST', body: { name: newName.value.trim() } })).data;
                await add(list);
            } catch (e) {
                showToast(e.message);
                busy.value = false;
            }
        };
        const label = computed(() => props.items.length === 1 ? `"${props.items[0].title}"` : `${props.items.length} items`);
        return { lookups, newName, busy, add, createAndAdd, label };
    },
    template: `
    <Modal :title="'Add ' + label + ' to a list'" labelledby="atl-title" @close="$emit('close')">
        <ul class="list-picker">
            <li v-for="l in lookups.lists" :key="l.id">
                <button type="button" @click="add(l)" :disabled="busy">
                    <span>{{ l.name }}</span><span class="muted">{{ l.media_count }}<template v-if="l.is_public"> · public</template></span>
                </button>
            </li>
            <li v-if="!lookups.lists.length" class="muted">No lists yet — create one below.</li>
        </ul>
        <form class="input-with-select" @submit.prevent="createAndAdd">
            <input v-model="newName" placeholder="New list name" aria-label="New list name" maxlength="100">
            <button class="btn-save" type="submit" :disabled="busy || !newName.trim()">Create &amp; add</button>
        </form>
    </Modal>`,
};
