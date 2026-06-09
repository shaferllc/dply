import { handleEmptyProjectList } from './project-prompts.mjs';
import { requireProjectId } from './project-context.mjs';
import { requireClient as requireApiClient } from './server-context.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function projectCommand(args, flags) {
  const sub = args[0];

  if (!sub || sub === '--help' || sub === '-h' || sub === 'help') {
    return printProjectHelp();
  }

  switch (sub) {
    case 'list':
    case 'ls':
      return projectList(flags);
    case 'show':
      return projectShow(args.slice(1), flags);
    case 'create':
      return projectCreate(args.slice(1), flags);
    case 'update':
      return projectUpdate(args.slice(1), flags);
    case 'delete':
    case 'rm':
      return projectDelete(args.slice(1), flags);
    case 'health':
      return projectHealth(args.slice(1), flags);
    case 'deploy':
      return projectDeploy(args.slice(1), flags);
    case 'deploys':
      return projectDeploys(args.slice(1), flags);
    case 'members':
      return projectMembers(args.slice(1), flags);
    case 'attach':
      return projectAttach(args.slice(1), flags);
    case 'detach':
      return projectDetach(args.slice(1), flags);
    case 'environments':
    case 'envs':
      return projectEnvironments(args.slice(1), flags);
    case 'variables':
    case 'vars':
      return projectVariables(args.slice(1), flags);
    case 'runbooks':
      return projectRunbooks(args.slice(1), flags);
    default:
      throw cliError(`Unknown project command: ${sub}. Run \`dply project help\`.`, 2);
  }
}

async function projectList(flags) {
  const client = await requireApiClient(flags);
  const rows = (await client.get('/projects'))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    await handleEmptyProjectList({});

    return;
  }

  printTable(
    ['ID', 'Name', 'Slug', 'Servers', 'Sites', 'Role'],
    rows.map((row) => [
      row.id,
      row.name,
      row.slug,
      row.servers_count ?? '—',
      row.sites_count ?? '—',
      row.role ?? '—',
    ]),
  );
}

async function projectShow(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const data = (await client.get(`/projects/${encodeURIComponent(projectId)}`))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return;
  }

  info(c.bold(data.name ?? 'Project'));
  printKeyValues([
    ['ID', data.id ?? '—'],
    ['Slug', data.slug ?? '—'],
    ['Description', data.description ?? '—'],
    ['Your role', data.role ?? '—'],
    ['Servers', String(data.servers_count ?? data.servers?.length ?? 0)],
    ['Sites', String(data.sites_count ?? data.sites?.length ?? 0)],
  ]);

  if ((data.servers ?? []).length > 0) {
    info('');
    info(c.bold('Servers'));
    for (const server of data.servers) {
      info(`  ${c.cyan(server.name)} ${c.dim(server.id)} · ${server.status}`);
    }
  }

  if ((data.sites ?? []).length > 0) {
    info('');
    info(c.bold('Sites'));
    for (const site of data.sites) {
      info(`  ${c.cyan(site.name)} ${c.dim(site.id)} · ${site.status}`);
    }
  }

  return 0;
}

async function projectCreate(args, flags) {
  let name = flags.name || flags.n || args.join(' ').trim();

  if (!name) {
    const { promptCreateProjectInteractive } = await import('./project-prompts.mjs');
    const created = await promptCreateProjectInteractive({ skipConfirm: true });

    if (!created) {
      throw cliError('Usage: dply project create --name "My project" [--description "..."]', 2);
    }

    return 0;
  }

  const client = await requireApiClient(flags);
  const body = { name: String(name) };
  if (flags.description) body.description = String(flags.description);
  if (flags.notes) body.notes = String(flags.notes);

  const data = (await client.post('/projects', body))?.data ?? {};
  ok(`Project created: ${c.cyan(data.name)} (${data.id})`);

  return 0;
}

