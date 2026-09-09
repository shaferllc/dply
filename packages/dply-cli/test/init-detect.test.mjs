import assert from 'node:assert/strict';
import test from 'node:test';
import {
  classifyGitState,
  envKeyNames,
  filterUploadPaths,
  humanBytes,
  manifestKind,
  proposeName,
  rankKinds,
  slug,
} from '../src/init-detect.mjs';

test('proposeName uses the folder name when it identifies the project', () => {
  assert.deepEqual(proposeName('/Users/me/work/acme-invoices'), { name: 'acme-invoices', qualified: false });
  assert.deepEqual(proposeName('/Users/me/work/Checkout Service'), { name: 'checkout-service', qualified: false });
});

test('proposeName qualifies a generic folder name with its parent', () => {
  // Two projects both containing an `api/` folder must not both propose `api`.
  assert.deepEqual(proposeName('/Users/me/work/beta/api'), { name: 'beta-api', qualified: true });
  assert.deepEqual(proposeName('/Users/me/work/acme/backend'), { name: 'acme-backend', qualified: true });
});

test('proposeName does not qualify when the parent is generic too', () => {
  assert.deepEqual(proposeName('/srv/app/api'), { name: 'api', qualified: false });
});

test('slug never returns an empty name', () => {
  assert.equal(slug('!!!'), 'function');
  assert.equal(slug(''), 'function');
});

test('rankKinds always returns every kind, fitting or not', () => {
  const ranked = rankKinds({ files: [] });

  assert.deepEqual(ranked.map((r) => r.kind).sort(), ['cloud', 'edge', 'vm']);
});

test('rankKinds gives an ineligible kind a reason rather than hiding it', () => {
  const ranked = rankKinds({ files: ['README.md'] });
  const edge = ranked.find((r) => r.kind === 'edge');

  assert.equal(edge.fits, false);
  assert.match(edge.reason, /no static build output/);
});

test('rankKinds puts a static folder on edge, ahead of the rest', () => {
  const ranked = rankKinds({ files: ['index.html', 'style.css'] });

  assert.equal(ranked[0].kind, 'edge');
  assert.equal(ranked[0].fits, true);
});

test('rankKinds does not call a PHP project static just because it has an index.html', () => {
  const ranked = rankKinds({ files: ['index.html', 'composer.json'] });
  const edge = ranked.find((r) => r.kind === 'edge');

  assert.equal(edge.fits, false);
  assert.equal(ranked.find((r) => r.kind === 'vm').fits, true);
});

test('rankKinds treats a Dockerfile as the thing that makes cloud possible', () => {
  const withDocker = rankKinds({ files: ['Dockerfile', 'package.json'] }).find((r) => r.kind === 'cloud');
  const without = rankKinds({ files: ['package.json'] }).find((r) => r.kind === 'cloud');

  assert.equal(withDocker.fits, true);
  assert.equal(without.fits, false);
  assert.match(without.reason, /Dockerfile/);
});

test('rankKinds recognises a static generator from dependencies', () => {
  const ranked = rankKinds({
    files: ['package.json'],
    packageJson: { dependencies: { astro: '^4.0.0' } },
  });

  assert.equal(ranked.find((r) => r.kind === 'edge').fits, true);
});

test('a server you own is always an option', () => {
  for (const files of [[], ['index.html'], ['Dockerfile']]) {
    assert.equal(rankKinds({ files }).find((r) => r.kind === 'vm').fits, true);
  }
});

test('classifyGitState sends a folder with no remote to upload', () => {
  const state = classifyGitState({ hasRemote: false, hasUpstream: false, dirtyFiles: 0, aheadCommits: 0 });

  assert.equal(state.code, 'no-remote');
  assert.equal(state.deployable, 'upload');
});

test('classifyGitState flags an unpushed branch as having nothing to deploy', () => {
  const state = classifyGitState({ hasRemote: true, hasUpstream: false, dirtyFiles: 0, aheadCommits: 0 });

  assert.equal(state.code, 'no-upstream');
  assert.match(state.summary, /never been pushed/);
});

