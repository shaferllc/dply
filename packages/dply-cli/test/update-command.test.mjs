import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isCurrent, linkedCheckoutPath, localVersion } from '../src/update-command.mjs';
import { readFile } from 'node:fs/promises';

test('localVersion reports the version actually installed', async () => {
  const pkg = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'));

  assert.equal(await localVersion(), pkg.version);
});

test('`dply --version` does not drift from package.json', async () => {
  // The literal that used to live in cli.mjs is exactly the failure mode
  // `dply update` would hide: it compares the reported version against the
  // instance, so a stale literal reports a false match forever.
  const src = await readFile(new URL('../src/cli.mjs', import.meta.url), 'utf8');
  const versionBranch = src.slice(src.indexOf("argv[0] === '--version'"), src.indexOf("argv[0] === '--version'") + 400);

  assert.match(versionBranch, /localVersion\(\)/);
  assert.doesNotMatch(versionBranch, /dply CLI \d+\.\d+\.\d+/);
});

test('a stamped build wins over the hand-maintained version', () => {
  // The whole point: package.json's version never moves when a command lands,
  // so comparing it would report "up to date" forever.
  assert.equal(isCurrent({ version: '0.1.0', build: 'aaa' }, { version: '0.1.0', build: 'bbb' }), false);
  assert.equal(isCurrent({ version: '0.1.0', build: 'aaa' }, { version: '0.9.9', build: 'aaa' }), true);
});

test('falls back to version when either side has no build stamp', () => {
  assert.equal(isCurrent({ version: '0.1.0', build: null }, { version: '0.1.0', build: 'bbb' }), true);
  assert.equal(isCurrent({ version: '0.1.0', build: 'aaa' }, { version: '0.2.0' }), false);
});

test('a linked source checkout is detected', async () => {
  // This test file only exists in the repo — the packed tarball ships
  // bin/src/package.json/README.md, so its presence IS the signal.
  const root = await linkedCheckoutPath();

  assert.ok(root, 'running from the checkout should report a path');
  assert.match(root, /dply-cli$/);
});
