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
 *    money: the summary always names the region, the delivery mode, and where
 *    the function counts against quota.
 */
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { tmpdir } from 'node:os';
import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';

import { ApiClient } from './api.mjs';
import { defaultBaseUrl, readGlobalConfig, readSiteLink, writeSiteLink } from './config.mjs';
import { followSiteDeployment } from './deploy-follow.mjs';
import { c, info, ok, warn } from './print.mjs';
import { isInteractive, pickRow } from './pick.mjs';
import {
  classifyGitState,
  envKeyNames,
  filterUploadPaths,
  humanBytes,
  proposeName,
  rankKinds,
} from './init-detect.mjs';

const KIND_LABELS = {
  serverless: 'Serverless — a managed function, scaled to zero',
  edge: 'Edge — static and SSG sites on the edge network',
  cloud: 'Cloud — a managed container app',
  vm: 'Server — a site on a server you own',
};

const HELLO_WORLD = {
  node: {
    file: 'main.js',
    body: `// A dply serverless function. \`main\` is the handler the runtime invokes.\nexport function main(args) {\n  return {\n    body: \`Hello from dply — \${new Date().toISOString()}\`,\n  };\n}\n`,
  },
  php: {
    file: 'main.php',
    body: `<?php\n\n// A dply serverless function. \`main\` is the handler the runtime invokes.\nfunction main(array $args): array\n{\n    return ['body' => 'Hello from dply — '.date(DATE_ATOM)];\n}\n`,
  },
  python: {
    file: 'main.py',
    body: `# A dply serverless function. \`main\` is the handler the runtime invokes.\nfrom datetime import datetime, timezone\n\n\ndef main(args):\n    return {"body": f"Hello from dply — {datetime.now(timezone.utc).isoformat()}"}\n`,
  },
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

  // Already linked? init becomes the one command you can always type.
  const linked = await readSiteLink(cwd);
  if (linked && ! flags.force) {
    const handled = await handleLinkedFolder(client, linked, { noPrompt, flags });
    if (handled) {
      return;
    }
  }

  const capabilities = await fetchCapabilities(client, baseUrl);

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

  await initServerless(client, cwd, capabilities, { flags, noPrompt, assumeYes });
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
      throw exit(
        `\`dply init\` needs a newer dply instance — ${baseUrl} does not support CLI site creation yet.\n`
        + '`dply link` and `dply deploy` work against it as before.',
        2,
      );
    }
    if (err?.status === 403) {
      throw exit('This token cannot read instance capabilities. Run `dply auth refresh` to re-approve it.', 2);
    }

    throw err;
  }
}

/* ------------------------------------------------------- already linked */

async function handleLinkedFolder(client, linked, { noPrompt, flags }) {
  const siteId = linked.link?.siteId;
  if (! siteId) {
    return false;
  }

  let site = null;
  try {
    site = (await client.get(`/serverless/sites/${encodeURIComponent(siteId)}`))?.data ?? null;
  } catch (err) {
    if (err?.status === 404) {
      warn('This folder is linked to a site that no longer exists (or belongs to another organization).');
      info(c.dim(`Linked id: ${siteId}`));
      if (noPrompt) {
        throw exit('Pass --force to create a new site here.', 2);
      }
      const answer = await ask('Create a new site for this folder? [y/N] ');

      return ! /^y(es)?$/i.test(answer.trim());
    }

    throw err;
  }

  info('');
  info(`${c.bold(site.name)} ${c.dim('· serverless')}`);
  info(`  status  ${site.is_live ? c.green(site.status) : site.status}`);
  if (site.url) {
    info(`  url     ${c.cyan(site.url)}`);
  }
  info(`  source  ${site.source_kind === 'upload' ? 'uploaded folder' : 'git'}`);
  info('');

  if (noPrompt || flags.status) {
    return true;
  }

  const choice = await pickRow(
    [
      { id: 'deploy', name: 'Deploy this folder now' },
      { id: 'open', name: 'Open it in the dashboard' },
      { id: 'new', name: 'Create another site for this folder' },
    ],
    { title: 'This folder is already linked.' },
  );

  if (choice?.id === 'deploy') {
    const { deploy } = await import('./commands.mjs');
    await deploy([], { ...flags, wait: true });

    return true;
  }

  if (choice?.id === 'open') {
    const { openInBrowser } = await import('./commands.mjs');
    await openInBrowser(site.workspace_url ?? `${client.baseUrl}/serverless`);

    return true;
  }

  return choice?.id !== 'new';
}

