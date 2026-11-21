import { test } from 'node:test';
import assert from 'node:assert/strict';
import { statusGroup, statusLabel, statusOptionsFor, statusForCategory, nextQuickStatus } from '../../js/status.js';
import { progressLabel, progressPct, slugify, mediaPath } from '../../js/format.js';

test('groups drive colours', () => {
    assert.equal(statusGroup('Plan to Read'), 'plan');
    assert.equal(statusGroup('Reading'), 'active');
    assert.equal(statusGroup('Watched'), 'done');
    assert.equal(statusGroup('On Hold'), 'on_hold');
    assert.equal(statusGroup(null), 'none');
});

test('category-aware wording', () => {
    assert.deepEqual(statusOptionsFor('Manga').map(o => o.value).slice(0, 3), ['Plan to Read', 'Reading', 'Read']);
    assert.deepEqual(statusOptionsFor('Movie').map(o => o.value).slice(0, 3), ['Plan to Watch', 'Watching', 'Watched']);
    assert.equal(statusForCategory('Watching', 'Light Novel'), 'Reading');
    assert.equal(statusForCategory('Read', 'TV'), 'Watched');
    assert.equal(statusForCategory('Dropped', 'Book'), 'Dropped');
    assert.equal(statusLabel('Rewatching', 'Book'), 'Rereading');
    assert.equal(statusLabel(null), 'Not in list');
});

test('badge quick cycle keeps the legacy behaviour', () => {
    assert.equal(nextQuickStatus({ status: null, categories: 'TV' }), 'Plan to Watch');
    assert.equal(nextQuickStatus({ status: 'Plan to Read', categories: 'Book' }), 'Read');
    assert.equal(nextQuickStatus({ status: 'Watched', categories: 'TV' }), null);
    assert.equal(nextQuickStatus({ status: 'Watching', categories: 'TV' }), 'Watched');
});

test('progress label and percentage', () => {
    assert.equal(progressLabel({ progress_current: 7, progress_total: 24, progress_unit: 'episode' }), 'Ep 7 / 24');
    assert.equal(progressLabel({ progress_current: 12, progress_total: null, progress_unit: 'chapter' }), 'Ch 12');
    assert.equal(progressLabel({ progress_current: null, progress_total: null }), '');
    assert.equal(progressPct({ progress_current: 7, progress_total: 24 }), 29);
    assert.equal(progressPct({ progress_current: 5, progress_total: null }), null);
});

test('detail URLs', () => {
    assert.equal(slugify('𝐓𝐡𝐞 Black Phone: 2!'), 'the-black-phone-2');
    assert.equal(mediaPath({ id: 12, title: 'Sōsō no Frieren' }), '/media/12-soso-no-frieren');
    assert.equal(slugify(''), 'media');
});
