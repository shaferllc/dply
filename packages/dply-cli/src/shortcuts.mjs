/** @type {Set<string>} */
const PROJECT_SUBCOMMANDS = new Set([
  'list',
  'ls',
  'show',
  'create',
  'update',
  'delete',
  'rm',
  'health',
  'deploy',
  'deploys',
  'members',
  'attach',
  'detach',
  'environments',
  'envs',
  'variables',
  'vars',
  'runbooks',
  'help',
  '--help',
  '-h',
]);

/** @type {Set<string>} */
const SERVER_SUBCOMMANDS = new Set([
  'list',
  'show',
  'health',
  'run',
  'firewall',
  'system-users',
  'help',
  '--help',
  '-h',
]);

/** @type {Set<string>} */
const SITE_SUBCOMMANDS = new Set([
  'list',
  'ls',
  'show',
  'deploy',
  'status',
  'logs',
  'deployments',
  'deploys',
  'deployment',
  'env',
  'errors',
  'help',
  '--help',
  '-h',
]);

/** Single-token shortcuts → argv prefix (rest appended when present). */
export const SINGLE_TOKEN_SHORTCUTS = {
  r: ['refresh'],
  refresh: ['refresh'],
  projects: ['project', 'list'],
  projs: ['project', 'list'],
  p: ['project', 'list'],
  create: ['project', 'create'],
  new: ['project', 'create'],
  site: ['site', 'list'],
  deploy: ['deploy'],
  servers: ['server', 'list'],
  sv: ['server', 'list'],
  server: ['server', 'list'],
  sites: ['sites'],
  me: ['whoami'],
  who: ['whoami'],
  orgs: ['account', 'orgs'],
  bill: ['billing', 'show'],
  billing: ['billing', 'show'],
  login: ['login'],
  logout: ['logout'],
  menu: ['menu'],
  m: ['menu'],
};

/**
 * Extra lines for tab completion (shell + `dply ls shortcuts`).
 *
 * @returns {string[]}
 */
export function shortcutCommandLines() {
  return [
    'projects',
    'projs',
    'p',
    'servers',
    'sv',
    'me',
    'who',
    'orgs',
    'bill',
    'create',
    'new',
    'site',
    'deploy',
    'sites:errors',
    'sites:uptime',
    'sites:notifications',
    'sites:uptime:history',
    'site:errors',
    'site:logs',
    'site:deploy',
  ];
}

/**
 * Expand friendly shortcuts into canonical argv before routing.
 *
 * @param {string[]} argv
 * @returns {string[]}
 */
export function expandArgv(argv) {
  if (argv.length === 0) {
    return argv;
  }

  const [first, ...rest] = argv;

  // `dply sites:errors acme` — a colon is just a space. One rule here means
  // every route that already exists gets a `ns:sub` form for free.
  if (first.includes(':') && ! first.startsWith('-')) {
    return expandArgv([...first.split(':').filter(Boolean), ...rest]);
  }

  const key = first.toLowerCase();

  if (rest.length === 0 && SINGLE_TOKEN_SHORTCUTS[key]) {
    return [...SINGLE_TOKEN_SHORTCUTS[key]];
  }

  if (key === 'billing' && rest.length > 0) {
    return ['billing', ...rest];
  }

  if ((key === 'projects' || key === 'projs' || key === 'p') && rest.length > 0) {
    const [sub, ...tail] = rest;
    const subKey = sub.toLowerCase();

    if (subKey === 'create' || subKey === 'new') {
      return ['project', 'create', ...tail];
    }

    if (subKey === 'list' || subKey === 'ls') {
      return ['project', 'list', ...tail];
    }

    if (!PROJECT_SUBCOMMANDS.has(subKey) && !sub.startsWith('-')) {
      return ['project', 'show', sub, ...tail];
    }

    return ['project', ...rest];
  }

  if ((key === 'servers' || key === 'sv') && rest.length > 0) {
    const [sub, ...tail] = rest;
    if (!SERVER_SUBCOMMANDS.has(sub.toLowerCase()) && !sub.startsWith('-')) {
      return ['server', 'system-users', 'list', '--server', sub, ...tail];
    }

    return ['server', ...rest];
  }

  if (key === 'project' && rest.length === 1 && !PROJECT_SUBCOMMANDS.has(rest[0].toLowerCase()) && !rest[0].startsWith('-')) {
    return ['project', 'show', rest[0]];
  }

  if ((key === 'site' || key === 'sites') && rest.length > 0) {
    const [sub, ...tail] = rest;

    // `errors` and `uptime` are top-level (they serve every kind of site), so
    // `site errors` / `sites:uptime` route there rather than dying in the site
    // switch. `sites:uptime:history` arrives here as `sites uptime history`.
    const subKey = sub.toLowerCase();

    if (subKey === 'errors' || subKey === 'uptime' || subKey === 'monitor' || subKey === 'notifications') {
      return [subKey === 'monitor' ? 'uptime' : subKey, ...tail];
    }

    // `sites` is the universal list; only a real verb means the BYO namespace.
    // `dply sites checkout` stays on `sites` and filters the list by name.
    if (key === 'sites' && SITE_SUBCOMMANDS.has(subKey)) {
      return ['site', ...rest];
    }
  }

  if (key === 'account' && rest.length === 0) {
    return ['account', 'show'];
  }

  return argv;
}
