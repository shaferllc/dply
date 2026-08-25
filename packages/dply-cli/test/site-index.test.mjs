import assert from 'node:assert/strict';
import test from 'node:test';
import { fetchAllSites, matchSites, normalizeKind } from '../src/site-index.mjs';

/** Minimal ApiClient stand-in: path → rows, or a throw for "no scope". */
function fakeClient(byPath) {
  return {
    async get(path) {
      const value = byPath[path];
      if (value === undefined) {
        throw new Error(`403 for ${path}`);
      }

      return { data: value };
    },
  };
}

const client = () => fakeClient({
  '/sites': [
    { id: 'a'.repeat(26), name: 'acme-web', kind: 'vm', status: 'ready', server_name: 'web-1' },
    { id: 'e'.repeat(26), name: 'acme-api', kind: 'cloud', status: 'running', runtime_mode_label: 'Container' },
  ],
  '/edge/sites': [{ id: 'b'.repeat(26), name: 'docs-site', status: 'live', hostname: 'docs.acme.com' }],
});

test('fetchAllSites unions the endpoints and tags each kind', async () => {
  const rows = await fetchAllSites(client());

  assert.deepEqual(rows.map((r) => `${r.kind}:${r.name}`), [
    'cloud:acme-api',
    'vm:acme-web',
    'edge:docs-site',
  ]);
  assert.equal(rows.find((r) => r.kind === 'edge').status, 'live');
});

test('fetchAllSites survives an endpoint the token cannot read', async () => {
  const partial = fakeClient({ '/sites': [{ id: 'a'.repeat(26), name: 'acme-web', kind: 'vm' }] });
  const rows = await fetchAllSites(partial);

  assert.deepEqual(rows.map((r) => r.kind), ['vm']);
});

test('fetchAllSites lets the specific kind win when a site appears twice', async () => {
  const id = 'd'.repeat(26);
  const dupe = fakeClient({
    '/sites': [{ id, name: 'docs-site', status: 'ready' }],
    '/edge/sites': [{ id, name: 'docs-site', status: 'live' }],
  });

  const rows = await fetchAllSites(dupe);

  assert.equal(rows.length, 1);
  assert.equal(rows[0].kind, 'edge');
});

test('--kind selects one product, including the two /sites serves', async () => {
  assert.deepEqual((await fetchAllSites(client(), { kind: 'cloud' })).map((r) => r.name), ['acme-api']);
  assert.deepEqual((await fetchAllSites(client(), { kind: 'vm' })).map((r) => r.name), ['acme-web']);
  assert.deepEqual((await fetchAllSites(client(), { kind: 'edge' })).map((r) => r.name), ['docs-site']);
});

test('a /sites row without a kind (older instance) reads as vm', async () => {
  const legacy = fakeClient({ '/sites': [{ id: 'f'.repeat(26), name: 'legacy' }] });

  assert.deepEqual((await fetchAllSites(legacy)).map((r) => r.kind), ['vm']);
});

test('normalizeKind accepts the obvious spellings and rejects nonsense', () => {
  assert.equal(normalizeKind('byo'), 'vm');
  assert.equal(normalizeKind('containers'), 'cloud');
  assert.equal(normalizeKind('apps'), 'cloud');
  assert.equal(normalizeKind('EDGE'), 'edge');
  assert.equal(normalizeKind(''), null);
  assert.equal(normalizeKind(undefined), null);
  assert.throws(() => normalizeKind('kubernetes'), /Unknown --kind/);
});

test('matchSites takes an id verbatim and a name loosely', async () => {
  const rows = await fetchAllSites(client());

  assert.deepEqual(matchSites(rows, 'b'.repeat(26)).map((r) => r.name), ['docs-site']);
  assert.deepEqual(matchSites(rows, 'docs').map((r) => r.name), ['docs-site']);
  assert.deepEqual(matchSites(rows, 'site').map((r) => r.name), ['docs-site']);
  assert.deepEqual(matchSites(rows, 'nope'), []);
});
