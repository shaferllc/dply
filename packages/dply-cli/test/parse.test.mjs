import assert from 'node:assert/strict';
import test from 'node:test';
import { parse } from '../src/cli.mjs';

test('parse keeps everything after `--` as positionals', () => {
  const { args, flags } = parse(['--site', 'shop', '--', 'migrate', '--force', '--step=1']);

  assert.deepEqual(args, ['migrate', '--force', '--step=1']);
  assert.equal(flags.site, 'shop');
  assert.equal(flags.force, undefined);
});

test('parse still reads flags with no `--` present', () => {
  const { args, flags } = parse(['artisan', '--site', 'shop', '--json']);

  assert.deepEqual(args, ['artisan']);
  assert.equal(flags.site, 'shop');
  assert.equal(flags.json, true);
});
