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
  info(c.dim('Health: `dply server health ' + serverId + '` · firewall: `dply server firewall show --server ' + serverId + '`'));

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

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function serverFirewall(args, flags) {
  const sub = args[0] ?? 'show';
  const rest = args.slice(1);

  switch (sub) {
    case 'show':
    case 'list':
    case 'ls':
      return firewallShow(rest, flags);
    case 'apply':
      return firewallApply(rest, flags);
    case 'apply-bundled':
      return firewallApplyBundled(rest, flags);
    case 'apply-template':
      return firewallApplyTemplate(rest, flags);
    case 'help':
    case '--help':
    case '-h':
      return printFirewallHelp();
    default:
      throw cliError(`Unknown firewall subcommand: ${sub}. Run \`dply server firewall help\`.`, 2);
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

async function firewallShow(args, flags) {
  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, args[0]);
  const payload = (await client.get(`/servers/${encodeURIComponent(serverId)}/firewall`))?.data ?? {};

  if (flags.json) {
    printJson(payload);

    return;
  }

  const rules = payload.rules ?? [];
  const templates = payload.templates ?? [];
  const bundledKeys = payload.bundled_template_keys ?? [];

  info(c.bold('Firewall rules'));
  if (rules.length === 0) {
    warn('No rules configured in dply yet.');
  } else {
    printTable(
      ['Name', 'Port', 'Proto', 'Source', 'Action', 'On'],
      rules.map((row) => [
        row.name ?? '—',
        row.port ?? 'any',
        row.protocol ?? '—',
        row.source ?? '—',
        row.action ?? '—',
        row.enabled ? 'yes' : 'no',
      ]),
    );
  }

  if (templates.length > 0) {
    info('');
    info(c.bold('Org templates'));
    for (const row of templates) {
      info(`  ${c.cyan(String(row.name))} ${c.dim(String(row.id))}${row.description ? ` · ${row.description}` : ''}`);
    }
  }

  if (bundledKeys.length > 0) {
    info('');
    info(c.bold('Bundled templates'));
    info(`  ${bundledKeys.join(', ')}`);
  }

  info('');
  info(c.dim('Apply: `dply server firewall apply --server ' + serverId + '` · bundled: `apply-bundled laravel_web`'));

  return 0;
}

async function firewallApply(args, flags) {
  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, args[0]);
  const ack =
    flags['ack-ssh-lockout'] === true
    || flags['acknowledge-ssh-lockout-risk'] === true;

  /** @type {Record<string, unknown>} */
  const body = {};
  if (ack) {
    body.acknowledge_ssh_lockout_risk = true;
  }

  try {
    const response = await client.post(`/servers/${encodeURIComponent(serverId)}/firewall/apply`, body);

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

    ok(response?.message ?? 'Firewall rules applied.');

    return 0;
  } catch (err) {
    if (err?.status === 422 && err?.body?.code === 'ssh_lockout_ack_required') {
      throw cliError(
        'SSH lockout risk — review rules, then re-run with --ack-ssh-lockout after confirming SSH access stays open.',
        2,
      );
    }

    throw err;
  }
}

async function firewallApplyBundled(args, flags) {
  const key = args[0];
  if (!key) {
    throw cliError('Usage: dply server firewall apply-bundled <key> --server <id>', 2);
  }

  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, flags.server ?? args[1]);
  const response = await client.post(
    `/servers/${encodeURIComponent(serverId)}/firewall/bundled/${encodeURIComponent(key)}`,
    {},
  );

  if (flags.json) {
    printJson(response);

    return;
  }

  const created = response?.rules_created;
  ok(response?.message ?? `Bundled template "${key}" applied.${created != null ? ` (${created} rule(s) added)` : ''}`);

  return 0;
}

async function firewallApplyTemplate(args, flags) {
  const templateId = args[0];
  if (!templateId) {
    throw cliError('Usage: dply server firewall apply-template <template-id> --server <id>', 2);
  }

  const client = await requireClient(flags);
  const serverId = await resolveServerId(client, flags, flags.server ?? args[1]);
  const response = await client.post(
    `/servers/${encodeURIComponent(serverId)}/firewall/templates/${encodeURIComponent(templateId)}`,
    {},
  );

  if (flags.json) {
    printJson(response);

    return;
  }

  const created = response?.rules_created;
  ok(response?.message ?? `Template applied.${created != null ? ` (${created} rule(s) added)` : ''}`);

  return 0;
}

function printFirewallHelp() {
  info(`${c.bold('dply server firewall')} — read and apply UFW rules on a BYO server`);
  info('');
  info('  show [--server ID]              List rules, org templates, bundled keys');
  info('  apply [--server ID]             Push dply rules to the VM (UFW)');
  info('      [--ack-ssh-lockout]         Confirm SSH lockout risk when required');
  info('  apply-bundled <key>             Merge a bundled starter ruleset');
  info('  apply-template <template-id>    Merge an org firewall template');

  return 0;
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
