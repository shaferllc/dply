import { ApiClient } from './api.mjs';
import { requireClient, resolveServerId } from './server-context.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

export async function serverList(args, flags) {
  const client = await requireClient(flags);
  const response = await client.get('/servers');
  const rows = response?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No servers visible to this token.');
    info(c.dim('Add a VM in the dply web app · shortcut: `dply servers`'));
    info(c.dim('Missing permissions? Try `dply auth refresh` or `dply r`.'));

    return;
  }

  printTable(
    ['ID', 'Name', 'Status', 'Provider', 'IP'],
    rows.map((row) => [
      row.id,
      row.name,
      row.status,
      row.provider,
      row.ip_address ?? '—',
    ]),
  );
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverShow(args, flags) {
  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, args[0]);
  const servers = (await client.get('/servers'))?.data ?? [];
  const server = servers.find((row) => row.id === serverId);

  if (!server) {
    throw cliError(`Server ${serverId} not found.`, 1);
  }

  const sites = (await client.get('/sites'))?.data ?? [];
  const siteRows = sites.filter((row) => row.server_id === serverId);

  if (flags.json) {
    printJson({ ...server, sites: siteRows });

    return;
  }

  info(c.bold(String(server.name ?? 'Server')));
  printKeyValues([
    ['ID', server.id ?? '—'],
    ['Status', server.status ?? '—'],
    ['Provider', server.provider ?? '—'],
    ['IP', server.ip_address ?? '—'],
    ['Created', server.created_at ?? '—'],
    ['Sites', String(siteRows.length)],
  ]);

  if (siteRows.length > 0) {
    info('');
    info(c.bold('Sites on this server'));
    for (const site of siteRows) {
      info(`  ${c.cyan(String(site.name))} ${c.dim(String(site.id))} · ${site.status ?? '—'}`);
    }
  }

  info('');
  info(c.dim('Health: `dply server health ' + serverId + '` · system users: `dply server system-users list --server ' + serverId + '`'));

  return 0;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverHealth(args, flags) {
  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, args[0]);
  const servers = (await client.get('/servers'))?.data ?? [];
  const server = servers.find((row) => row.id === serverId);

  if (!server) {
    throw cliError(`Server ${serverId} not found.`, 1);
  }

  /** @type {Array<Record<string, unknown>>} */
  let findings = [];
  let insightsForbidden = false;

  try {
    findings = (await client.get(`/servers/${encodeURIComponent(serverId)}/insights`))?.data ?? [];
  } catch (err) {
    if (err?.status === 403) {
      insightsForbidden = true;
    } else {
      throw err;
    }
  }

  if (flags.json) {
    printJson({ server, findings, insights_forbidden: insightsForbidden });

    return;
  }

  info(c.bold(String(server.name ?? 'Server')));
  printKeyValues([
    ['Status', server.status ?? '—'],
    ['IP', server.ip_address ?? '—'],
  ]);

  info('');
  info(c.bold('Insights'));

  if (findings.length === 0) {
    if (insightsForbidden) {
      warn('Insights unavailable — token may need insights.read. Try `dply auth refresh`.');
    } else {
      ok('No open insight findings.');
    }
  } else {
    const critical = findings.filter((row) => row.severity === 'critical').length;
    const warning = findings.filter((row) => row.severity === 'warning').length;
    warn(`${findings.length} open finding(s) · ${critical} critical · ${warning} warning`);

    for (const row of findings.slice(0, 5)) {
      info(`  ${c.cyan(String(row.severity ?? 'info'))}  ${row.title ?? row.insight_key ?? 'Finding'}`);
    }

    if (findings.length > 5) {
      info(c.dim(`  … and ${findings.length - 5} more`));
    }
  }

  return 0;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverRun(args, flags) {
  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, undefined);
  const command = args.join(' ').trim() || String(flags.command || flags.c || '').trim();

  if (!command) {
    throw cliError('Usage: dply server run --server <id> <command…> · or --command "…"', 2);
  }

  const response = await client.post(`/servers/${encodeURIComponent(serverId)}/run-command`, { command });

  if (flags.json) {
    printJson(response);

    return;
  }

  if (response.output) {
    process.stdout.write(String(response.output));
    if (!String(response.output).endsWith('\n')) {
      process.stdout.write('\n');
    }
  }

  ok(response?.message ?? 'Command completed.');

  return 0;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverSystemUsers(args, flags) {
  const sub = args[0] ?? 'list';
  const rest = args.slice(1);

  switch (sub) {
    case 'list':
      return systemUsersList(rest, flags);
    case 'sync':
      return systemUsersSync(rest, flags);
    case 'add':
      return systemUsersAdd(rest, flags);
    case 'update':
      return systemUsersUpdate(rest, flags);
    case 'remove':
    case 'rm':
      return systemUsersRemove(rest, flags);
    case 'help':
    case '--help':
    case '-h':
      return printSystemUsersHelp();
    default:
      throw cliError(`Unknown system-users subcommand: ${sub}. Run \`dply server system-users help\`.`, 2);
  }
}

