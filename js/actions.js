// Item actions shared by cards, table rows and the detail page.
import { api } from './api.js?v=2.1.0';
import { showToast, confirmDialog, bumpMedia } from './store.js?v=2.1.0';
import { nextQuickStatus, statusLabel, statusOptionsFor } from './status.js?v=2.1.0';
import { unitPlural } from './format.js?v=2.1.0';

const fail = (e) => showToast(e?.message || 'Something went wrong');

export async function setStatus(item, status) {
    try {
        const res = await api('update_status', { method: 'POST', query: { id: item.id }, body: { status } });
        Object.assign(item, res.data || { status });
        bumpMedia();
        return true;
    } catch (e) {
        fail(e);
        return false;
    }
}

export const cycleStatus = (item) => setStatus(item, nextQuickStatus(item));

export async function saveScore(item, score) {
    try {
        await api('update_score', { method: 'POST', query: { id: item.id }, body: { score } });
        item.score = score;
        bumpMedia();
    } catch (e) {
        fail(e);
    }
}

/** +1 progress; when the total is reached, offer to mark it Watched/Read (sets finished_at). */
export async function progressPlusOne(item, by = 1) {
    try {
        const res = await api('progress', { method: 'POST', query: { id: item.id }, body: { by } });
        Object.assign(item, res.data);
        bumpMedia();
        if (res.reached_total) {
            const done = statusOptionsFor(item.categories)[2].value;
            const ok = await confirmDialog({
                title: `Mark as ${done}?`,
                message: `You reached the last of ${item.progress_total} ${unitPlural(item.progress_unit)} of "${item.title}". Mark it as ${done} and set today as the finish date?`,
                confirmLabel: `Mark as ${done}`,
                cancelLabel: 'Not yet',
            });
            if (ok) await completeItem(item);
        }
    } catch (e) {
        fail(e);
    }
}

export async function completeItem(item) {
    try {
        const res = await api('complete', { method: 'POST', query: { id: item.id }, body: {} });
        Object.assign(item, res.data);
        bumpMedia();
        showToast(`"${item.title}" marked as ${statusLabel(item.status, item.categories)}`, null, 4000);
    } catch (e) {
        fail(e);
    }
}

/** Confirm, soft-delete, and offer Undo. Resolves true if deleted. */
export async function deleteWithUndo(item, onChange = () => {}) {
    const ok = await confirmDialog({
        title: 'Delete media?',
        message: `"${item.title}" will be removed from your list. You can undo this right after.`,
        confirmLabel: 'Delete',
        danger: true,
    });
    if (!ok) return false;
    try {
        await api('', { method: 'DELETE', query: { id: item.id } });
        bumpMedia();
        onChange();
        showToast(`Deleted "${item.title}"`, {
            label: 'Undo',
            run: async () => {
                try {
                    await api('restore', { method: 'POST', query: { id: item.id } });
                    showToast(`Restored "${item.title}"`, null, 3000);
                    bumpMedia();
                    onChange();
                } catch (e) {
                    fail(e);
                }
            },
        });
        return true;
    } catch (e) {
        fail(e);
        return false;
    }
}
