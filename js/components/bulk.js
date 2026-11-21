// Sticky bulk-action bar (selection mode). One batch endpoint for every operation.
import { api } from '../api.js?v=2.1.0';
import { Icon, Modal } from './common.js?v=2.1.0';
import { TagInput } from './forms.js?v=2.1.0';
import { lookups, ui, confirmDialog, showToast, bumpMedia, loadTags } from '../store.js?v=2.1.0';
import { STATUSES } from '../status.js?v=2.1.0';

const { ref, computed } = Vue;

export const BulkBar = {
    components: { Icon, Modal, TagInput },
    props: { items: { type: Array, required: true } },
    emits: ['done'],
    setup(props, { emit }) {
        const busy = ref(false);
        const tagModal = ref(false);
        const tags = ref([]);
        const ids = computed(() => props.items.map(i => i.id));
        const n = computed(() => props.items.length);
        // Watch/Read wording is adapted per item on the server, so offer one entry per group.
        const LABELS = { 'Plan to Watch': 'Plan to Watch / Read', Watching: 'Watching / Reading', Watched: 'Watched / Read', Rewatching: 'Rewatching / Rereading' };
        const statusChoices = STATUSES.filter(s => !['Plan to Read', 'Reading', 'Read'].includes(s.value)).map(s => ({ value: s.value, label: LABELS[s.value] || s.value }));

        const run = async (op, value, message) => {
            busy.value = true;
            try {
                const res = (await api('batch', { method: 'POST', body: { ids: ids.value, op, value } })).data;
                bumpMedia();
                return res;
            } catch (e) {
                showToast(e.message);
                return null;
            } finally {
                busy.value = false;
                if (message) showToast(message, null, 3500);
            }
        };

        const onSelect = async (e, op, label) => {
            const value = e.target.value;
            e.target.value = '';
            if (value === '') return;
            const res = await run(op, value === 'null' ? null : value);
            if (res) showToast(`${label} updated for ${res.affected} item${res.affected === 1 ? '' : 's'}`, null, 3500);
        };

        const addTags = async () => {
            if (!tags.value.length) return;
            const res = await run('add_tags', tags.value);
            if (res) showToast(`Tagged ${res.affected} items`, null, 3500);
            tagModal.value = false;
            tags.value = [];
            loadTags();
        };

        const remove = async () => {
            const ok = await confirmDialog({
                title: `Delete ${n.value} item${n.value === 1 ? '' : 's'}?`,
                message: `${n.value} item${n.value === 1 ? '' : 's'} will be removed from your list. You can undo this right after.`,
                confirmLabel: `Delete ${n.value}`,
                danger: true,
            });
            if (!ok) return;
            const deletedIds = [...ids.value];
            const res = await run('delete', null);
            if (!res) return;
            emit('done');
            showToast(`Deleted ${res.affected} items`, {
                label: 'Undo',
                run: async () => {
                    try {
                        await api('batch', { method: 'POST', body: { ids: deletedIds, op: 'restore' } });
                        bumpMedia();
                        showToast('Restored', null, 3000);
                    } catch (e) {
                        showToast(e.message);
                    }
                },
            });
        };

        return { busy, tagModal, tags, n, lookups, ui, statusChoices, onSelect, addTags, remove };
    },
    template: `
    <div class="bulk-bar" role="region" aria-label="Bulk actions">
        <strong class="bulk-count">{{ n }} selected</strong>
        <select @change="onSelect($event, 'set_status', 'Status')" :disabled="busy" aria-label="Set status">
            <option value="">Status…</option>
            <option value="null">Not in list</option>
            <option v-for="s in statusChoices" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <select @change="onSelect($event, 'set_category', 'Category')" :disabled="busy" aria-label="Set category">
            <option value="">Category…</option>
            <option v-for="c in lookups.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <select @change="onSelect($event, 'set_rating', 'Rating')" :disabled="busy" aria-label="Set age rating">
            <option value="">Rating…</option>
            <option v-for="r in lookups.ratings" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
        <button class="btn-chip" @click="ui.addToList = items" :disabled="busy"><Icon name="bookmark" :size="16" /> Add to list</button>
        <button class="btn-chip" @click="tagModal = true" :disabled="busy">#&nbsp;Add tags</button>
        <button class="btn-chip danger" @click="remove" :disabled="busy"><Icon name="trash" :size="16" /> Delete</button>

        <Modal v-if="tagModal" :title="'Add tags to ' + n + ' items'" labelledby="bulk-tags-title" @close="tagModal = false">
            <TagInput v-model="tags" input-id="bulk-tags" />
            <template #footer>
                <button class="btn-cancel" @click="tagModal = false">Cancel</button>
                <button class="btn-save" @click="addTags" :disabled="!tags.length || busy">Add tags</button>
            </template>
        </Modal>
    </div>`,
};
