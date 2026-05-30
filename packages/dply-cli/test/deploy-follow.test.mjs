import assert from 'node:assert/strict';
import test from 'node:test';
import { deployFollowRequested, nextLogChunk } from '../src/deploy-follow.mjs';

test('nextLogChunk streams only new log bytes', () => {
  assert.equal(nextLogChunk('hello', 0), 'hello');
  assert.equal(nextLogChunk('hello', 3), 'lo');
  assert.equal(nextLogChunk('hello', 5), '');
  assert.equal(nextLogChunk('', 0), '');
});

test('deployFollowRequested accepts follow and wait aliases', () => {
  assert.equal(deployFollowRequested({ follow: true }), true);
  assert.equal(deployFollowRequested({ wait: true }), true);
  assert.equal(deployFollowRequested({ w: true }), true);
  assert.equal(deployFollowRequested({}), false);
});

test('edge terminal deploy statuses include live and failed', async () => {
  const { TERMINAL_EDGE_DEPLOY_STATUSES } = await import('../src/deploy-follow.mjs');

  assert.equal(TERMINAL_EDGE_DEPLOY_STATUSES.has('live'), true);
  assert.equal(TERMINAL_EDGE_DEPLOY_STATUSES.has('failed'), true);
  assert.equal(TERMINAL_EDGE_DEPLOY_STATUSES.has('building'), false);
});