async function projectUpdate(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const body = {};
  if (flags.name || flags.n) body.name = String(flags.name || flags.n);
  if (flags.description !== undefined) body.description = String(flags.description);
  if (flags.notes !== undefined) body.notes = String(flags.notes);

  if (Object.keys(body).length === 0) {
    throw cliError('Pass at least one of --name, --description, or --notes.', 2);
  }

  const data = (await client.patch(`/projects/${encodeURIComponent(projectId)}`, body))?.data ?? {};
  ok(`Project updated: ${c.cyan(data.name)}`);

  return 0;
}

async function projectDelete(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const response = await client.delete(`/projects/${encodeURIComponent(projectId)}`);
  ok(response?.message ?? 'Project deleted.');

  return 0;
}

async function projectHealth(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const data = (await client.get(`/projects/${encodeURIComponent(projectId)}/health`))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return;
  }

  info(c.bold('Project health'));
  printKeyValues([
    ['Status', data.status_label ?? '—'],
    ['Healthy', data.healthy ? 'yes' : 'no'],
    ['Servers ready', `${data.servers_ready ?? 0}/${data.servers_total ?? 0}`],
    ['Sites w/ active SSL', `${data.sites_active_ssl ?? 0}/${data.sites_total ?? 0}`],
    ['Pending deploys', String(data.pending_deploys ?? 0)],
  ]);

  if ((data.issues ?? []).length > 0) {
    info('');
    info(c.bold('Issues'));
    for (const issue of data.issues) {
      warn(issue);
    }
  }

  return 0;
}

async function projectDeploy(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const body = {};

  const siteIds = flags.site || flags.s;
  if (siteIds) {
    body.site_ids = Array.isArray(siteIds) ? siteIds : String(siteIds).split(',').map((s) => s.trim()).filter(Boolean);
  }

  const response = await client.post(`/projects/${encodeURIComponent(projectId)}/deploy`, body);
  const data = response?.data ?? {};
  ok(response?.message ?? 'Project deploy queued.');
  if (data.id) {
    info(c.dim(`Deploy run: ${data.id}`));
  }

  return 0;
}

async function projectDeploys(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const limit = flags.limit ?? 20;
  const rows = (await client.get(`/projects/${encodeURIComponent(projectId)}/deploys?limit=${encodeURIComponent(limit)}`))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No project deploy runs yet.');

    return;
  }

  printTable(
    ['ID', 'Status', 'Sites', 'Started', 'Finished'],
    rows.map((row) => [
      row.id,
      row.status,
      (row.site_ids ?? []).length,
      formatWhen(row.started_at),
      formatWhen(row.finished_at),
    ]),
  );
}

async function projectMembers(args, flags) {
  const action = args[0] ?? 'list';
  const rest = args.slice(1);

  if (action === 'list' || action === 'ls') {
    return projectMembersList(rest, flags);
  }

  if (action === 'add' || action === 'set') {
    return projectMembersAdd(rest, flags);
  }

  if (action === 'remove' || action === 'rm') {
    return projectMembersRemove(rest, flags);
  }

  throw cliError(`Unknown members subcommand: ${action}. Use list | add | remove.`, 2);
}

async function projectMembersList(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const rows = (await client.get(`/projects/${encodeURIComponent(projectId)}/members`))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  printTable(
    ['ID', 'User', 'Email', 'Role'],
    rows.map((row) => [row.id, row.name ?? '—', row.email ?? '—', row.role ?? '—']),
  );
}

async function projectMembersAdd(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const userId = flags.user || flags.u || args[1];
  const role = flags.role || flags.r || 'viewer';

  if (!userId) {
    throw cliError('Usage: dply project members add <project> --user USER_ID --role viewer|deployer|maintainer|owner', 2);
  }

  await client.post(`/projects/${encodeURIComponent(projectId)}/members`, {
    user_id: String(userId),
    role: String(role),
  });

  ok('Project member saved.');

  return 0;
}

async function projectMembersRemove(args, flags) {
  const client = await requireApiClient(flags);
  const projectId = await requireProjectId(flags, args[0]);
  const memberId = args[1];

  if (!memberId) {
    throw cliError('Usage: dply project members remove <project> <member-id>', 2);
  }

  await client.delete(`/projects/${encodeURIComponent(projectId)}/members/${encodeURIComponent(memberId)}`);
  ok('Project member removed.');

  return 0;
}