async function systemUsersList(args, flags) {
  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, args[0]);
  const response = await client.get(`/servers/${serverId}/system-users`);
  const rows = response?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No system users in the dply snapshot — run `dply server system-users sync` first.');

    return;
  }

  printTable(
    ['Username', 'UID', 'Sites', 'Shell', 'Protected'],
    rows.map((row) => [
      row.username,
      row.uid ?? '—',
      String(row.site_count ?? 0),
      row.shell ?? '—',
      row.is_protected ? 'yes' : 'no',
    ]),
  );
}

async function systemUsersSync(args, flags) {
  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, args[0]);
  const response = await client.post(`/servers/${serverId}/system-users/sync`, {});
  ok(response?.message ?? 'Sync queued.');
}

async function systemUsersAdd(args, flags) {
  const username = args[0];
  if (!username) {
    throw cliError('Usage: dply server system-users add <username> --server <id>', 2);
  }

  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, flags.server ?? args[1]);

  const body = {
    username,
    sudo: flags.sudo === true,
    shell: flags.shell ?? '/bin/bash',
    web_group: flags['no-web-group'] !== true,
  };

  const response = await client.post(`/servers/${serverId}/system-users`, body);
  ok(response?.message ?? `Queued creation of ${username}.`);
}

async function systemUsersUpdate(args, flags) {
  const username = args[0];
  if (!username) {
    throw cliError('Usage: dply server system-users update <username> [--shell …] [--sudo] [--no-sudo] [--web-group] [--no-web-group] --server <id>', 2);
  }

  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, flags.server ?? args[1]);

  /** @type {Record<string, unknown>} */
  const body = {};
  if (flags.shell) {
    body.shell = flags.shell;
  }
  if (flags.sudo === true) {
    body.sudo = true;
  }
  if (flags['no-sudo'] === true) {
    body.sudo = false;
  }
  if (flags['web-group'] === true) {
    body.web_group = true;
  }
  if (flags['no-web-group'] === true) {
    body.web_group = false;
  }

  if (Object.keys(body).length === 0) {
    throw cliError('Provide at least one of --shell, --sudo/--no-sudo, --web-group/--no-web-group.', 2);
  }

  const response = await client.patch(`/servers/${serverId}/system-users/${encodeURIComponent(username)}`, body);
  ok(response?.message ?? `Queued update of ${username}.`);
}

async function systemUsersRemove(args, flags) {
  const username = args[0];
  if (!username) {
    throw cliError('Usage: dply server system-users remove <username> --server <id>', 2);
  }

  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, flags.server ?? args[1]);
  const response = await client.delete(`/servers/${serverId}/system-users/${encodeURIComponent(username)}`);
  ok(response?.message ?? `Queued removal of ${username}.`);
}

function printSystemUsersHelp() {
  info(`${c.bold('dply server system-users')} — manage Linux accounts on a BYO server`);
  info('');
  info('  list [--server ID]              List users from the dply snapshot');
  info('  sync [--server ID]              SSH-sync /etc/passwd into dply');
  info('  add <user> [--server ID]        Queue user creation');
  info('      [--shell /bin/bash|/bin/sh|/usr/sbin/nologin]');
  info('      [--sudo] [--no-web-group]');
  info('  update <user> [--server ID]     Queue shell / sudo / web-group changes');
  info('      [--shell …] [--sudo|--no-sudo] [--web-group|--no-web-group]');
  info('  remove <user> [--server ID]     Queue user removal');

  return 0;
}

/**
 * @param {string} message
 * @param {number} [code]
 */
function cliError(message, code = 1) {
  const err = new Error(message);
  err.exitCode = code;

  return err;
}
