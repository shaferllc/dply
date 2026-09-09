/**
 * `dply init` — stand in a folder, answer as little as possible, end on a live
 * URL.
 *
 * The shape of this flow, and why each branch exists, is written up in
 * docs/adr/cli-init-and-site-creation.md. Two things are worth knowing before
 * reading it:
 *
 * 1. Detection here only orders a menu. What the deploy will actually build
 *    comes back from the create endpoint's dry run, which runs the same
 *    detector the deploy runs. There is no second ladder in JavaScript.
 * 2. Every prompt has a flag, and `--yes` skips the confirmation — so the same
 *    command works in a pipeline. Nothing is inferred silently that spends
 *    money: the summary always names the region and where the site counts
 *    against quota.
 */
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { basename, join } from 'node:path';
import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';

import { ApiClient } from './api.mjs';
import { defaultBaseUrl, instanceKey, listInstances, readGlobalConfig, readSiteLink, writeSiteLink } from './config.mjs';
import { c, info, ok, warn } from './print.mjs';
import { isInteractive, pickRow } from './pick.mjs';
import {
  envKeyNames,
  manifestKind,
  proposeName,
  rankKinds,
} from './init-detect.mjs';

const KIND_LABELS = {
  edge: 'Edge — static and SSG sites on the edge network',
  cloud: 'Cloud — a managed container app',
  vm: 'Server — a site on a server you own',
};

/**
 * @param {string[]} _args
 * @param {Record<string, any>} flags
 */
export async function init(_args, flags) {
  const cwd = process.cwd();
  const noPrompt = Boolean(flags['no-prompt'] || flags.quiet || flags.json || process.env.DPLY_NO_PROMPT)
    || ! isInteractive();
  const assumeYes = Boolean(flags.yes || flags.y);

  const client = await requireClientForInit(flags, noPrompt);
  const baseUrl = client.baseUrl;

  // Capabilities FIRST, before a single question. An instance that cannot
  // create anything should say so immediately — asking someone to answer
  // prompts and only then telling them the whole thing is impossible is the
  // worst possible ordering, and it is the one this had.
  const capabilities = await fetchCapabilities(client, baseUrl);

  const linked = await readSiteLink(cwd);
  if (linked && ! flags.force) {
    const handled = await handleLinkedFolder(client, linked, capabilities, { noPrompt, flags });
    if (handled) {
      return;
    }
  }

  // Nothing here can be created — say it before the menu, not after it.
  if (! canCreateAnything(capabilities)) {
    return explainNoCreateHere(capabilities, client, { noPrompt });
  }

  const kind = await chooseKind(cwd, capabilities, { flags, noPrompt });
  if (kind === null) {
    return;
  }

  const kindCapability = capabilities.kinds?.[kind] ?? {};

  // Kinds without a create endpoint on this instance — or with one that is not
  // switched on — hand off to the dashboard wizard rather than pretending.
  if (! kindCapability.cli_create) {
    return openWizardFor(kind, capabilities, cwd, { noPrompt });
  }

  if (kind === 'cloud') {
    return initCloud(client, cwd, capabilities, { flags, noPrompt, assumeYes });
  }

  if (kind === 'vm') {
    return initVm(client, cwd, capabilities, { flags, noPrompt, assumeYes });
  }

  return openWizardFor(kind, capabilities, cwd, { noPrompt });
}

/**
 * Every path out of "this instance cannot create anything", in one place —
 * reached before any question is asked.
 */
