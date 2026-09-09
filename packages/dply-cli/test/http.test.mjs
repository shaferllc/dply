import assert from 'node:assert/strict';
import os from 'node:os';
import path from 'node:path';
import { describe, it } from 'node:test';
import { isLocalDevHost, localCaCandidatePaths } from '../src/http.mjs';

describe('isLocalDevHost', () => {
  it('matches common local control-plane hosts', () => {
    assert.equal(isLocalDevHost('dply.test'), true);
    assert.equal(isLocalDevHost('foo.bar.test'), true);
    assert.equal(isLocalDevHost('localhost'), true);
    assert.equal(isLocalDevHost('127.0.0.1'), true);
    assert.equal(isLocalDevHost('::1'), true);
    assert.equal(isLocalDevHost('[::1]'), true);
  });

  it('rejects production hosts', () => {
    assert.equal(isLocalDevHost('dply.io'), false);
    assert.equal(isLocalDevHost('app.dply.host'), false);
    assert.equal(isLocalDevHost('example.com'), false);
  });
});

describe('localCaCandidatePaths', () => {
  it('includes Dply Local CA path under the home directory', () => {
    const paths = localCaCandidatePaths();
    assert.ok(paths.includes(path.join(os.homedir(), '.dpl', 'certs', 'ca.pem')));
  });
});
