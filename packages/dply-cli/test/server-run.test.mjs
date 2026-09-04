import assert from 'node:assert/strict';
import test from 'node:test';
import { __testing } from '../src/server-commands.mjs';

const { printServerCommandRun, waitForServerCommand } = __testing;

function fakeClient(responses, fallback = null) {
  const calls = [];
  const queue = [...responses];

  return {
    calls,
    get: async (path) => {
      calls.push({ method: 'GET', path });
      const next = queue.shift() ?? fallback;
      if (!next) {
        throw new Error(`unexpected GET ${path}`);
      }

      return next;
    },
  };
}

const RUN = (over = {}) => ({
  run_id: '01ABC',
  status: 'completed',
  exit_code: 0,
  output: 'up 3 days',
  message: 'Command completed.',
  error: null,
  ...over,
});

test('polls a queued run until it settles', async () => {
  const client = fakeClient([RUN({ status: 'running', exit_code: null, output: '' }), RUN()]);

  const run = await waitForServerCommand(client, 'srv1', '01ABC', { interval: 1, timeout: 5 });

  assert.equal(run.status, 'completed');
  assert.equal(client.calls[0].path, '/servers/srv1/commands/01ABC');
});

test('stops waiting at the deadline and leaves the run going', async () => {
  const client = fakeClient([], RUN({ status: 'running', exit_code: null, output: '' }));

  const run = await waitForServerCommand(client, 'srv1', '01ABC', { interval: 1, timeout: 0.02 });

  assert.equal(run.status, 'running');
});

test('propagates a non-zero exit code', () => {
  assert.equal(printServerCommandRun(RUN({ exit_code: 2 }), {}), 2);
});

test('a completed run with exit 0 returns 0', () => {
  assert.equal(printServerCommandRun(RUN(), {}), 0);
});

test('a failed run reports 1 and never leaks transport detail', () => {
  // The API sends a normalized message; the CLI must not invent one either.
  const run = RUN({ status: 'failed', exit_code: null, output: '', error: 'The command could not be run on this server.' });

  assert.equal(printServerCommandRun(run, {}), 1);
});

test('a still-queued run is not reported as a failure', () => {
  assert.equal(printServerCommandRun(RUN({ status: 'queued', exit_code: null, output: '' }), {}), 0);
});