async function explainNoCreateHere(capabilities, client, { noPrompt }) {
  const host = instanceKey(client.baseUrl);

  info('');
  if (capabilities.unsupported) {
    warn(`${host} is running a dply version without CLI site creation.`);
  } else {
    warn(`Creating sites from the CLI is switched off on ${host}.`);
    const flag = Object.values(capabilities.kinds ?? {})
      .map((kind) => kind?.cli_create_flag)
      .find(Boolean);
    if (flag) {
      info(c.dim(`  An operator can enable it with ${c.cyan(`${flag}=true`)}, then \`php artisan config:clear\`.`));
    }
  }

  info(c.dim(`  Create the site in the dashboard, then run ${c.cyan('dply link')} here.`));
  info(c.dim(`  ${c.cyan('dply deploy')} works normally once linked.`));

  const other = (await listInstances()).filter((row) => ! row.active);
  if (other.length > 0) {
    info('');
    info(c.dim(`  Other instances you are signed in to: ${other.map((row) => row.key).join(', ')}`));
    info(c.dim(`  Switch with ${c.cyan('dply use <name>')}.`));
  }

  if (! noPrompt && capabilities.kinds && Object.keys(capabilities.kinds).length === 0) {
    return;
  }
}

/**
 * True when this instance can create nothing at all from the CLI — an older
 * dply, or one with every create surface switched off.
 */
function canCreateAnything(capabilities) {
  return Object.values(capabilities?.kinds ?? {}).some((kind) => kind?.cli_create);
}

/* ------------------------------------------------------------------ auth */

/**
 * A missing token is step zero, not a dead end: run the same device flow
 * `dply login` runs and carry on where we left off.
 */
async function requireClientForInit(flags, noPrompt) {
  const cfg = await readGlobalConfig();

  if (! cfg?.token) {
    if (noPrompt) {
      throw exit('Not logged in. Run `dply login` first (or pass --token).', 2);
    }

    info(c.bold('You are not signed in to dply yet.'));
    info(c.dim('Approving this device once is all it takes — init continues afterwards.'));
    info('');

    const { login } = await import('./commands.mjs');
    await login([], { ...flags, 'no-shell': true });

    const after = await readGlobalConfig();
    if (! after?.token) {
      throw exit('Sign-in did not complete.', 2);
    }

    return new ApiClient({ baseUrl: after.baseUrl ?? defaultBaseUrl(), token: after.token });
  }

  return new ApiClient({ baseUrl: cfg.baseUrl ?? defaultBaseUrl(), token: cfg.token });
}

/**
 * An instance older than this feature 404s here. That is a fact worth stating
 * plainly rather than letting the next call fail obscurely — every other
 * command still works against it.
 */
async function fetchCapabilities(client, baseUrl) {
  try {
    const response = await client.get('/capabilities');

    return response?.data ?? {};
  } catch (err) {
    if (err?.status === 404) {
      // An instance older than this endpoint. Not fatal: a linked folder can
      // still be inspected and deployed here, and creating is what degrades.
      // Erroring outright would make `dply init` useless against every
      // instance that has not been upgraded yet.
      return { unsupported: true, baseUrl, kinds: {} };
    }
    if (err?.status === 403) {
      throw exit('This token cannot read instance capabilities. Run `dply auth refresh` to re-approve it.', 2);
    }

    throw err;
  }
}

/* ------------------------------------------------------- already linked */

