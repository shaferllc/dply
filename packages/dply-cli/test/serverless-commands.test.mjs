import assert from 'node:assert/strict';
import test from 'node:test';
import { parse } from '../src/cli.mjs';
import { __testing, SERVERLESS_SUBCOMMANDS, invocationQuery, queryString, windowSeconds } from '../src/serverless-commands.mjs';

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
    'platform',
    'invoke',
    'credentials',
    'workers',
    'schedule',
    'env',
    'runtime',
  ]);
});

test('excerpt collapses whitespace and trims to one table cell', () => {
  const { excerpt } = __testing;

  assert.equal(excerpt(''), '—');
  assert.equal(excerpt(null), '—');
  assert.equal(excerpt(' a\n  b '), 'a b');
  assert.equal(excerpt('x'.repeat(80)), `${'x'.repeat(59)}\u2026`);
});

test('parseHeaders turns repeatable --header flags into an object', () => {
  const { parseHeaders } = __testing;

  assert.deepEqual(parseHeaders('X-Token: abc'), { 'X-Token': 'abc' });
  assert.deepEqual(parseHeaders(['A: 1', 'B: 2']), { A: '1', B: '2' });
  // A value containing a colon keeps it; a malformed entry is dropped.
  assert.deepEqual(parseHeaders('Referer: https://a.test/x'), { Referer: 'https://a.test/x' });
  assert.deepEqual(parseHeaders('nonsense'), {});
  assert.deepEqual(parseHeaders(undefined), {});
});

test('runtimePatch sends only the fields the flags name', () => {
  const { runtimePatch } = __testing;

  assert.deepEqual(runtimePatch({}, {}), {});
  assert.deepEqual(runtimePatch({ memory: '512' }, {}), { memory_mb: 512 });
  assert.deepEqual(runtimePatch({ secure: true }, {}), { secured: true });
  assert.deepEqual(runtimePatch({ unsecure: true }, {}), { secured: false });
  assert.deepEqual(runtimePatch({ maintenance: 'off' }, {}), { maintenance: false });
  assert.deepEqual(
    runtimePatch({ cors: 'on', 'cors-origins': 'https://a.test, https://b.test' }, {}),
    { cors: { enabled: true, allow_origins: ['https://a.test', 'https://b.test'] } },
  );
});

test('runtimePatch merges --param into the stored map and honours --rm-param', () => {
  const { runtimePatch } = __testing;
  const current = { parameters: { KEEP: '1', DROP: '2' } };

  // The endpoint replaces the map whole, so a partial edit has to read first.
  assert.deepEqual(
    runtimePatch({ param: ['NEW=3'], 'rm-param': 'DROP' }, current),
    { parameters: { KEEP: '1', NEW: '3' } },
  );
});

test('onOff accepts the usual spellings and rejects the rest', () => {
  const { onOff } = __testing;

  assert.equal(onOff(true, 'x'), true);
  assert.equal(onOff('on', 'x'), true);
  assert.equal(onOff('false', 'x'), false);
  assert.throws(() => onOff('maybe', 'x'), /takes on or off/);
});

test('repeated flags collect into an array', () => {
  // `--param A=1 --param B=2` used to keep only B.
  assert.deepEqual(parse(['--param', 'A=1', '--param', 'B=2']).flags.param, ['A=1', 'B=2']);
  assert.deepEqual(parse(['--header', 'A: 1']).flags.header, 'A: 1');
  assert.deepEqual(parse(['--json']).flags.json, true);
});
