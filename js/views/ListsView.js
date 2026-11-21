// /lists — create, rename, describe, publish, reorder and delete custom lists.
import { api } from '../api.js?v=2.1.0';
import { Icon } from '../components/common.js?v=2.1.0';
import { lookups, loadLists, showToast, confirmDialog, bumpMedia } from '../store.js?v=2.1.0';

const { ref, reactive, onMounted } = Vue;

export const ListsView = {
    components: { Icon },
    setup() {
        const newName = ref('');
        const editing = reactive({});
        const busy = ref(false);

        onMounted(() => { loadLists(); document.title = 'Lists · MMR'; });

        const create = async () => {
            if (!newName.value.trim()) return;
            busy.value = true;
            try {
                await api('list_create', { method: 'POST', body: { name: newName.value.trim() } });
                newName.value = '';
                await loadLists();
            } catch (e) {
                showToast(e.message);
            } finally {
                busy.value = false;
            }
        };
        const startEdit = (l) => { editing[l.id] = { name: l.name, description: l.description || '' }; };
        const cancelEdit = (l) => { delete editing[l.id]; };
        const saveEdit = async (l) => {
            try {
                await api('list_update', { method: 'POST', query: { id: l.id }, body: editing[l.id] });
                delete editing[l.id];
                await loadLists();
            } catch (e) {
                showToast(e.message);
            }
        };
        const togglePublic = async (l) => {
            try {
                await api('list_update', { method: 'POST', query: { id: l.id }, body: { is_public: !l.is_public } });
                await loadLists();
            } catch (e) {
                showToast(e.message);
            }
        };
        const move = async (index, dir) => {
            const ids = lookups.lists.map(l => l.id);
            const j = index + dir;
            if (j < 0 || j >= ids.length) return;
            [ids[index], ids[j]] = [ids[j], ids[index]];
            lookups.lists = ids.map(id => lookups.lists.find(l => l.id === id)); // optimistic
            try {
                await api('list_reorder', { method: 'POST', body: { ids } });
            } catch (e) {
                showToast(e.message);
                loadLists();
            }
        };
        const remove = async (l) => {
            const ok = await confirmDialog({
                title: `Delete list "${l.name}"?`,
                message: `The list will be deleted. The ${l.media_count} item${l.media_count === 1 ? '' : 's'} in it stay in your library. This cannot be undone.`,
                confirmLabel: 'Delete list',
                danger: true,
            });
            if (!ok) return;
            try {
                await api('list_delete', { method: 'POST', query: { id: l.id } });
                await loadLists();
                bumpMedia();
            } catch (e) {
                showToast(e.message);
            }
        };
        return { lookups, newName, editing, busy, create, startEdit, cancelEdit, saveEdit, togglePublic, move, remove };
    },
    template: `
    <div class="lists-page">
        <div class="page-header"><div class="page-title"><h2>My lists</h2><span class="muted">{{ lookups.lists.length }}</span></div></div>
        <form class="input-with-select list-create" @submit.prevent="create">
            <input v-model="newName" placeholder="New list name (e.g. Halloween marathon)" aria-label="New list name" maxlength="100">
            <button class="btn-save" type="submit" :disabled="busy || !newName.trim()"><Icon name="plus" :size="16" /> Create</button>
        </form>
        <p v-if="!lookups.lists.length" class="empty-state muted">No lists yet. Create one, then add items from a card (bookmark icon), the detail page, or bulk selection.</p>
        <ol class="lists-manage">
            <li v-for="(l, i) in lookups.lists" :key="l.id" class="list-row">
                <div class="list-order">
                    <button class="icon-btn small" @click="move(i, -1)" :disabled="i === 0" :aria-label="'Move ' + l.name + ' up'">▲</button>
                    <button class="icon-btn small" @click="move(i, 1)" :disabled="i === lookups.lists.length - 1" :aria-label="'Move ' + l.name + ' down'">▼</button>
                </div>
                <div v-if="editing[l.id]" class="list-edit">
                    <input v-model="editing[l.id].name" :aria-label="'Name of ' + l.name" maxlength="100">
                    <textarea v-model="editing[l.id].description" rows="2" placeholder="Description (optional)" aria-label="Description"></textarea>
                    <div class="row-actions">
                        <button class="btn-save" @click="saveEdit(l)">Save</button>
                        <button class="btn-cancel" @click="cancelEdit(l)">Cancel</button>
                    </div>
                </div>
                <div v-else class="list-info">
                    <router-link :to="{ path: '/', query: { list: l.id, sort: 'list_position' } }" class="list-name">{{ l.name }}</router-link>
                    <span class="muted">{{ l.media_count }} item{{ l.media_count === 1 ? '' : 's' }}</span>
                    <p v-if="l.description" class="muted small">{{ l.description }}</p>
                </div>
                <div class="row-actions">
                    <label class="switch" :title="l.is_public ? 'Shown on your public profile' : 'Private'">
                        <input type="checkbox" :checked="l.is_public" @change="togglePublic(l)"> Public
                    </label>
                    <button v-if="!editing[l.id]" class="icon-btn" @click="startEdit(l)" :aria-label="'Rename ' + l.name"><Icon name="edit" :size="16" /></button>
                    <button class="icon-btn danger" @click="remove(l)" :aria-label="'Delete list ' + l.name"><Icon name="trash" :size="16" /></button>
                </div>
            </li>
        </ol>
    </div>`,
};