async function handleLinkedFolder(client, linked, capabilities, { noPrompt, flags }) {
  const siteId = linked.link?.siteId;
  if (! siteId) {
    return false;
  }

  // A link records the instance its site lives on. Before blaming the site for
  // being missing, check whether we are simply looking on the wrong one — a
  // token is per-instance, so a folder linked on one install is invisible from
  // another, and "no longer exists" is a badly wrong thing to tell someone
  // whose site is fine.
  const linkedHost = instanceKey(linked.link.baseUrl ?? client.baseUrl);
  const activeHost = instanceKey(client.baseUrl);

  if (linked.link.baseUrl && linkedHost !== activeHost) {
    warn(`This folder is linked to a site on ${c.bold(linkedHost)}, but you are on ${c.bold(activeHost)}.`);
    info(c.dim(`  Switch back with ${c.cyan(`dply use ${linked.link.baseUrl}`)} to deploy it.`));
    info('');

    if (noPrompt) {
      throw exit('Pass --force to create a new site here instead.', 2);
    }

    const answer = await ask(`Create a separate site for this folder on ${activeHost}? [y/N] `);

    // "no" means they wanted the other instance — nothing more to do here.
    return ! /^y(es)?$/i.test(answer.trim());
  }

  const kind = linked.link?.kind ?? linked.link?.product ?? 'vm';
  let site = null;
  try {
    site = await fetchLinkedSite(client, siteId, kind);
  } catch (err) {
    if (err?.status === 404) {
      warn('This folder is linked to a site that no longer exists (or belongs to another organization).');
      info(c.dim(`Linked id: ${siteId}`));

      if (noPrompt) {
        throw exit('Pass --force to create a new site here.', 2);
      }

      // Only offer to create if this instance can actually create something;
      // otherwise the answer leads nowhere and the question was a waste.
      if (! canCreateAnything(capabilities)) {
        info(c.dim(`${instanceKey(client.baseUrl)} cannot create sites from the CLI — use \`dply link\` to attach an existing one.`));

        return true;
      }

      const answer = await ask('Create a new site for this folder? [y/N] ');

      return ! /^y(es)?$/i.test(answer.trim());
    }

    throw err;
  }

  if (! site) {
    warn('This folder is linked to a site that no longer exists (or belongs to another organization).');
    info(c.dim(`Linked id: ${siteId}`));

    if (noPrompt) {
      throw exit('Pass --force to create a new site here.', 2);
    }

    if (! canCreateAnything(capabilities)) {
      info(c.dim(`${instanceKey(client.baseUrl)} cannot create sites from the CLI — use \`dply link\` to attach an existing one.`));

      return true;
    }

    const answer = await ask('Create a new site for this folder? [y/N] ');

    return ! /^y(es)?$/i.test(answer.trim());
  }

  const url = site.url ?? site.visit_url ?? site.live_url ?? site.primary_hostname;
  info('');
  info(`${c.bold(site.name)} ${c.dim(`· ${kind === 'byo' ? 'vm' : kind}`)}`);
  info(`  status  ${site.status ?? '—'}`);
  if (url) {
    info(`  url     ${c.cyan(url)}`);
  }
  info('');

  if (noPrompt || flags.status) {
    return true;
  }

  const options = [
    { id: 'deploy', name: 'Deploy this folder now' },
    { id: 'open', name: 'Open it in the dashboard' },
  ];
  if (canCreateAnything(capabilities)) {
    options.push({ id: 'new', name: 'Create another site for this folder' });
  }

  const choice = await pickRow(options, { title: 'This folder is already linked.' });

  if (choice?.id === 'deploy') {
    const { deploy } = await import('./commands.mjs');
    await deploy([], { ...flags, wait: true });

    return true;
  }

  if (choice?.id === 'open') {
    const { openInBrowser } = await import('./commands.mjs');
    await openInBrowser(site.workspace_url ?? client.baseUrl);

    return true;
  }

  return choice?.id !== 'new';
}

/* -------------------------------------------------------------- the menu */

async function chooseKind(cwd, capabilities, { flags, noPrompt }) {
  // A repository that declares what it wants should not be asked. --kind still
  // wins, so the manifest is a default rather than a lock.
  const declared = declaredKind(cwd);
  const wanted = String(flags.kind ?? declared ?? '').trim().toLowerCase();

  if (declared && ! flags.kind) {
    info(c.dim(`dply.yaml declares ${c.bold(declared)} — using that.`));
  }

  if (wanted) {
    if (! ['vm', 'cloud', 'edge'].includes(wanted)) {
      throw exit(`Unknown --kind "${wanted}". Use vm, cloud, or edge.`, 2);
    }

    return wanted;
  }

  const ranked = rankKinds(readFolder(cwd)).filter(
    (row) => capabilities.kinds?.[row.kind]?.enabled !== false,
  );

  if (noPrompt) {
    const best = ranked.find((row) => row.fits) ?? ranked[0];
    if (! best) {
      throw exit('No site kinds are enabled on this instance.', 2);
    }
    info(c.dim(`Using --kind ${best.kind} (${best.reason}).`));

    return best.kind;
  }

  const chosen = await pickRow(ranked, {
    title: `What should ${c.bold(basename(cwd))} be deployed as?`,
    label: (row) => (row.fits ? KIND_LABELS[row.kind] : c.dim(KIND_LABELS[row.kind])),
    // The reason is the point: an option nobody can use still says why.
    hint: (row) => (row.fits ? `detected: ${row.reason}` : row.reason),
  });

  return chosen?.kind ?? null;
}

