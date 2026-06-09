import assert from 'node:assert/strict';
import test from 'node:test';
import { expandArgv } from '../src/shortcuts.mjs';
import { normalizeShellLine } from '../src/shell.mjs';
import { matchMenuItemByText } from '../src/menus.mjs';

/** @type {import('../src/menus.mjs').MenuItem[]} */
const accountItems = [
  { label: 'Show profile', argv: ['account', 'show'], keywords: ['profile', 'me', 'who', 'whoami', 'show'] },
  { label: 'Organizations', argv: ['account', 'orgs'], keywords: ['orgs', 'organizations'] },
  { label: 'Projects', argv: ['account', 'projects'], keywords: ['projects'] },
  { label: 'Refresh permissions', argv: ['auth', 'refresh'], keywords: ['refresh', 'r', 'auth'] },
];

test('expandArgv maps friendly shortcuts', () => {
  assert.deepEqual(expandArgv(['projects']), ['project', 'list']);
  assert.deepEqual(expandArgv(['p']), ['project', 'list']);
  assert.deepEqual(expandArgv(['r']), ['refresh']);
  assert.deepEqual(expandArgv(['me']), ['whoami']);
  assert.deepEqual(expandArgv(['servers']), ['server', 'list']);
  assert.deepEqual(expandArgv(['account']), ['account', 'show']);
});

test('expandArgv resolves project slug shorthands', () => {
  assert.deepEqual(expandArgv(['projects', 'acme']), ['project', 'show', 'acme']);
  assert.deepEqual(expandArgv(['projects', 'create', '--name', 'Demo']), ['project', 'create', '--name', 'Demo']);
  assert.deepEqual(expandArgv(['project', 'acme']), ['project', 'show', 'acme']);
});

test('normalizeShellLine strips pasted dply prefix', () => {
  assert.equal(normalizeShellLine('dply auth refresh'), 'auth refresh');
  assert.equal(normalizeShellLine('DPLY projects'), 'projects');
  assert.equal(normalizeShellLine('  dply  '), '');
  assert.equal(normalizeShellLine('projects'), 'projects');
});

test('matchMenuItemByText resolves shortcuts in menus', () => {
  assert.equal(matchMenuItemByText('projects', accountItems)?.label, 'Projects');
  assert.equal(matchMenuItemByText('me', accountItems)?.label, 'Show profile');
  assert.equal(matchMenuItemByText('r', accountItems)?.label, 'Refresh permissions');
  assert.equal(matchMenuItemByText('orgs', accountItems)?.label, 'Organizations');
});
