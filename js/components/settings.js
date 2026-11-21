// Settings: opt-in public profile.
import { api } from '../api.js?v=2.1.0';
import { Modal } from './common.js?v=2.1.0';
import { showToast } from '../store.js?v=2.1.0';

const { ref, reactive, computed, onMounted } = Vue;

export const SettingsModal = {
    components: { Modal },
    emits: ['close'],
    setup(props, { emit }) {
        const s = reactive({ public_enabled: false, username: '', display_name: '', public_show_adult: false, public_show_notes: false });
        const loading = ref(true);
        const busy = ref(false);
        const error = ref('');
        onMounted(async () => {
            try {
                Object.assign(s, (await api('settings')).data);
            } catch (e) {
                error.value = e.message;
            } finally {
                loading.value = false;
            }
        });
        const profileUrl = computed(() => s.username ? new URL('u/' + encodeURIComponent(s.username), document.baseURI).href : '');
        const save = async () => {
            busy.value = true;
            error.value = '';
            try {
                Object.assign(s, (await api('settings_save', { method: 'POST', body: { ...s } })).data);
                showToast('Settings saved', null, 3000);
                emit('close');
            } catch (e) {
                error.value = e.message;
            } finally {
                busy.value = false;
            }
        };
        const copy = async () => {
            try {
                await navigator.clipboard.writeText(profileUrl.value);
                showToast('Link copied', null, 2500);
            } catch {
                showToast('Could not copy — select the link instead.');
            }
        };
        return { s, loading, busy, error, profileUrl, save, copy };
    },
    template: `
    <Modal title="Settings" labelledby="settings-title" @close="$emit('close')">
        <div v-if="loading" class="loading-container"><div class="spinner small"></div></div>
        <form v-else class="media-form" @submit.prevent="save">
            <fieldset class="form-fieldset">
                <legend>Public profile</legend>
                <label class="checkbox-item"><input type="checkbox" v-model="s.public_enabled"> Enable a public, read-only profile page</label>
                <div class="form-row">
                    <div class="form-group">
                        <label for="st-user">Username</label>
                        <input id="st-user" v-model="s.username" pattern="[A-Za-z0-9_-]{3,30}" maxlength="30" placeholder="e.g. melazhari" autocomplete="off">
                        <p class="form-hint">3–30 letters, digits, - or _.</p>
                    </div>
                    <div class="form-group">
                        <label for="st-name">Display name</label>
                        <input id="st-name" v-model="s.display_name" maxlength="60">
                    </div>
                </div>
                <label class="checkbox-item"><input type="checkbox" v-model="s.public_show_adult"> Include adult (Rx) items</label>
                <label class="checkbox-item"><input type="checkbox" v-model="s.public_show_notes"> Show my notes</label>
                <p class="form-hint">The profile shows your stats, top 10 and lists marked public. Notes and adult items stay hidden unless enabled above.</p>
                <p v-if="s.public_enabled && profileUrl" class="form-hint">
                    <a :href="profileUrl" target="_blank" rel="noopener">{{ profileUrl }}</a>
                    <button type="button" class="link-btn" @click="copy">Copy</button>
                </p>
                <p class="form-hint warn">If the site is behind a password (basic auth), allow <code>/u/</code> without it — see README "Public profile".</p>
            </fieldset>
            <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        </form>
        <template #footer>
            <button class="btn-cancel" @click="$emit('close')">Cancel</button>
            <button class="btn-save" @click="save" :disabled="busy || loading">{{ busy ? 'Saving…' : 'Save' }}</button>
        </template>
    </Modal>`,
};
