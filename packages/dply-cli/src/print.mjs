const useColor = process.stdout.isTTY && process.env.NO_COLOR == null;

const codes = {
  reset: 0,
  bold: 1,
  dim: 2,
  red: 31,
  green: 32,
  yellow: 33,
  blue: 34,
  magenta: 35,
  cyan: 36,
};

function wrap(text, code) {
  if (!useColor) return text;

  return `\x1b[${code}m${text}\x1b[0m`;
}

export const c = {
  bold: (t) => wrap(t, codes.bold),
  dim: (t) => wrap(t, codes.dim),
  red: (t) => wrap(t, codes.red),
  green: (t) => wrap(t, codes.green),
  yellow: (t) => wrap(t, codes.yellow),
  blue: (t) => wrap(t, codes.blue),
  cyan: (t) => wrap(t, codes.cyan),
};

/**
 * Print a simple two-column key/value table to stdout.
 *
 * @param {Array<[string, string]>} pairs
 */
export function printKeyValues(pairs) {
  const width = Math.max(...pairs.map(([k]) => k.length));
  for (const [k, v] of pairs) {
    process.stdout.write(`${c.dim(k.padEnd(width))}  ${v ?? c.dim('—')}\n`);
  }
}

/**
 * Print a list of records as a compact table.
 *
 * Rows may be objects keyed by column name, or positional arrays matching
 * `columns` left-to-right.
 *
 * @param {string[]} columns
 * @param {Array<Record<string, unknown> | unknown[]>} rows
 */
export function printTable(columns, rows) {
  if (rows.length === 0) {
    process.stdout.write(c.dim('(no rows)\n'));

    return;
  }

  const normalized = rows.map((row) => {
    if (Array.isArray(row)) {
      return Object.fromEntries(columns.map((col, i) => [col, row[i] ?? '—']));
    }

    return row;
  });

  const widths = columns.map((col) => Math.max(col.length, ...normalized.map((r) => String(r[col] ?? '—').length)));
  const header = columns.map((col, i) => c.bold(col.padEnd(widths[i]))).join('  ');
  process.stdout.write(`${header}\n`);
  process.stdout.write(c.dim(columns.map((_, i) => '─'.repeat(widths[i])).join('  ')) + '\n');
  for (const row of normalized) {
    const line = columns.map((col, i) => String(row[col] ?? '—').padEnd(widths[i])).join('  ');
    process.stdout.write(`${line}\n`);
  }
}

export function printJson(value) {
  process.stdout.write(`${JSON.stringify(value, null, 2)}\n`);
}

export function info(msg) {
  process.stdout.write(`${msg}\n`);
}

export function ok(msg) {
  process.stdout.write(`${c.green('✓')} ${msg}\n`);
}

export function warn(msg) {
  process.stderr.write(`${c.yellow('!')} ${msg}\n`);
}
