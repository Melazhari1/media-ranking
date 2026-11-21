import { test } from 'node:test';
import assert from 'node:assert/strict';
import { paginationItems } from '../../js/pagination.js';

const render = (cur, total) =>
    paginationItems(cur, total).map(i => (i.type === 'ellipsis' ? '…' : String(i.page))).join(' ');

test('first page: 1 2 3 … last (not "1 2 3 1487 …")', () => {
    assert.equal(render(1, 1487), '1 2 3 … 1487');
    assert.equal(render(2, 1487), '1 2 3 … 1487');
});

test('middle page: ellipsis on both sides', () => {
    assert.equal(render(42, 1487), '1 … 41 42 43 … 1487');
});

test('last pages', () => {
    assert.equal(render(1487, 1487), '1 … 1485 1486 1487');
    assert.equal(render(1486, 1487), '1 … 1485 1486 1487');
    assert.equal(render(1485, 1487), '1 … 1484 1485 1486 1487');
});

test('a one-page gap shows the page, never "…"', () => {
    assert.equal(render(3, 1487), '1 2 3 4 … 1487');
    assert.equal(render(4, 1487), '1 2 3 4 5 … 1487');
    assert.equal(render(5, 1487), '1 … 4 5 6 … 1487');
    assert.equal(render(1484, 1487), '1 … 1483 1484 1485 1486 1487');
});

test('small totals', () => {
    assert.equal(render(1, 0), '');
    assert.equal(render(1, 1), '1');
    assert.equal(render(1, 2), '1 2');
    assert.equal(render(2, 5), '1 2 3 4 5');
    assert.equal(render(1, 5), '1 2 3 4 5');
    assert.equal(render(1, 6), '1 2 3 … 6');
});

test('out-of-range current page is clamped', () => {
    assert.equal(render(0, 10), '1 2 3 … 10');
    assert.equal(render(99, 10), '1 … 8 9 10');
});

test('keys are unique (Vue v-for)', () => {
    for (const cur of [1, 5, 42, 1487]) {
        const keys = paginationItems(cur, 1487).map(i => i.key);
        assert.equal(new Set(keys).size, keys.length);
    }
});
