// Small shared components: status badge, pagination, confirm dialog, toast host, icons.
import { paginationItems } from '../pagination.js?v=2.1.0';
import { statusGroup, statusLabel } from '../status.js?v=2.1.0';
import { toast, dismissToast, pauseToast, resumeToast, confirmState, settleConfirm } from '../store.js?v=2.1.0';

const { computed, ref, watch, nextTick } = Vue;

export const ICONS = {
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="m13.5 6.5 4 4"/>',
    trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    chevronLeft: '<path d="m15 18-6-6 6-6"/>',
    chevronRight: '<path d="m9 18 6-6-6-6"/>',
    chevronDown: '<path d="m6 9 6 6 6-6"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>',
    eyeOff: '<path d="m2 2 20 20"/><path d="M10.58 10.58a2 2 0 0 0 2.83 2.83"/><path d="M16.68 16.68A9.77 9.77 0 0 1 12 18c-7 0-11-6-11-6a18.4 18.4 0 0 1 4.32-4.32M7.5 5.68A9.77 9.77 0 0 1 12 5c7 0 11 6 11 6a18.4 18.4 0 0 1-2.06 2.61"/>',
    dice: '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 8h.01M16 8h.01M12 12h.01M8 16h.01M16 16h.01"/>',
    upload: '<path d="M12 16V4"/><path d="m6 10 6-6 6 6"/><path d="M4 20h16"/>',
    check: '<path d="m5 12 5 5 9-10"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    bookmark: '<path d="M6 3h12v18l-6-4-6 4V3z"/>',
    star: '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z"/>',
    repeat: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>',
    chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    home: '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    select: '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m8 12 3 3 5-6"/>',
};

export const Icon = {
    props: { name: { type: String, required: true }, size: { type: Number, default: 18 } },
    template: `<svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" v-html="paths"></svg>`,
    computed: { paths() { return ICONS[this.name] || ''; } },
};

/** Coloured-dot status pill. Text uses text tokens; the dot carries the status colour. */
export const StatusBadge = {
    props: { status: { type: String, default: null }, category: { type: String, default: null }, compact: Boolean },
    setup(props) {
        const group = computed(() => statusGroup(props.status));
        const label = computed(() => statusLabel(props.status, props.category));
        return { group, label };
    },
    template: `<span class="status-badge" :class="['st-' + group, { compact }]"><i class="status-dot" aria-hidden="true"></i>{{ label }}</span>`,
};

export const Pagination = {
    props: { page: { type: Number, required: true }, pages: { type: Number, required: true } },
    emits: ['change'],
    setup(props) {
        const items = computed(() => paginationItems(props.page, props.pages));
        return { items };
    },
    template: `
    <nav v-if="pages > 1" class="pagination" aria-label="Pagination">
        <button class="page-link" :disabled="page === 1" @click="$emit('change', page - 1)">&laquo; Previous</button>
        <div class="page-numbers">
            <template v-for="p in items" :key="p.key">
                <span v-if="p.type === 'ellipsis'" class="page-ellipsis" aria-hidden="true">…</span>
                <button v-else class="page-link" :class="{ active: p.page === page }"
                    :aria-current="p.page === page ? 'page' : null" :aria-label="'Page ' + p.page"
                    @click="$emit('change', p.page)">{{ p.page }}</button>
            </template>
        </div>
        <button class="page-link" :disabled="page === pages" @click="$emit('change', page + 1)">Next &raquo;</button>
    </nav>`,
};

export const ConfirmHost = {
    setup() {
        const cancelBtn = ref(null);
        watch(() => confirmState.current, (c) => { if (c) nextTick(() => cancelBtn.value?.focus()); });
        const onKey = (e) => { if (e.key === 'Escape') settleConfirm(false); };
        return { confirmState, settleConfirm, cancelBtn, onKey };
    },
    template: `
    <div v-if="confirmState.current" class="modal-overlay" @click.self="settleConfirm(false)" @keydown="onKey">
        <div class="modal-content confirm-dialog" role="alertdialog" aria-modal="true"
            aria-labelledby="confirm-title" aria-describedby="confirm-message">
            <div class="modal-header">
                <h3 id="confirm-title">{{ confirmState.current.title }}</h3>
                <button class="close-btn" @click="settleConfirm(false)" aria-label="Close">×</button>
            </div>
            <div class="modal-body"><p id="confirm-message">{{ confirmState.current.message }}</p></div>
            <div class="modal-footer">
                <button ref="cancelBtn" class="btn-cancel" @click="settleConfirm(false)">{{ confirmState.current.cancelLabel || 'Cancel' }}</button>
                <button :class="confirmState.current.danger ? 'btn-danger' : 'btn-save'" @click="settleConfirm(true)">
                    {{ confirmState.current.confirmLabel }}</button>
            </div>
        </div>
    </div>`,
};

export const ToastHost = {
    setup() {
        const run = async () => {
            const action = toast.current?.action;
            dismissToast();
            if (action) await action.run();
        };
        return { toast, run, dismissToast, pauseToast, resumeToast };
    },
    template: `
    <div class="toast-region" role="status" aria-live="polite">
        <div v-if="toast.current" class="toast" @mouseenter="pauseToast" @mouseleave="resumeToast"
            @focusin="pauseToast" @focusout="resumeToast">
            <span class="toast-message">{{ toast.current.message }}</span>
            <button v-if="toast.current.action" class="toast-action" @click="run">{{ toast.current.action.label }}</button>
            <button class="toast-close" @click="dismissToast" aria-label="Dismiss">×</button>
        </div>
    </div>`,
};

/** Accessible modal shell: Esc closes, focus moves into the dialog, focus returns on close. */
export const Modal = {
    props: { title: String, wide: Boolean, labelledby: { type: String, default: 'modal-title' } },
    emits: ['close'],
    setup(props, { emit }) {
        const root = ref(null);
        let previouslyFocused = null;
        Vue.onMounted(() => {
            previouslyFocused = document.activeElement;
            nextTick(() => root.value?.querySelector('input, select, textarea, button:not(.close-btn)')?.focus());
        });
        Vue.onBeforeUnmount(() => previouslyFocused?.focus?.());
        const onKey = (e) => { if (e.key === 'Escape') emit('close'); };
        return { root, onKey };
    },
    template: `
    <div class="modal-overlay" @click.self="$emit('close')" @keydown="onKey">
        <div ref="root" class="modal-content" :class="{ 'modal-wide': wide }" role="dialog" aria-modal="true" :aria-labelledby="labelledby">
            <div class="modal-header">
                <h3 :id="labelledby">{{ title }}</h3>
                <button class="close-btn" @click="$emit('close')" aria-label="Close">×</button>
            </div>
            <div class="modal-body"><slot /></div>
            <div v-if="$slots.footer" class="modal-footer"><slot name="footer" /></div>
        </div>
    </div>`,
};
