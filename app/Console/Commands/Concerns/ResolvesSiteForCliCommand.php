<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Site;
use App\Models\User;

/**
 * Shared helpers for the granular dply:wp:* / dply:laravel:* /
 * dply:snapshot:* commands. Each one needs the same
 * resolve-site-by-name + resolve-acting-user-by-email plumbing,
 * so we don't repeat it eight times.
 */
trait ResolvesSiteForCliCommand
{
    protected function resolveSite(string $nameOrSlug): ?Site
    {
        return Site::query()
            ->where(function ($q) use ($nameOrSlug) {
                $q->where('name', $nameOrSlug)->orWhere('slug', $nameOrSlug);
            })
            ->first();
    }

    protected function resolveActingUser(Site $site, ?string $email): ?User
    {
        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return $site->user ?? null;
    }
}