/**
 * A kind the CLI cannot create right now — for one of two quite different
 * reasons, which are worth telling apart. "Not built yet" is something the user
 * waits for; "built, but an operator has not switched it on" is something they
 * can fix in a minute. Saying the first when the second is true sends people
 * looking for a release that already shipped.
 */
async function openWizardFor(kind, capabilities, cwd, { noPrompt }) {
  const capability = capabilities.kinds?.[kind] ?? {};
  const base = String(capabilities.baseUrl ?? capabilities.instance?.url ?? '').replace(/\/+$/, '');
  const url = capability.create_url ?? (base ? `${base}/${kind === 'vm' ? 'servers' : kind}/create` : null);

  info('');

  if (capabilities.unsupported) {
    warn(`${capabilities.baseUrl ?? 'This instance'} does not support creating sites from the CLI yet.`);
    info(c.dim('  `dply link` and `dply deploy` work against it as before.'));
  } else if (capability.cli_create_supported && capability.cli_create === false) {
    warn(`Creating ${kind} sites from the CLI is switched off on this dply instance.`);
    if (capability.cli_create_flag) {
      info(c.dim(`  An operator can enable it with ${c.cyan(`${capability.cli_create_flag}=true`)}, then \`php artisan config:clear\`.`));
    }
  } else {
    warn(`${kind} sites cannot be created from the CLI yet.`);
  }

  if (! url) {
    return;
  }

  info(c.dim(`Create it here, then run \`dply link\` in this folder: ${url}`));

  if (! noPrompt) {
    const answer = await ask('Open it in the browser? [Y/n] ');
    if (! /^n(o)?$/i.test(answer.trim())) {
      const { openInBrowser } = await import('./commands.mjs');
      await openInBrowser(url);
    }
  }
}

/* ---------------------------------------------------------------- vm */

/**
 * A site on a server you already own.
 *
 * The one structural difference from the managed products: a site has to live
 * somewhere, so a server is chosen before anything else can be asked. dply does
 * not provision a server here — that is a bigger decision than `init` should
 * make on someone's behalf — so an organization with no ready server is pointed
 * at the dashboard rather than quietly buying a machine.
 */
