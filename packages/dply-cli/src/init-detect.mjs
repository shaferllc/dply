/**
 * The local half of `dply init` — everything it can work out from the folder
 * alone, with no network and no side effects.
 *
 * Detection here only orders a menu and fills in defaults. The authoritative
 * answer comes back from the create endpoint's dry run. Nothing downstream
 * depends on this being right.
 */

/**
 * Folder names too generic to identify a site in a list. `~/work/beta/api`
 * proposes `beta-api` rather than a second row called `api`.
 */
export const GENERIC_BASENAMES = new Set([
  'api', 'app', 'src', 'web', 'www', 'server', 'backend', 'frontend',
  'service', 'functions', 'lambda', 'worker', 'site', 'code', 'main',
]);

/** Never uploaded: rebuilt server-side from the detected build command. */
export const ALWAYS_EXCLUDED = [
  '.git', 'node_modules', 'vendor', '.venv', 'venv', '__pycache__',
  '.next', '.nuxt', '.svelte-kit', '.terraform', '.dply-cache',
];

/**
 * Site name for a folder: the basename, qualified with its parent when the
 * basename is too generic to tell two sites apart.
 *
 * @param {string} cwd absolute path of the folder being initialised
 * @returns {{ name: string, qualified: boolean }}
 */
export function proposeName(cwd) {
  const parts = String(cwd).split(/[/\\]+/).filter(Boolean);
  const base = (parts[parts.length - 1] ?? '').toLowerCase();
  const parent = (parts[parts.length - 2] ?? '').toLowerCase();

  if (! base) {
    return { name: 'function', qualified: false };
  }

  if (GENERIC_BASENAMES.has(base) && parent && ! GENERIC_BASENAMES.has(parent)) {
    return { name: `${slug(parent)}-${slug(base)}`, qualified: true };
  }

  return { name: slug(base), qualified: false };
}

/**
 * @param {string} value
 */
export function slug(value) {
  return String(value)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60) || 'function';
}

/**
 * What deploying this folder would mean for each kind, best fit first.
 *
 * Every kind is always returned — an ineligible one carries a reason instead of
 * disappearing. A menu that hides options looks broken when detection is wrong;
 * one that says why teaches the product model.
 *
 * @param {{ files: string[], packageJson?: Record<string, any> | null }} folder
 * @returns {Array<{ kind: string, fits: boolean, score: number, reason: string }>}
 */
export function rankKinds({ files, packageJson = null }) {
  const has = (name) => files.includes(name);
  const deps = {
    ...(packageJson?.dependencies ?? {}),
    ...(packageJson?.devDependencies ?? {}),
  };
  const dep = (name) => Object.prototype.hasOwnProperty.call(deps, name);

  const hasPhp = has('composer.json');
  const hasPython = has('requirements.txt') || has('pyproject.toml') || has('Pipfile');
  const hasGo = has('go.mod');
  const hasDockerfile = has('Dockerfile') || has('dockerfile');
  const staticGenerator = ['astro', 'next', 'nuxt', 'gatsby', '@11ty/eleventy', 'vite', 'vitepress']
    .some(dep) || has('_config.yml') || has('hugo.toml');
  const hasIndexHtml = has('index.html');
  const serverSide = hasPhp || hasPython || hasGo
    || dep('express') || dep('fastify') || dep('koa') || dep('@nestjs/core');

  const out = [];

  // Edge — static output, or something that builds it.
  if (hasIndexHtml && ! serverSide) {
    out.push({ kind: 'edge', fits: true, score: 4, reason: 'index.html at the root, no server-side code' });
  } else if (staticGenerator) {
    out.push({ kind: 'edge', fits: true, score: 3, reason: 'a static site generator is in the dependencies' });
  } else {
    out.push({ kind: 'edge', fits: false, score: 0, reason: 'no static build output or site generator found' });
  }

  // Cloud — a container image comes from a Dockerfile.
  out.push(hasDockerfile
    ? { kind: 'cloud', fits: true, score: 3, reason: 'a Dockerfile is present' }
    : { kind: 'cloud', fits: false, score: 1, reason: 'no Dockerfile — a container app is built from one' });

  // A server you own runs anything, so this never carries a reason not to.
  out.push({ kind: 'vm', fits: true, score: 1, reason: 'runs on a server you own' });

  const order = ['edge', 'cloud', 'vm'];

  return out.sort((a, b) => (b.score - a.score) || (order.indexOf(a.kind) - order.indexOf(b.kind)));
}

