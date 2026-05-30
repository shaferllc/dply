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
const SERVER_SUBCOMMANDS = new Set(['list', 'system-users', 'help', '--help', '-h']);

/** Single-token shortcuts → argv prefix (rest appended when present). */
const SINGLE_TOKEN = {
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
  const key = first.toLowerCase();

  if (rest.length === 0 && SINGLE_TOKEN[key]) {
    return [...SINGLE_TOKEN[key]];
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

  if (key === 'account' && rest.length === 0) {
    return ['account', 'show'];
  }

  return argv;
}