async function initVm(client, cwd, capabilities, { flags, noPrompt, assumeYes }) {
  const server = await chooseServer(client, { flags, noPrompt });
  if (server === null) {
    return;
  }

  const git = readGitState(cwd);
  const folder = readFolder(cwd);
  const proposed = proposeName(cwd);
  const type = String(flags.type ?? guessSiteType(folder));

  if (git.hasRemote && git.hasUpstream) {
    await warnIfBehindRemote(git, { noPrompt, cwd });
  }

  const payload = {
    name: String(flags.name ?? proposed.name),
    type,
    primary_hostname: String(flags.hostname ?? ''),
    git_repository_url: git.remoteUrl,
    git_branch: git.branch || 'main',
    app_port: type === 'node' ? Number(flags.port ?? 3000) : null,
  };

  if (flags['document-root']) {
    payload.document_root = String(flags['document-root']);
  }

  const dry = await dryRun(client, payload, { noPrompt }, `/servers/${encodeURIComponent(server.id)}/sites`);
  if (dry === null) {
    return;
  }

  const envContent = await offerEnvImport(cwd, { noPrompt, flags });
  if (envContent) {
    payload.env_file_content = envContent;
  }

  info('');
  info(c.bold('About to create'));
  info(`  name       ${payload.name}`);
  info(`  kind       vm (site on your server)`);
  info(`  server     ${server.name}${dry.plan?.webserver ? c.dim(` · ${dry.plan.webserver}`) : ''}`);
  info(`  type       ${type}`);
  info(`  files      ${dry.plan?.document_root ?? c.dim('server default')}`);
  info(`  source     ${payload.git_repository_url
    ? `${payload.git_repository_url} · ${payload.git_branch}`
    : c.dim('no git remote — deploy from the dashboard or add one later')}`);
  if (payload.primary_hostname) {
    info(`  domain     ${payload.primary_hostname}`);
  }
  if (dry.quota && dry.quota.limit !== null && dry.quota.limit !== undefined) {
    info(`  quota      site ${Number(dry.quota.used) + 1} of ${dry.quota.limit} on your plan`);
  }
  if (payload.env_file_content) {
    info(`  env        importing from .env`);
  }
  info('');

  if (! assumeYes && ! noPrompt) {
    const answer = await ask(`${c.bold('Create it?')} [Y/n] `);
    if (/^n(o)?$/i.test(answer.trim())) {
      info('Nothing created.');

      return;
    }
  }

  const created = await client.post(
    `/servers/${encodeURIComponent(server.id)}/sites`,
    payload,
    { headers: { 'Idempotency-Key': `init-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` } },
  );
  const site = created.data;

  const linkPath = await writeSiteLink(
    { siteId: site.id, siteName: site.name, baseUrl: client.baseUrl, kind: 'vm', product: 'byo', serverId: site.server_id },
    cwd,
  );

  ok(`Created ${c.bold(site.name)} — linked in ${c.dim(linkPath.replace(`${cwd}/`, ''))}`);
  info(c.dim('  The server is provisioning the site now.'));
  info(c.dim(`  Then: ${c.cyan('dply deploy --follow')}`));
  info(c.dim(`  Watch it: ${site.workspace_url}`));
}

/**
 * Servers are listed rather than guessed: which machine a site lands on is not
 * something to infer from a folder.
 */
async function chooseServer(client, { flags, noPrompt }) {
  const wanted = String(flags.server ?? '').trim();
  const rows = (await client.get('/servers'))?.data ?? [];
  const ready = rows.filter((row) => String(row.status ?? '') === 'ready');

  if (rows.length === 0) {
    throw exit(
      'This organization has no servers yet. Add one in the dashboard, then run `dply init` again —\n'
      + 'or run `dply init --kind cloud` if you want a managed container app instead.',
      2,
    );
  }

  if (wanted) {
    const match = rows.find((row) => String(row.id) === wanted
      || String(row.name).toLowerCase() === wanted.toLowerCase());
    if (! match) {
      throw exit(`No server matched "${wanted}". Run \`dply server list\`.`, 2);
    }

    return match;
  }

  if (ready.length === 0) {
    throw exit('No server is ready yet — a site created now would sit pending. Check `dply server list`.', 2);
  }

  if (noPrompt) {
    if (ready.length > 1) {
      throw exit('Pass --server <id-or-name>: this organization has more than one ready server.', 2);
    }

    return ready[0];
  }

  return pickRow(ready, {
    title: 'Which server should this site live on?',
    hint: (row) => [row.provider, row.region, row.ip_address].filter(Boolean).join(' · '),
  });
}

/**
 * Enough to fill a default the user can override — the server decides what it
 * can actually serve.
 */
function guessSiteType(folder) {
  if (folder.files.includes('composer.json')) {
    return 'php';
  }
  if (folder.files.includes('package.json')) {
    return 'node';
  }
  if (folder.files.includes('index.html')) {
    return 'static';
  }

  return 'php';
}

/* ------------------------------------------------------------- cloud */

/**
 * A managed container app.
 *
 * The container backend clones and builds the repository itself, so dply
 * never holds the source. There is no upload alternative to fall back on —
 * a folder with no reachable remote simply cannot become a cloud app, and
 * saying so beats inventing a path that would fail at provision time.
 */