/**
 * Turn raw git facts into the one thing init needs to decide: does the folder
 * on screen match what dply would deploy?
 *
 * @param {{ hasRemote: boolean, hasUpstream: boolean, dirtyFiles: number, aheadCommits: number }} state
 * @returns {{ code: string, deployable: 'git' | 'upload', summary: string }}
 */
export function classifyGitState({ hasRemote, hasUpstream, dirtyFiles, aheadCommits }) {
  if (! hasRemote) {
    return {
      code: 'no-remote',
      deployable: 'upload',
      summary: 'no git remote — this folder will be uploaded as-is',
    };
  }

  if (! hasUpstream) {
    return {
      code: 'no-upstream',
      deployable: 'git',
      summary: 'this branch has never been pushed, so there is nothing on the remote to deploy',
    };
  }

  if (aheadCommits > 0 && dirtyFiles > 0) {
    return {
      code: 'ahead-and-dirty',
      deployable: 'git',
      summary: `${aheadCommits} unpushed commit${aheadCommits === 1 ? '' : 's'} and ${dirtyFiles} uncommitted file${dirtyFiles === 1 ? '' : 's'}`,
    };
  }

  if (aheadCommits > 0) {
    return {
      code: 'ahead',
      deployable: 'git',
      summary: `${aheadCommits} commit${aheadCommits === 1 ? '' : 's'} not pushed`,
    };
  }

  if (dirtyFiles > 0) {
    return {
      code: 'dirty',
      deployable: 'git',
      summary: `${dirtyFiles} uncommitted file${dirtyFiles === 1 ? '' : 's'}`,
    };
  }

  return { code: 'clean', deployable: 'git', summary: 'up to date with the remote' };
}

/**
 * Drop paths that should never be uploaded, plus anything the caller excluded.
 *
 * `git ls-files -co --exclude-standard` already honours .gitignore, so this is
 * the belt for folders that are not repositories — and the escape hatch for
 * tracked-but-huge paths that .gitignore cannot help with.
 *
 * @param {string[]} paths
 * @param {string[]} [extraExcludes]
 * @returns {string[]}
 */
export function filterUploadPaths(paths, extraExcludes = []) {
  const excluded = [...ALWAYS_EXCLUDED, ...extraExcludes.map((e) => e.replace(/^\.\//, '').replace(/\/+$/, ''))];

  return paths.filter((path) => {
    const clean = path.replace(/^\.\//, '');

    // .env never rides the archive: it goes to the encrypted column instead.
    if (clean === '.env' || clean.startsWith('.env.')) {
      return false;
    }

    return ! excluded.some((ex) => clean === ex || clean.startsWith(`${ex}/`));
  });
}

/**
 * @param {number} bytes
 */
export function humanBytes(bytes) {
  if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
  if (bytes >= 1024 ** 2) return `${Math.round(bytes / 1024 ** 2)} MB`;
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;

  return `${bytes} B`;
}

/**
 * Key names only — values must never reach a terminal, a log, or an argv.
 *
 * @param {string} contents
 * @returns {string[]}
 */
export function envKeyNames(contents) {
  return String(contents)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '' && ! line.startsWith('#'))
    .map((line) => line.replace(/^export\s+/, '').split('=')[0].trim())
    .filter((key) => /^[A-Za-z_][A-Za-z0-9_]*$/.test(key));
}

/**
 * The `kind:` a repository declares in its dply manifest.
 *
 * Deliberately a dumb line-scan rather than a YAML parser: this only reads one
 * top-level scalar to pre-answer a menu, the server validates the manifest
 * properly on every deploy, and adding a YAML dependency to a package that has
 * had none for its whole life is a poor trade for that.
 *
 * @param {string} contents
 * @returns {string|null}
 */
export function manifestKind(contents) {
  for (const line of String(contents).split(/\r?\n/)) {
    // Top-level only: an indented `kind:` belongs to some nested block.
    if (/^\s/.test(line) || line.trim().startsWith('#')) {
      continue;
    }

    const match = /^kind\s*:\s*(.+?)\s*(?:#.*)?$/.exec(line);
    if (! match) {
      continue;
    }

    const value = match[1].replace(/^["']|["']$/g, '').trim().toLowerCase();

    return ['vm', 'cloud', 'edge'].includes(value) ? value : null;
  }

  return null;
}
