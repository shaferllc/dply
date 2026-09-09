import assert from 'node:assert/strict';
import test from 'node:test';
import { __testing } from '../src/uptime-commands.mjs';

const { isDown, percent, promptAllowed, parseInterval, watchRequested } = __testing;

test('only a checked-and-failing monitor counts as down', () => {
  assert.equal(isDown({ status: 'down' }), true);
  assert.equal(isDown({ status: 'up' }), false);
  // Never probed is not an outage — it must not fail a CI gate.
  assert.equal(isDown({ status: 'unchecked' }), false);
  assert.equal(isDown({}), false);
});

test('percent renders two decimals and keeps "no data" distinct from 0%', () => {
  assert.equal(percent(100), '100.00%');
  assert.equal(percent(99.5), '99.50%');
  assert.equal(percent(0), '0.00%');
  assert.equal(percent(null), '—');
  assert.equal(percent(undefined), '—');
});

test('watch interval is clamped to something a prober can serve', () => {
  assert.equal(parseInterval(undefined), 15000);
  assert.equal(parseInterval('1000'), 5000);
  assert.equal(parseInterval('999999'), 300000);
  assert.equal(parseInterval('30000'), 30000);
});

test('watch accepts the same spellings as the other streams', () => {
  assert.equal(watchRequested({ watch: true }), true);
  assert.equal(watchRequested({ follow: true }), true);
  assert.equal(watchRequested({ tail: true }), true);
  assert.equal(watchRequested({}), false);
});

test('prompting stays off for scripts and machine output', () => {
  assert.equal(promptAllowed({}), false);
  assert.equal(promptAllowed({ json: true }), false);
  assert.equal(promptAllowed({ 'no-prompt': true }), false);
});