async function initCloud(client, cwd, capabilities, { flags, noPrompt, assumeYes }) {
  const git = readGitState(cwd);

  if (! git.hasRemote) {
    throw exit(
      'A cloud app builds from a git repository, and this folder has no remote.\n'
      + 'Push it somewhere first, then run `dply init` again.',
      2,
    );
  }

  if (! git.hasUpstream) {
    warn(`Branch ${git.branch} has never been pushed — the backend would have nothing to build.`);
    if (! noPrompt) {
      const answer = await ask(`Push it to origin/${git.branch} now? [Y/n] `);
      if (/^n(o)?$/i.test(answer.trim())) {
        throw exit('Nothing to build.', 2);
      }
      runGit(cwd, ['push', '-u', 'origin', git.branch]);
    }
  } else {
    // The backend builds the remote, not the folder on screen.
    await warnIfBehindRemote(git, { noPrompt, cwd });
  }

  const folder = readFolder(cwd);
  const proposed = proposeName(cwd);

  const payload = {
    name: String(flags.name ?? proposed.name),
    mode: 'source',
    repo: git.remoteUrl,
    branch: git.branch || 'main',
    dockerfile_path: String(flags.dockerfile ?? (folder.files.includes('Dockerfile') ? 'Dockerfile' : '')),
    port: Number(flags.port ?? 8080),
    instances: Number(flags.instances ?? 1),
    size_tier: String(flags.size ?? 'small'),
    region: flags.region ? String(flags.region) : '',
    backend: String(flags.backend ?? 'auto'),
  };

  const dry = await dryRun(client, payload, { noPrompt }, '/cloud/sites');
  if (dry === null) {
    return;
  }

  const envContent = await offerEnvImport(cwd, { noPrompt, flags });
  if (envContent) {
    payload.env_file_content = envContent;
  }

  // Region has no defensible default until the backend is known, which the dry
  // run just resolved.
  if (payload.region === '') {
    payload.region = dry.plan?.regions?.[0]?.slug ?? '';
  }

  info('');
  info(c.bold('About to create'));
  info(`  name       ${payload.name}`);
  info(`  kind       cloud (container app)`);
  info(`  source     ${payload.repo} · ${payload.branch}`);
  info(`  build      ${payload.dockerfile_path || c.dim('backend auto-detects the build')}`);
  info(`  backend    ${dry.plan?.backend ?? 'auto'}`);
  info(`  region     ${payload.region || c.dim('backend default')}`);
  info(`  size       ${payload.size_tier} × ${payload.instances}`);
  info(`  port       ${payload.port}`);
  if (dry.quota && dry.quota.limit !== null && dry.quota.limit !== undefined) {
    info(`  quota      app ${Number(dry.quota.used) + 1} of ${dry.quota.limit} on your plan`);
  }
  if (payload.env_file_content) {
    info(`  env        importing from .env`);
  }
  info('');

  if (! assumeYes && ! noPrompt) {
    const answer = await ask(`${c.bold('Create it?')} [Y/n] `);
    if (/^n(o)?$/i.test(answer.trim())) {
      info('Nothing created.');

      return;
    }
  }

  const created = await client.post('/cloud/sites', payload, {
    headers: { 'Idempotency-Key': `init-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` },
  });
  const site = created.data;

  const linkPath = await writeSiteLink(
    { siteId: site.id, siteName: site.name, baseUrl: client.baseUrl, kind: 'cloud', product: 'cloud' },
    cwd,
  );

  ok(`Created ${c.bold(site.name)} — linked in ${c.dim(linkPath.replace(`${cwd}/`, ''))}`);
  info(c.dim('  The backend is building your image now.'));
  info(c.dim(`  Watch it: ${site.workspace_url}`));
}

/**
 * The container backend builds `origin/<branch>`, so unpushed work is invisible.
 */
