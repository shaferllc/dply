<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Renames the old local seed organization (slug "local-dev") to a normal workspace name/slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = Organization::query()->where('slug', 'local-dev')->first();
        if (! $legacy) {
            return;
        }

        $email = (string) ($legacy->email ?: 'tj@tjshafer.com');
        $firstUser = $legacy->users()->first();
        $workspaceName = $firstUser
            ? trim($firstUser->name).'’s workspace'
            : 'Workspace';

        $baseSlug = Str::slug(str_replace('@', '-at-', $email));
        $slug = $baseSlug;
        $suffix = 0;
        while (Organization::query()
            ->where('slug', $slug)
            ->where('id', '!=', $legacy->id)
            ->exists()) {
            $suffix++;
            $slug = $baseSlug.'-'.$suffix;
        }

        $legacy->forceFill([
            'name' => $workspaceName,
            'slug' => $slug,
            'email' => $email,
        ])->save();
    }

    public function down(): void
    {
        // Non-destructive: do not restore "local-dev" slug in down().
    }
};
