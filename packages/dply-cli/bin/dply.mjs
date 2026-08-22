#!/usr/bin/env node
import { run } from '../src/cli.mjs';

// process.exit() discards stdout still buffered in the pipe (payloads over
// ~64KB, e.g. `site deployments --json` with full log_output), truncating
// output mid-string. Drain stdout first, with a timeout so a blocked reader
// can't hang us forever.
function exitAfterFlush(code) {
  if (process.stdout.writableLength > 0) {
    process.stdout.once('drain', () => process.exit(code));
    setTimeout(() => process.exit(code), 2000).unref();
  } else {
    process.exit(code);
  }
}

run(process.argv.slice(2)).then(
  (code) => exitAfterFlush(code ?? 0),
  (err) => {
    const message = err?.message ?? String(err);
    process.stderr.write(`dply: ${message}\n`);

    // A token minted before a scope existed fails with a bare "Forbidden".
    // The interactive shell offers the refresh; one-shot runs need the hint.
    if (err?.status === 403) {
      process.stderr.write('Your token may not carry the scope for that — run `dply auth refresh` to approve more.\n');
    }

    exitAfterFlush(err?.exitCode ?? 1);
  },
);