async function projectAttach(args, flags) {
  const kind = args[0];
  const projectId = await requireProjectId(flags, args[1]);
  const resourceId = args[2] || flags.server || flags.site || flags.id;

  if (!kind || !resourceId) {
    throw cliError('Usage: dply project attach server|site <project> <resource-id>', 2);
  }

  const client = await requireApiClient(flags);

  if (kind === 'server') {
    await client.post(`/projects/${encodeURIComponent(projectId)}/servers/${encodeURIComponent(resourceId)}/attach`, {});
    ok('Server added to project.');

    return;
  }

  if (kind === 'site') {
    await client.post(`/projects/${encodeURIComponent(projectId)}/sites/${encodeURIComponent(resourceId)}/attach`, {});
    ok('Site added to project.');

    return;
  }

  throw cliError('Attach kind must be server or site.', 2);
}

async function projectDetach(args, flags) {
  const kind = args[0];
  const projectId = await requireProjectId(flags, args[1]);
  const resourceId = args[2] || flags.server || flags.site || flags.id;

  if (!kind || !resourceId) {
    throw cliError('Usage: dply project detach server|site <project> <resource-id>', 2);
  }

  const client = await requireApiClient(flags);

  if (kind === 'server') {
    await client.delete(`/projects/${encodeURIComponent(projectId)}/servers/${encodeURIComponent(resourceId)}/detach`);
    ok('Server removed from project.');

    return;
  }

  if (kind === 'site') {
    await client.delete(`/projects/${encodeURIComponent(projectId)}/sites/${encodeURIComponent(resourceId)}/detach`);
    ok('Site removed from project.');

    return;
  }

  throw cliError('Detach kind must be server or site.', 2);
}

async function projectEnvironments(args, flags) {
  const action = args[0] ?? 'list';
  const rest = args.slice(1);

  if (action === 'list' || action === 'ls') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const rows = (await client.get(`/projects/${encodeURIComponent(projectId)}/environments`))?.data ?? [];

    if (flags.json) {
      printJson(rows);

      return;
    }

    printTable(['ID', 'Name', 'Slug'], rows.map((row) => [row.id, row.name, row.slug]));

    return;
  }

  if (action === 'add' || action === 'create') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const name = flags.name || flags.n || rest[1];
    if (!name) {
      throw cliError('Usage: dply project environments add <project> --name "Staging"', 2);
    }

    await client.post(`/projects/${encodeURIComponent(projectId)}/environments`, {
      name: String(name),
      description: flags.description ? String(flags.description) : undefined,
    });
    ok('Environment added.');

    return;
  }

  if (action === 'remove' || action === 'rm') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const environmentId = rest[1];
    if (!environmentId) {
      throw cliError('Usage: dply project environments remove <project> <environment-id>', 2);
    }

    await client.delete(`/projects/${encodeURIComponent(projectId)}/environments/${encodeURIComponent(environmentId)}`);
    ok('Environment removed.');

    return;
  }

  throw cliError('Use: environments list | add | remove', 2);
}

async function projectVariables(args, flags) {
  const action = args[0] ?? 'list';
  const rest = args.slice(1);

  if (action === 'list' || action === 'ls') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const rows = (await client.get(`/projects/${encodeURIComponent(projectId)}/variables`))?.data ?? [];

    if (flags.json) {
      printJson(rows);

      return;
    }

    printTable(['Key', 'Secret', 'Has value'], rows.map((row) => [
      row.key,
      row.is_secret ? 'yes' : 'no',
      row.has_value ? 'yes' : 'no',
    ]));

    return;
  }

  if (action === 'set') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const pair = rest[1] || flags.key;
    let key;
    let value;

    if (pair && String(pair).includes('=')) {
      const idx = String(pair).indexOf('=');
      key = String(pair).slice(0, idx);
      value = String(pair).slice(idx + 1);
    } else {
      key = pair;
      value = flags.value ?? '';
    }

    if (!key) {
      throw cliError('Usage: dply project variables set <project> KEY=value', 2);
    }

    await client.put(`/projects/${encodeURIComponent(projectId)}/variables`, {
      key: String(key),
      value: String(value),
      secret: flags.secret !== false && flags['no-secret'] !== true,
    });
    ok(`Variable ${String(key).toUpperCase()} saved.`);

    return;
  }

  if (action === 'remove' || action === 'rm') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const variableId = rest[1];
    if (!variableId) {
      throw cliError('Usage: dply project variables remove <project> <variable-id>', 2);
    }

    await client.delete(`/projects/${encodeURIComponent(projectId)}/variables/${encodeURIComponent(variableId)}`);
    ok('Project variable removed.');

    return;
  }

  throw cliError('Use: variables list | set | remove', 2);
}

