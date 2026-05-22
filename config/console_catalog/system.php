<?php

/**
 * Console catalog — System section.
 *
 * Always shown (no `requires_any_tags`). Covers the read-only "look at the box"
 * commands every Linux server has.
 */
return [
    'label' => 'System',
    'description' => 'Inspect host resources, processes, and recent activity.',
    'entries' => [
        ['command' => 'uptime', 'description' => 'Load average and uptime.'],
        ['command' => 'free -h', 'description' => 'Memory usage (human-readable).'],
        ['command' => 'df -h', 'description' => 'Disk usage per mount.'],
        ['command' => 'du -sh /var/log/* 2>/dev/null | sort -h | tail -n 20', 'description' => 'Largest log dirs/files.'],
        ['command' => 'ps -eo pid,user,pcpu,pmem,comm --sort=-pcpu | head -n 15', 'description' => 'Top processes by CPU.'],
        ['command' => 'ps -eo pid,user,pcpu,pmem,comm --sort=-pmem | head -n 15', 'description' => 'Top processes by memory.'],
        ['command' => 'ss -tulpn 2>/dev/null | head -n 30', 'description' => 'Listening TCP/UDP ports.'],
        ['command' => 'who', 'description' => 'Currently logged-in users.'],
        ['command' => 'last -n 20', 'description' => 'Recent login history.'],
        ['command' => 'uname -a', 'description' => 'Kernel and architecture.'],
        ['command' => 'cat /etc/os-release', 'description' => 'Distro and release info.'],
        ['command' => 'journalctl -p err -n 50 --no-pager', 'description' => 'Recent system errors from journald.'],
        ['command' => 'systemctl --failed --no-pager', 'description' => 'Failed units.'],
        ['command' => 'date', 'description' => 'Current server time and timezone.'],
    ],
];
