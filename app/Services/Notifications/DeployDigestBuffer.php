<?php

namespace App\Services\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DeployDigestBuffer
{
    protected const CACHE_PREFIX = 'deploy-digest-lines:';

    public static function record(int $organizationId, string $line): void
    {
        $key = self::CACHE_PREFIX.$organizationId;
        $lines = Cache::get($key, []);
        $lines[] = now()->toIso8601String().' '.$line;
        Cache::put($key, $lines, now()->addDays(2));
    }

    /**
     * @return array<int, string>
     */
    public static function pull(int $organizationId): array
    {
        $key = self::CACHE_PREFIX.$organizationId;
        $lines = Cache::pull($key, []);

        return is_array($lines) ? $lines : [];
    }

    public static function flushAll(): void
    {
        $hours = (int) config('dply.deploy_digest_hours', 0);
        if ($hours <= 0) {
            return;
        }

        $orgIds = Organization::query()->pluck('id');
        foreach ($orgIds as $id) {
            $lines = self::pull((int) $id);
            if ($lines === []) {
                continue;
            }
            $org = Organization::query()->find($id);
            if (! $org || ! $org->wantsDeployEmailNotifications()) {
                continue;
            }
            $recipients = $org->users()
                ->wherePivotIn('role', ['owner', 'admin'])
                ->get();
            if ($recipients->isEmpty()) {
                continue;
            }
            $body = implode("\n", array_map(fn ($l) => Str::limit($l, 500), $lines));
            foreach ($recipients as $user) {
                /** @var User $user */
                if (! $user->email) {
                    continue;
                }
                Mail::raw(
                    "Deploy activity digest (last ~{$hours}h)\n\n".$body,
                    fn ($m) => $m->to($user->email)->subject('['.config('app.name').'] Deploy digest')
                );
            }
        }
    }
}