async function warnIfBehindRemote(git, { noPrompt, cwd }) {
  if (git.aheadCommits === 0) {
    if (git.dirtyFiles > 0) {
      info(c.dim(`  ${git.dirtyFiles} uncommitted file(s) — not included; the backend builds the remote.`));
    }

    return;
  }

  warn(`${git.aheadCommits} commit(s) not pushed. The backend will build origin/${git.branch}.`);

  if (noPrompt) {
    return;
  }

  const answer = await ask('Push first? [Y/n] ');
  if (! /^n(o)?$/i.test(answer.trim())) {
    runGit(cwd, ['push']);
  }
}

/* --------------------------------------------------------- prompts */

/**
 * A `.env` is the difference between an app that boots and one that 500s on
 * first hit — but it is also secrets leaving the machine, so it is never
 * implied. Key names are shown; values are never printed.
 */
async function offerEnvImport(cwd, { noPrompt, flags }) {
  if (flags['no-env-file']) {
    return null;
  }

  const path = join(cwd, String(flags['env-file'] ?? '.env'));
  if (! existsSync(path)) {
    return null;
  }

  const contents = readFileSync(path, 'utf8');
  const keys = envKeyNames(contents);
  if (keys.length === 0) {
    return null;
  }

  if (noPrompt) {
    return flags['env-file'] ? contents : null;
  }

  info('');
  info(`Found ${c.bold('.env')} with ${keys.length} key${keys.length === 1 ? '' : 's'}:`);
  info(c.dim(`  ${keys.slice(0, 12).join(', ')}${keys.length > 12 ? `, +${keys.length - 12} more` : ''}`));
  info(c.dim('  Values are never shown here, and never ride the upload — they go straight'));
  info(c.dim('  into this site\'s encrypted environment.'));
  const answer = await ask('Import them as this site\'s environment? [y/N] ');

  return /^y(es)?$/i.test(answer.trim()) ? contents : null;
}

/* ------------------------------------------------------- dry run + create */

async function dryRun(client, payload, { noPrompt }, endpoint) {
  for (;;) {
    let response;
    try {
      response = await client.post(endpoint, { ...payload, dry_run: true });
    } catch (err) {
      // The ability middleware answers before the controller does, so a 403
      // here means the token predates the create scope.
      if (err?.status === 403) {
        if (noPrompt) {
          throw exit('This token cannot create sites. Run `dply auth refresh` and approve the create scope for this product.', 2);
        }
        warn('This CLI session was approved without the scope needed to create this kind of site.');
        const answer = await ask('Re-approve it now (opens your browser)? [Y/n] ');
        if (/^n(o)?$/i.test(answer.trim())) {
          throw exit('Run `dply auth refresh` when you are ready.', 2);
        }
        const { refreshAuth } = await import('./commands.mjs');
        await refreshAuth([], {});
        const cfg = await readGlobalConfig();
        client.token = cfg?.token ?? client.token;

        continue;
      }

      const blocker = err?.body?.blocker;
      if (! blocker) {
        throw err;
      }

      const again = await handleBlocker(blocker, { noPrompt });
      if (! again) {
        return null;
      }

      continue;
    }

    return response?.data ?? {};
  }
}

/**
 * A blocker is a fact plus the place that clears it. Nothing here can be fixed
 * from the terminal, so the useful move is to open that page and retry — not
 * to make the user start over.
 */
async function handleBlocker(blocker, { noPrompt }) {
  info('');
  warn(blocker.message);

  if (blocker.resolve_command) {
    info(c.dim(`  Fix: ${c.cyan(blocker.resolve_command)}`));
  }
  if (blocker.resolve_url) {
    info(c.dim(`  Fix: ${blocker.resolve_url}`));
  }

  if (noPrompt || ! blocker.resolve_url) {
    throw exit('Cannot create a site until that is resolved.', 2);
  }

  const answer = await ask('Open that page now, then retry? [Y/n] ');
  if (/^n(o)?$/i.test(answer.trim())) {
    return false;
  }

  const { openInBrowser } = await import('./commands.mjs');
  await openInBrowser(blocker.resolve_url);
  await ask('Press Enter once it is sorted… ');

  return true;
}