test('classifyGitState separates unpushed commits from uncommitted files', () => {
  assert.equal(classifyGitState({ hasRemote: true, hasUpstream: true, dirtyFiles: 0, aheadCommits: 2 }).code, 'ahead');
  assert.equal(classifyGitState({ hasRemote: true, hasUpstream: true, dirtyFiles: 3, aheadCommits: 0 }).code, 'dirty');
  assert.equal(classifyGitState({ hasRemote: true, hasUpstream: true, dirtyFiles: 3, aheadCommits: 2 }).code, 'ahead-and-dirty');
});

test('classifyGitState pluralises honestly', () => {
  assert.match(classifyGitState({ hasRemote: true, hasUpstream: true, dirtyFiles: 0, aheadCommits: 1 }).summary, /1 commit not pushed/);
  assert.match(classifyGitState({ hasRemote: true, hasUpstream: true, dirtyFiles: 0, aheadCommits: 4 }).summary, /4 commits not pushed/);
});

test('classifyGitState calls a clean tree clean', () => {
  assert.equal(classifyGitState({ hasRemote: true, hasUpstream: true, dirtyFiles: 0, aheadCommits: 0 }).code, 'clean');
});

test('filterUploadPaths drops dependency directories the build regenerates', () => {
  const kept = filterUploadPaths([
    'src/index.js',
    'node_modules/left-pad/index.js',
    'vendor/autoload.php',
    '.git/config',
    '.venv/bin/python',
    'package.json',
  ]);

  assert.deepEqual(kept, ['src/index.js', 'package.json']);
});

test('filterUploadPaths never uploads a .env — secrets go to the encrypted column', () => {
  const kept = filterUploadPaths(['.env', '.env.local', '.env.production', 'app.js']);

  assert.deepEqual(kept, ['app.js']);
});

test('filterUploadPaths honours an explicit --exclude', () => {
  const kept = filterUploadPaths(['fixtures/big.bin', 'app.js'], ['fixtures']);

  assert.deepEqual(kept, ['app.js']);
});

test('filterUploadPaths does not exclude a path that merely starts with the same letters', () => {
  const kept = filterUploadPaths(['vendors/ours.js', 'vendor/theirs.php'], []);

  assert.deepEqual(kept, ['vendors/ours.js']);
});

test('envKeyNames returns names only, never values', () => {
  const keys = envKeyNames([
    '# a comment',
    'APP_KEY=base64:hunter2',
    'export DB_PASSWORD=swordfish',
    '',
    'STRIPE_SECRET = sk_live_abc',
    'not a line',
  ].join('\n'));

  assert.deepEqual(keys, ['APP_KEY', 'DB_PASSWORD', 'STRIPE_SECRET']);
  assert.equal(keys.join(' ').includes('hunter2'), false);
  assert.equal(keys.join(' ').includes('swordfish'), false);
});

test('humanBytes rounds to something a person can read', () => {
  assert.equal(humanBytes(512), '512 B');
  assert.equal(humanBytes(2048), '2 KB');
  assert.equal(humanBytes(5 * 1024 * 1024), '5 MB');
  assert.equal(humanBytes(3 * 1024 ** 3), '3.0 GB');
});

test('manifestKind reads a declared kind from a dply manifest', () => {
  assert.equal(manifestKind('kind: cloud\nruntime: php\n'), 'cloud');
  assert.equal(manifestKind('runtime: php\nkind: cloud\n'), 'cloud');
  assert.equal(manifestKind('kind: "edge"'), 'edge');
  assert.equal(manifestKind("kind: 'vm'  # a server we own"), 'vm');
});

test('manifestKind ignores a kind that is not top-level', () => {
  // An indented `kind:` belongs to some nested block, not to the site.
  assert.equal(manifestKind('processes:\n  - kind: cloud\n'), null);
});

test('manifestKind ignores comments and unknown values', () => {
  assert.equal(manifestKind('# kind: cloud\nruntime: php'), null);
  assert.equal(manifestKind('kind: serverless'), null);
  assert.equal(manifestKind('kind: lambda'), null);
  assert.equal(manifestKind('runtime: php'), null);
  assert.equal(manifestKind(''), null);
});