async function projectRunbooks(args, flags) {
  const action = args[0] ?? 'list';
  const rest = args.slice(1);

  if (action === 'list' || action === 'ls') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const rows = (await client.get(`/projects/${encodeURIComponent(projectId)}/runbooks`))?.data ?? [];

    if (flags.json) {
      printJson(rows);

      return;
    }

    printTable(['ID', 'Title', 'URL'], rows.map((row) => [row.id, row.title, row.url ?? '—']));

    return;
  }

  if (action === 'add' || action === 'create') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const title = flags.title || flags.t || rest[1];
    if (!title) {
      throw cliError('Usage: dply project runbooks add <project> --title "Rollback" [--url URL]', 2);
    }

    await client.post(`/projects/${encodeURIComponent(projectId)}/runbooks`, {
      title: String(title),
      url: flags.url ? String(flags.url) : undefined,
      body: flags.body ? String(flags.body) : undefined,
    });
    ok('Runbook saved.');

    return;
  }

  if (action === 'remove' || action === 'rm') {
    const client = await requireApiClient(flags);
    const projectId = await requireProjectId(flags, rest[0]);
    const runbookId = rest[1];
    if (!runbookId) {
      throw cliError('Usage: dply project runbooks remove <project> <runbook-id>', 2);
    }

    await client.delete(`/projects/${encodeURIComponent(projectId)}/runbooks/${encodeURIComponent(runbookId)}`);
    ok('Runbook removed.');

    return;
  }

  throw cliError('Use: runbooks list | add | remove', 2);
}

function printProjectHelp() {
  info(`${c.bold('dply project')} — org projects (grouped servers + sites)`);
  info('');
  info(`  ${'list'.padEnd(16)} ${c.dim('List projects in this organization')}`);
  info(`  ${'show'.padEnd(16)} ${c.dim('Project details + attached resources')}`);
  info(`  ${'create'.padEnd(16)} ${c.dim('--name "…" [--description]')}`);
  info(`  ${'update'.padEnd(16)} ${c.dim('<project> --name|--description|--notes')}`);
  info(`  ${'delete'.padEnd(16)} ${c.dim('Remove project grouping (org admin)')}`);
  info(`  ${'health'.padEnd(16)} ${c.dim('Health summary for grouped resources')}`);
  info(`  ${'deploy'.padEnd(16)} ${c.dim('Queue deploy for all or selected sites')}`);
  info(`  ${'deploys'.padEnd(16)} ${c.dim('Recent project deploy runs')}`);
  info(`  ${'members'.padEnd(16)} ${c.dim('list | add | remove')}`);
  info(`  ${'attach'.padEnd(16)} ${c.dim('server|site <project> <resource-id>')}`);
  info(`  ${'detach'.padEnd(16)} ${c.dim('server|site <project> <resource-id>')}`);
  info(`  ${'environments'.padEnd(16)} ${c.dim('list | add | remove')}`);
  info(`  ${'variables'.padEnd(16)} ${c.dim('list | set KEY=val | remove')}`);
  info(`  ${'runbooks'.padEnd(16)} ${c.dim('list | add | remove')}`);
  info('');
  info(c.dim('Context: --project <id-or-slug> · $DPLY_PROJECT'));

  return 0;
}

function formatWhen(iso) {
  if (!iso) return '—';

  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

function cliError(message, exitCode = 1) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}
