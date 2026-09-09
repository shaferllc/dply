import assert from 'node:assert/strict';
import test from 'node:test';
import { parse } from '../src/cli.mjs';

test('parse keeps everything after `--` as the remote command', () => {
  const { args, flags } = parse(['--site', 'shop', '--', 'migrate', '--force', '--step=1']);

  // Out of args AND out of flag parsing: the remote command keeps its own
  // flags, and this CLI never mistakes one for its own.
  assert.deepEqual(flags['--'], ['migrate', '--force', '--step=1']);
  assert.deepEqual(args, []);
  assert.equal(flags.site, 'shop');
  assert.equal(flags.force, undefined);
});

test('parse keeps our own positionals separable from the remote command', () => {
  const { args, flags } = parse(['shop', '--', 'migrate']);

  // `dply site artisan shop -- migrate` is a site plus a command; merging the
  // two makes them unrecoverable, and "shop" is a syntactically valid verb.
  assert.deepEqual(args, ['shop']);
  assert.deepEqual(flags['--'], ['migrate']);
});

test('parse reports no remote command when there is no `--`', () => {
  const { args, flags } = parse(['migrate']);

  assert.deepEqual(args, ['migrate']);
  assert.equal(flags['--'], undefined);
});

test('parse still reads flags with no `--` present', () => {
  const { args, flags } = parse(['artisan', '--site', 'shop', '--json']);

  assert.deepEqual(args, ['artisan']);
  assert.equal(flags.site, 'shop');
  assert.equal(flags.json, true);
});
