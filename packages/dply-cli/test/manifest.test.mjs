import assert from 'node:assert/strict';
import test from 'node:test';
import { TOP_LEVEL } from '../src/cli.mjs';
import { readFileSync } from 'node:fs';
import { COMMANDS, MULTI_TOKEN_SHORTCUTS, canonicalNameFor, knownCommandNames, subcommandsFor } from '../src/manifest.mjs';
import { SUBCOMMAND_NAMES as UPTIME_SUBCOMMANDS } from '../src/uptime-commands.mjs';

// Reached by explicit branches in run() rather than through the TOP_LEVEL
// table. Kept in step with BRANCHED in src/manifest.mjs: a command listed there
// but dispatched nowhere is exactly the `dply guide` bug this file exists for.
const DISPATCHED_OUTSIDE_TOP_LEVEL = new Set(['edge', 'help', 'ls']);

test('every dispatched command is in the manifest', () => {
  const known = new Set(knownCommandNames());
  const missing = Object.keys(TOP_LEVEL).filter((name) => !known.has(name));

  assert.deepEqual(missing, [], `shipped but unadvertised: ${missing.join(', ')}`);
});

test('every manifest command is really dispatched', () => {
  const orphans = COMMANDS
    .map((c) => c.id)
    .filter((id) => !(id in TOP_LEVEL) && !DISPATCHED_OUTSIDE_TOP_LEVEL.has(id));

  // Catches the actual failure this file exists for: advertising a command that
  // does not exist. Both `browse` and `bus` were listed here on the first pass,
  // carried over from a different CLI, and this test is what caught them.
  assert.deepEqual(orphans, [], `advertised but not dispatched: ${orphans.join(', ')}`);
});

test('a spelling resolves to a canonical id, or to nothing', () => {
  // `monitor` and `notify` are their own TOP_LEVEL keys rather than aliases -
  // they dispatch directly - so they are canonical in their own right. The
  // aliases here are the single-token shortcuts (`r` for refresh, `me` for
  // whoami), read from the shortcut table rather than restated.
  assert.equal(canonicalNameFor('uptime'), 'uptime');
  assert.equal(canonicalNameFor('monitor'), 'monitor');
  assert.equal(canonicalNameFor('r'), 'refresh');
  assert.equal(canonicalNameFor('me'), 'whoami');
  assert.equal(canonicalNameFor('not-a-command'), null);
});

test('every alias expands to a command that exists', () => {
  const ids = new Set(COMMANDS.map((c) => c.id));

  for (const { id, aliases } of COMMANDS) {
    for (const alias of aliases) {
      assert.ok(ids.has(id), `${alias} expands to ${id}, which is not dispatched`);
    }
  }
});

test('subcommand lists match the modules that own them', () => {
  // uptime is the module that already exported its own list; the manifest must
  // not drift from it.
  const manifestUptime = subcommandsFor('uptime');
  if (manifestUptime.length > 0) {
    for (const name of manifestUptime) {
      assert.ok(UPTIME_SUBCOMMANDS.includes(name), `uptime subcommand ${name} is not dispatched`);
    }
  }
});

test('site artisan is advertised', () => {
  // The command that started this: shipped in one place, absent from the other.
  assert.ok(subcommandsFor('site').includes('artisan'));
  assert.ok(subcommandsFor('server').includes('run'));
});

test('commands.json is in step with the manifest module', () => {
  // The PHP catalog test reads the JSON, because it cannot import an .mjs.
  // A stale file would let the two sides agree with each other and not reality.
  const onDisk = JSON.parse(readFileSync(new URL('../commands.json', import.meta.url), 'utf8'));

  assert.deepEqual(
    onDisk.commands,
    JSON.parse(JSON.stringify(COMMANDS)),
    'run `npm run manifest` after changing src/manifest.mjs',
  );
  assert.deepEqual(onDisk.shortcuts, MULTI_TOKEN_SHORTCUTS, 'run `npm run manifest`');
});
