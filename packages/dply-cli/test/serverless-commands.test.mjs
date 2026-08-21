import assert from 'node:assert/strict';
import test from 'node:test';
import { SERVERLESS_SUBCOMMANDS, invocationQuery, queryString, windowSeconds } from '../src/serverless-commands.mjs';

test('queryString drops empty values and encodes the rest', () => {
  assert.equal(queryString({}), '');
  assert.equal(queryString({ a: '', b: null, c: undefined, d: false }), '');
  assert.equal(queryString({ level: 'error', limit: '10' }), 'level=error&limit=10');
  assert.equal(queryString({ since: '2026-08-21T00:00:00Z' }), 'since=2026-08-21T00%3A00%3A00Z');
});

test('queryString renders boolean true as 1', () => {
  assert.equal(queryString({ failed: true }), 'failed=1');
});

test('invocationQuery returns an empty string when no filters are set', () => {
  assert.equal(invocationQuery({}), '');
  assert.equal(invocationQuery({ json: true }), '');
});

test('invocationQuery maps only the filters the endpoint understands', () => {
  assert.equal(invocationQuery({ failed: true }), '?failed=1');
  assert.equal(invocationQuery({ failed: true, source: 'web', limit: 5 }), '?failed=1&source=web&limit=5');
  assert.equal(invocationQuery({ source: 'tick' }), '?source=tick');
});

test('windowSeconds defaults to an hour and clamps to a week', () => {
  assert.equal(windowSeconds({}), 3600);
  assert.equal(windowSeconds({ window: '120' }), 120);
  assert.equal(windowSeconds({ window: '0' }), 3600);
  assert.equal(windowSeconds({ window: '99999999' }), 604800);
});

test('the documented subcommand list matches what the dispatcher accepts', () => {
  assert.deepEqual(SERVERLESS_SUBCOMMANDS, [
    'list',
    'status',
    'invocations',
    'errors',
    'logs',
    'invocation',
  ]);
});