/* --------------------------------------------------------------- helpers */

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {string} kind
 */
async function fetchLinkedSite(client, siteId, kind) {
  if (kind === 'edge') {
    return (await client.get(`/edge/sites/${encodeURIComponent(siteId)}`))?.data ?? null;
  }

  if (kind === 'cloud') {
    try {
      return (await client.get(`/cloud/sites/${encodeURIComponent(siteId)}`))?.data ?? null;
    } catch (err) {
      if (err?.status !== 404) {
        throw err;
      }
    }
  }

  const rows = (await client.get('/sites'))?.data ?? [];

  return rows.find((row) => String(row.id) === String(siteId)) ?? null;
}

/**
 * `kind:` from the repository's dply manifest, if it has one.
 *
 * @param {string} cwd
 * @returns {string|null}
 */
function declaredKind(cwd) {
  for (const name of ['dply.yaml', 'dply.yml']) {
    const path = join(cwd, name);
    if (! existsSync(path)) {
      continue;
    }

    try {
      const found = manifestKind(readFileSync(path, 'utf8'));
      if (found) {
        return found;
      }
    } catch {
      // An unreadable manifest is the deploy's problem to report, not init's.
    }
  }

  return null;
}

function readFolder(cwd) {
  let files = [];
  try {
    files = readdirSync(cwd);
  } catch {
    files = [];
  }

  let packageJson = null;
  if (files.includes('package.json')) {
    try {
      packageJson = JSON.parse(readFileSync(join(cwd, 'package.json'), 'utf8'));
    } catch {
      packageJson = null;
    }
  }

  return { files, packageJson };
}

/**
 * Everything init needs to know about the folder's git state, in one shot.
 * Each command failing is itself an answer (no repo, no remote, no upstream).
 */
function readGitState(cwd) {
  const run = (args) => {
    const result = spawnSync('git', args, { cwd, encoding: 'utf8' });

    return result.status === 0 ? String(result.stdout).trim() : null;
  };

  const inRepo = run(['rev-parse', '--is-inside-work-tree']) === 'true';
  if (! inRepo) {
    return { inRepo: false, hasRemote: false, hasUpstream: false, dirtyFiles: 0, aheadCommits: 0, branch: 'main', prefix: '', remoteUrl: '', headSha: '' };
  }

  const remoteUrl = run(['remote', 'get-url', 'origin']) ?? '';
  const branch = run(['rev-parse', '--abbrev-ref', 'HEAD']) ?? 'main';
  const upstream = run(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
  const status = run(['status', '--porcelain']) ?? '';
  const ahead = upstream ? run(['rev-list', '--count', '@{u}..HEAD']) : null;

  return {
    inRepo: true,
    hasRemote: remoteUrl !== '',
    hasUpstream: Boolean(upstream),
    dirtyFiles: status ? status.split('\n').filter(Boolean).length : 0,
    aheadCommits: Number(ahead ?? 0) || 0,
    branch,
    // A monorepo subdirectory is a fact git already knows; the web wizard
    // cannot express it, so the CLI is strictly more capable here.
    prefix: (run(['rev-parse', '--show-prefix']) ?? '').replace(/\/+$/, ''),
    remoteUrl,
    headSha: run(['rev-parse', 'HEAD']) ?? '',
  };
}

function runGit(cwd, args) {
  const result = spawnSync('git', args, { cwd, stdio: 'inherit' });
  if (result.status !== 0) {
    throw exit(`git ${args.join(' ')} failed.`, 1);
  }
}

async function ask(question) {
  const rl = readline.createInterface({ input, output, terminal: true });
  try {
    return await rl.question(question);
  } finally {
    rl.close();
  }
}

function exit(message, code) {
  const err = new Error(message);
  err.exitCode = code;

  return err;
}
