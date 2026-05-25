#!/usr/bin/env node
import { run } from '../src/cli.mjs';

run(process.argv.slice(2)).then(
  (code) => process.exit(code ?? 0),
  (err) => {
    const message = err?.message ?? String(err);
    process.stderr.write(`dply: ${message}\n`);
    process.exit(err?.exitCode ?? 1);
  },
);
