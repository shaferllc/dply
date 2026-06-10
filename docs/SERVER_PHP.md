---
title: "PHP workspace"
slug: server-php
category: "Servers"
order: 320
description: "Manages PHP versions and FPM pools on a server via mise, letting you install, enable, upgrade, and uninstall versions for sites to select."
group: servers
---

# PHP workspace

The **PHP** section manages PHP versions and pools on the server via mise — install, enable, upgrade, and uninstall.

## Version rows

Each installed PHP version shows:

- **Version** label (8.2, 8.3, etc.)
- **Status** — active (mise `use`) or installed-only
- **Actions** — **Enable**, **Upgrade**, **Uninstall**

Installed-but-not-activated versions need **Enable** before sites can select them.

## FPM pools

Configure pool settings per version where exposed. Sites pick the active version under **Site → Runtime → PHP**.

## Console actions

Install and upgrade queue remote jobs with streaming output and automatic reprobe after completion.

## Laravel sites

New Laravel Docker/VM sites may auto-run `key:generate` on deploy to avoid 500 errors — ensure the selected PHP version matches `composer.json`.

## Related sections

- **Manage** — mise runtimes including PHP
- **Webserver** — PHP-FPM integration
- **Site → Laravel** — artisan and queue defaults
