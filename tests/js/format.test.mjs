import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatPublicScore, categoryName } from '../../js/format.js';

test('public score label per source', () => {
    assert.equal(formatPublicScore({ public_score: '6.70', score_source: 'mal' }), 'MAL 6.7 ★');
    assert.equal(formatPublicScore({ public_score: 7.4, score_source: 'tmdb' }), 'TMDB 7.4 ★');
    assert.equal(formatPublicScore({ public_score: '8.75', score_source: 'imdb' }), 'IMDb 8.75 ★');
    assert.equal(formatPublicScore({ public_score: 5, score_source: 'manual' }), 'Score 5 ★');
});

test('no score shows a dash, never 0', () => {
    for (const v of [null, undefined, '', 0, '0', '0.00']) {
        assert.equal(formatPublicScore({ public_score: v, score_source: 'mal' }), '—');
    }
});

test('missing category falls back to Unknown', () => {
    assert.equal(categoryName({ categories: null }), 'Unknown');
    assert.equal(categoryName({ categories: '  ' }), 'Unknown');
    assert.equal(categoryName({ categories: 'TV' }), 'TV');
});
