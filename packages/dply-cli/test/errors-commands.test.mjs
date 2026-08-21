import assert from 'node:assert/strict';
import test from 'node:test';
import { __testing } from '../src/errors-commands.mjs';

const { filterEvents, parseLimit, parseInterval, truncate, watchRequested } = __testing;

const events = [
  { id: 'a', category: 'deploy', title: 'Deploy failed' },
  { id: 'b', category: 'ssl', title: 'Certificate expired' },
  { id: 'c', category: 'SSL', title: 'Renewal pending' },
];

test('filterEvents returns everything when no --category is given', () => {
  assert.equal(filterEvents(events, {}).length, 3);
  assert.equal(filterEvents(events, { category: '  ' }).length, 3);
});

test('filterEvents matches categories case-insensitively and accepts a list', () => {
  assert.deepEqual(filterEvents(events, { category: 'ssl' }).map((e) => e.id), ['b', 'c']);
  assert.deepEqual(filterEvents(events, { category: 'deploy,ssl' }).map((e) => e.id), ['a', 'b', 'c']);
  assert.deepEqual(filterEvents(events, { category: 'nope' }), []);
});

test('parseLimit defaults to 20 and rejects out-of-range values', () => {
  assert.equal(parseLimit(undefined), 20);
  assert.equal(parseLimit('5'), 5);
  assert.equal(parseLimit(50), 50);
  assert.throws(() => parseLimit('0'), /positive integer/);
  assert.throws(() => parseLimit('-3'), /positive integer/);
  assert.throws(() => parseLimit('51'), /cannot exceed 50/);
});

test('parseInterval clamps to a sane polling range', () => {
  assert.equal(parseInterval(undefined), 5000);
  assert.equal(parseInterval('100'), 1000);
  assert.equal(parseInterval('999999'), 300000);
  assert.equal(parseInterval('7500'), 7500);
});

test('truncate collapses whitespace and ellipsizes', () => {
  assert.equal(truncate('a\n  b   c', 40), 'a b c');
  assert.equal(truncate('x'.repeat(50), 10), `${'x'.repeat(9)}…`);
});

test('watchRequested accepts --watch and the existing --follow/--tail spellings', () => {
  assert.equal(watchRequested({}), false);
  assert.equal(watchRequested({ watch: true }), true);
  assert.equal(watchRequested({ follow: true }), true);
  assert.equal(watchRequested({ tail: true }), true);
});