/* -------------------------------------------------------------- the menu */

async function chooseKind(cwd, capabilities, { flags, noPrompt }) {
  const wanted = String(flags.kind ?? '').trim().toLowerCase();
  if (wanted) {
    if (! ['vm', 'cloud', 'edge', 'serverless'].includes(wanted)) {
      throw exit(`Unknown --kind "${wanted}". Use vm, cloud, edge, or serverless.`, 2);
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
  const url = capability.create_url;

  info('');

  if (capability.cli_create_supported && capability.cli_create === false) {
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

/* --------------------------------------------------------- serverless */

async function initServerless(client, cwd, capabilities, { flags, noPrompt, assumeYes }) {
  const git = readGitState(cwd);
  const source = await chooseSource(git, { flags, noPrompt });

  if (source.kind === 'git' && source.action === 'push') {
    runGit(cwd, ['push']);
    ok('Pushed.');
  }

  let folder = readFolder(cwd);
  if (! folder.files.length || (! folder.files.includes('package.json')
    && ! folder.files.includes('composer.json') && ! hasEntryFile(folder.files))) {
    const scaffolded = await offerHelloWorld(cwd, { noPrompt, flags });
    if (scaffolded) {
      folder = readFolder(cwd);
      source.kind = 'upload';
    }
  }

  const proposed = proposeName(cwd);
  const name = String(flags.name ?? proposed.name);
  const region = String(flags.region ?? capabilities.serverless?.default_region ?? 'nyc1');
  const deliveryMode = await chooseDeliveryMode(capabilities, { flags, noPrompt });

  // Upload sources are detected on the archive, so it has to exist before the
  // dry run — which is also why the dry run keeps it and the create consumes
  // the same bytes.
  let sourceHandle = null;
  if (source.kind === 'upload') {
    sourceHandle = await uploadCurrentFolder(client, cwd, flags, null, capabilities);
  }

  const payload = {
    name,
    source_kind: source.kind,
    repo: source.kind === 'git' ? git.remoteUrl : '',
    branch: git.branch || 'main',
    repository_subdirectory: git.prefix,
    region,
    delivery_mode: deliveryMode,
    runtime: String(flags.runtime ?? 'auto'),
    source_handle: sourceHandle,
  };

  const dry = await dryRun(client, payload, { noPrompt });
  if (dry === null) {
    return;
  }

  const envContent = await offerEnvImport(cwd, { noPrompt, flags });
  if (envContent) {
    payload.env_file_content = envContent;
  }

  printSummary({ payload, plan: dry.plan, quota: dry.quota, git, source, proposed });

  if (! assumeYes && ! noPrompt) {
    const answer = await ask(`${c.bold('Create it?')} [Y/n] `);
    if (/^n(o)?$/i.test(answer.trim())) {
      info('Nothing created.');

      return;
    }
  }

  const created = await createSite(client, payload);
  const site = created.data;

  // Written before the deploy, so a failed first deploy still leaves a folder
  // you can retry from rather than an orphan you cannot address.
  const linkPath = await writeSiteLink(
    { siteId: site.id, siteName: site.name, baseUrl: client.baseUrl, kind: 'serverless', product: 'serverless', sourceKind: site.source_kind },
    cwd,
  );
  ok(`Created ${c.bold(site.name)} — linked in ${c.dim(linkPath.replace(`${cwd}/`, ''))}`);

  if (site.push_to_deploy?.enabled) {
    info(c.dim('  push-to-deploy: on'));
  } else if (site.push_to_deploy?.message) {
    info(c.dim(`  push-to-deploy: off — ${site.push_to_deploy.message}`));
  }

  await offerEngines(client, site, dry.plan, { noPrompt, flags });

  if (flags['no-deploy']) {
    info(`Run ${c.cyan('dply deploy')} when you are ready.`);

    return;
  }

  await followInit(client, site, cwd, { noPrompt });
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
      + 'or run `dply init --kind serverless`, which needs no server at all.',
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
 * Shorter than the serverless flow for one structural reason: the container
 * backend clones and builds the repository itself, so dply never holds the
 * source. There is no upload alternative to fall back on — a folder with no
 * reachable remote simply cannot become a cloud app, and saying so beats
 * inventing a path that would fail at provision time.
 */
async function initCloud(client, cwd, capabilities, { flags, noPrompt, assumeYes }) {
  const git = readGitState(cwd);

  if (! git.hasRemote) {
    throw exit(
      'A cloud app builds from a git repository, and this folder has no remote.\n'
      + 'Push it somewhere first, or run `dply init --kind serverless`, which can deploy the folder as-is.',
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
    // Same trap as the serverless git path: the backend builds the remote, not
    // the folder on screen.
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
 * The container backend builds `origin/<branch>`, so unpushed work is invisible
 * to it in exactly the way it is for a git-source function.
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

/* ------------------------------------------------------------- source */

async function chooseSource(git, { flags, noPrompt }) {
  const forced = String(flags.source ?? '').trim().toLowerCase();
  if (forced === 'upload' || forced === 'git') {
    return { kind: forced, action: null };
  }

  const state = classifyGitState(git);

  if (state.deployable === 'upload') {
    info(c.dim(`Source: this folder (${state.summary}).`));

    return { kind: 'upload', action: null };
  }

  if (state.code === 'clean') {
    return { kind: 'git', action: null };
  }

  // dply deploys origin/<branch>, not what is on screen. Say so before it
  // becomes a mystery on the deployed site.
  info('');
  warn(`Heads up — ${state.summary}.`);
  info(c.dim(`dply deploys ${c.bold(`origin/${git.branch}`)}${git.headSha ? ` @ ${git.headSha.slice(0, 7)}` : ''}, not your working folder.`));

  if (noPrompt) {
    info(c.dim('Continuing with the remote (non-interactive).'));

    return { kind: 'git', action: null };
  }

  const options = [{ id: 'push', name: `Push to origin/${git.branch}, then deploy that` }];
  if (state.code !== 'no-upstream') {
    options.push({ id: 'origin', name: `Deploy origin/${git.branch} as it is now` });
  }
  options.push({ id: 'upload', name: 'Deploy this folder as-is (upload, no push-to-deploy)' });

  const choice = await pickRow(options, { title: 'What should dply deploy?' });

  if (choice?.id === 'upload') {
    return { kind: 'upload', action: null };
  }

  return { kind: 'git', action: choice?.id === 'push' ? 'push' : null };
}

/**
 * Pack the folder and send it.
 *
 * With no `siteId` this stashes for a create that has not happened yet and
 * returns the handle; with one it replaces that site's source and redeploys —
 * which is what `dply deploy` means for an upload-source site.
 *
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} cwd
 * @param {Record<string, any>} flags
 * @param {string|null} [siteId]
 * @param {Record<string, any>|null} [capabilities]
 */
export async function uploadCurrentFolder(client, cwd, flags, siteId = null, capabilities = null) {
  const paths = collectUploadPaths(cwd, flags);
  if (paths.length === 0) {
    throw exit('There is nothing to upload in this folder.', 2);
  }

  const stamp = `${Date.now()}-${process.pid}`;
  const archive = join(tmpdir(), `dply-src-${stamp}.tar.gz`);
  const listFile = join(tmpdir(), `dply-src-${stamp}.list`);
  writeFileSync(listFile, `${paths.join('\n')}\n`);

  // System tar rather than a dependency: this package deliberately has none,
  // and every platform it runs on ships one (Windows 10+ as bsdtar). `-h`
  // dereferences symlinks into real files, which is also what keeps the
  // server's archive validation from rejecting an ordinary repo.
  const tar = spawnSync('tar', ['-czhf', archive, '-T', listFile], { cwd, encoding: 'utf8' });
  if (tar.status !== 0) {
    throw exit(`Could not build the upload archive: ${tar.stderr || tar.error?.message || 'tar failed'}`, 1);
  }

  const size = statSync(archive).size;
  const max = Number(
    capabilities?.serverless?.upload?.max_bytes
    ?? (await client.get('/capabilities').catch(() => null))?.data?.serverless?.upload?.max_bytes
    ?? 104857600,
  );

  // Fail before spending the upload: a 413 after a two-minute transfer says
  // nothing about which directory caused it.
  if (size > max) {
    reportOversize(cwd, paths, size, max);
    throw exit('Upload is too large.', 2);
  }

  info(c.dim(`Uploading ${paths.length} files (${humanBytes(size)})…`));

  const form = new FormData();
  form.append('archive', new Blob([readFileSync(archive)]), 'source.tar.gz');

  const path = siteId
    ? `/serverless/sites/${encodeURIComponent(siteId)}/source`
    : '/serverless/sites/source';

  const response = await client.request(path, { method: 'POST', body: form });

  return response?.data?.source_handle ?? null;
}

function collectUploadPaths(cwd, flags) {
  const excludes = [].concat(flags.exclude ?? []).filter(Boolean).map(String);

  const tracked = spawnSync('git', ['ls-files', '-co', '--exclude-standard'], { cwd, encoding: 'utf8' });
  if (tracked.status === 0) {
    return filterUploadPaths(tracked.stdout.split('\n').filter(Boolean), excludes);
  }

  // Not a repository: walk it, honouring the built-in ignore list only.
  const out = [];
  const walk = (dir, prefix = '') => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const rel = prefix ? `${prefix}/${entry.name}` : entry.name;
      if (filterUploadPaths([rel], excludes).length === 0) {
        continue;
      }
      if (entry.isDirectory()) {
        walk(join(dir, entry.name), rel);
      } else if (entry.isFile()) {
        out.push(rel);
      }
    }
  };
  walk(cwd);

  return out;
}

/**
 * Fail before spending the upload, and say which directory caused it —
 * a 413 after a two-minute transfer tells the user nothing.
 */
function reportOversize(cwd, paths, size, max) {
  const byTop = new Map();
  for (const path of paths) {
    const top = path.split('/')[0];
    let bytes = 0;
    try {
      bytes = statSync(join(cwd, path)).size;
    } catch {
      bytes = 0;
    }
    byTop.set(top, (byTop.get(top) ?? 0) + bytes);
  }

  const worst = [...byTop.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5);

  warn(`This folder packs to ${humanBytes(size)}, over the ${humanBytes(max)} limit for this instance.`);
  info('');
  info('Largest paths:');
  for (const [name, bytes] of worst) {
    info(`  ${humanBytes(bytes).padStart(8)}  ${name}`);
  }
  info('');
  info(c.dim(`Exclude one with ${c.cyan('--exclude <path>')}, or add it to .gitignore.`));
}

/* --------------------------------------------------------- prompts */

async function chooseDeliveryMode(capabilities, { flags, noPrompt }) {
  const forced = String(flags.delivery ?? '').trim().toLowerCase();
  if (forced === 'managed' || forced === 'byo') {
    return forced;
  }

  const managedAvailable = Boolean(capabilities.serverless?.managed_available);
  if (! managedAvailable) {
    return 'byo';
  }

  if (noPrompt) {
    return 'managed';
  }

  // Only asked when both are genuinely possible — it decides whose account
  // the infrastructure lands in, which is not a default worth hiding.
  const choice = await pickRow(
    [
      { id: 'managed', name: 'Run it on dply — billed with your dply subscription' },
      { id: 'byo', name: 'Run it in your own DigitalOcean account' },
    ],
    { title: 'Where should this function run?' },
  );

  return choice?.id ?? 'managed';
}

/**
 * A `.env` is the difference between a Laravel function that boots and one
 * that 500s on first hit — but it is also secrets leaving the machine, so it
 * is never implied. Key names are shown; values are never printed.
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
  const answer = await ask('Import them as this function\'s environment? [y/N] ');

  return /^y(es)?$/i.test(answer.trim()) ? contents : null;
}

async function offerHelloWorld(cwd, { noPrompt, flags }) {
  const language = String(flags.template ?? '').trim().toLowerCase();

  if (! language && noPrompt) {
    throw exit(
      'Nothing deployable here — looked for a framework, a package manifest, a main.{js,php,py,go}, or an index.html.\n'
      + 'Pass --template node|php|python to start from a minimal function.',
      2,
    );
  }

  let chosen = language;
  if (! chosen) {
    info('');
    warn('There is nothing deployable in this folder yet.');
    const choice = await pickRow(
      [
        { id: 'node', name: 'Start from a minimal Node function' },
        { id: 'php', name: 'Start from a minimal PHP function' },
        { id: 'python', name: 'Start from a minimal Python function' },
      ],
      { title: 'Write a hello-world to get going?' },
    );
    if (! choice) {
      throw exit('Nothing to deploy.', 2);
    }
    chosen = choice.id;
  }

  const template = HELLO_WORLD[chosen];
  if (! template) {
    throw exit(`Unknown --template "${chosen}". Use node, php, or python.`, 2);
  }

  const path = join(cwd, template.file);
  if (existsSync(path)) {
    return true;
  }

  writeFileSync(path, template.body);
  ok(`Wrote ${c.bold(template.file)} — edit it and run \`dply deploy\` any time.`);

  return true;
}

/* ------------------------------------------------------- dry run + create */

async function dryRun(client, payload, { noPrompt }, endpoint = '/serverless/sites') {
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
    throw exit('Cannot create a function until that is resolved.', 2);
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

async function createSite(client, payload) {
  // Same key for every retry of this run: a create whose response is lost must
  // not provision a second billable namespace.
  const idempotencyKey = `init-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

  return client.post('/serverless/sites', payload, {
    headers: { 'Idempotency-Key': idempotencyKey },
  });
}

/* --------------------------------------------------------------- output */

function printSummary({ payload, plan, quota, git, source, proposed }) {
  const detected = plan?.detected ?? null;

  info('');
  info(c.bold('About to create'));
  info(`  name       ${payload.name}${proposed.qualified ? c.dim(' (folder name was too generic on its own)') : ''}`);
  info(`  kind       serverless`);
  info(`  source     ${source.kind === 'upload'
    ? 'this folder, uploaded'
    : `${git.remoteUrl} · ${git.branch}${git.prefix ? ` · ${git.prefix}` : ''}`}`);

  if (detected) {
    const label = [detected.framework, detected.runtime].filter((v) => v && v !== 'unknown').join(' · ');
    info(`  runtime    auto${label ? c.dim(` → detected ${label}`) : ''}`);
    if (detected.build_command) {
      info(`  build      ${c.dim(detected.build_command)}`);
    }
  } else {
    info(`  runtime    auto${plan?.error ? c.dim(` (could not inspect: ${plan.error})`) : ''}`);
  }

  info(`  region     ${payload.region}`);
  info(`  delivery   ${payload.delivery_mode === 'managed' ? 'dply’s platform' : 'your own DigitalOcean account'}`);

  if (quota && quota.limit !== null && quota.limit !== undefined) {
    info(`  quota      function ${Number(quota.used) + 1} of ${quota.limit} on your plan`);
  }

  if (payload.env_file_content) {
    info(`  env        importing from .env`);
  }

  for (const warning of detected?.warnings ?? []) {
    info(c.dim(`  ! ${warning}`));
  }

  info('');
}

/**
 * Horizon or a scheduler in the repo means queued work the function will never
 * run unless the matching engine is on. dply has both engines — the moment to
 * mention it is now, not when someone files a bug about jobs not running.
 */
async function offerEngines(client, site, plan, { noPrompt, flags }) {
  const detected = plan?.detected;
  if (! detected?.laravel_horizon || flags['no-workers']) {
    return;
  }

  info('');
  info(c.dim('This app uses Horizon, so it expects a queue worker.'));

  if (noPrompt && ! flags.workers) {
    info(c.dim('Enable it later with `dply serverless workers <name> --enable`.'));

    return;
  }

  if (! noPrompt) {
    const answer = await ask('Turn on the queue engine for this function? [Y/n] ');
    if (/^n(o)?$/i.test(answer.trim())) {
      return;
    }
  }

  try {
    await client.put(`/serverless/sites/${encodeURIComponent(site.id)}/workers`, { enabled: true });
    ok('Queue engine on.');
  } catch (err) {
    warn(`Could not enable the queue engine: ${err.message}`);
  }
}

/**
 * Two phases, two different things to watch. Provisioning the namespace has no
 * SiteDeployment to poll — it lives on the host — and it is where a bad
 * DigitalOcean credential shows up, so it gets followed properly instead of
 * appearing as an endless wait.
 */
async function followInit(client, site, cwd, { noPrompt }) {
  info('');
  info(c.dim('Provisioning the function namespace…'));

  // Ctrl-C means "give me my terminal back", not "destroy the thing I just
  // paid to provision". The deploy runs server-side and .dply/site.json is
  // already written, so detaching orphans nothing — but saying so is the
  // difference between a calm exit and a panic.
  const detach = () => {
    info('');
    info(c.dim('Stopped watching — the deploy is still running.'));
    info(c.dim(`  re-attach: ${c.cyan('dply deploy --wait')}`));
    info(c.dim(`  status:    ${c.cyan('dply serverless status ' + site.name)}`));
    info(c.dim(`  cancel it: ${site.workspace_url}`));
    process.exit(130);
  };
  process.on('SIGINT', detach);

  const deadline = Date.now() + 5 * 60 * 1000;
  let deploymentId = null;

  while (Date.now() < deadline) {
    const current = (await client.get(`/serverless/sites/${encodeURIComponent(site.id)}`))?.data ?? {};

    if (current.provision?.failed) {
      process.off('SIGINT', detach);
      warn(`Provisioning failed: ${current.provision.error ?? 'the namespace could not be created.'}`);

      return offerCleanup(client, site, cwd, { noPrompt });
    }

    const deployments = (await client.get(`/sites/${encodeURIComponent(site.id)}/deployments?limit=1`))?.data ?? [];
    if (deployments.length > 0) {
      deploymentId = deployments[0].id;
      break;
    }

    await sleep(2000);
  }

  if (! deploymentId) {
    process.off('SIGINT', detach);
    warn('The deploy has not started yet. Re-attach with `dply deploy --wait`.');

    return;
  }

  let succeeded = false;
  try {
    const result = await followSiteDeployment(client, site.id, deploymentId);
    succeeded = result?.status === 'success' || result === true;
  } catch (err) {
    warn(err.message);
  }

  process.off('SIGINT', detach);

  if (! succeeded) {
    return offerCleanup(client, site, cwd, { noPrompt });
  }

  await printLiveUrl(client, site);
}

async function printLiveUrl(client, site) {
  const current = (await client.get(`/serverless/sites/${encodeURIComponent(site.id)}`))?.data ?? {};
  const url = current.url;

  info('');
  if (! url) {
    ok('Deployed.');
    info(c.dim(`  ${site.workspace_url}`));

    return;
  }

  // "Deployed" should mean "answering". A URL that 404s ten seconds after a
  // success message is the one outcome worth designing against.
  let live = false;
  try {
    const response = await fetch(url, { method: 'HEAD', redirect: 'follow' });
    live = response.status < 500;
  } catch {
    live = false;
  }

  ok(`Live at ${c.cyan(url)}${live ? '' : c.dim(' (not answering yet — give it a moment)')}`);
  info(c.dim(`  dashboard: ${site.workspace_url}`));
}

/**
 * The undo. Only ever offered for a function that never deployed — which is
 * also the only thing the endpoint will accept.
 */
async function offerCleanup(client, site, cwd, { noPrompt }) {
  info('');
  info(c.dim(`The function exists but has not deployed. Retry with ${c.cyan('dply deploy')}, or open ${site.workspace_url}`));

  if (noPrompt) {
    throw exit('Deploy failed.', 1);
  }

  const choice = await pickRow(
    [
      { id: 'retry', name: 'Try the deploy again' },
      { id: 'keep', name: 'Leave it and open the dashboard' },
      { id: 'delete', name: 'Delete it and start over' },
    ],
    { title: 'What now?' },
  );

  if (choice?.id === 'retry') {
    const { deploy } = await import('./commands.mjs');
    await deploy([], { wait: true });

    return;
  }

  if (choice?.id === 'delete') {
    try {
      const result = await client.delete(`/serverless/sites/${encodeURIComponent(site.id)}`);
      ok(`Deleted ${site.name}.`);
      // A namespace or bucket dply could not reach keeps costing money, so it
      // is named rather than folded into a clean success.
      for (const key of ['remote_error', 'bucket_error']) {
        if (result?.data?.[key]) {
          warn(`…but ${key.replace('_', ' ')}: ${result.data[key]}`);
        }
      }
    } catch (err) {
      warn(`Could not delete it: ${err.message}`);
    }

    return;
  }

  const { openInBrowser } = await import('./commands.mjs');
  await openInBrowser(site.workspace_url);
}

/* --------------------------------------------------------------- helpers */

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

function hasEntryFile(files) {
  return ['main.js', 'main.mjs', 'main.php', 'main.py', 'main.go', 'index.js', 'index.mjs', 'index.html']
    .some((f) => files.includes(f));
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

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function exit(message, code) {
  const err = new Error(message);
  err.exitCode = code;

  return err;
}
