import assert from 'node:assert/strict';
import test from 'node:test';
import { expandArgv } from '../src/shortcuts.mjs';

test('expandArgv maps BYO site shortcuts', () => {
  assert.deepEqual(expandArgv(['site']), ['site', 'list']);
  assert.deepEqual(expandArgv(['deploy']), ['deploy']);
});
