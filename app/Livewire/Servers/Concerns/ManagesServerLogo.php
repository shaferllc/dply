<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Custom server logo management for the manage workspace: upload an image or
 * remove it. Logos live on the durable `site_assets` disk under server-logos/
 * (release-independent, so they survive a redeploy); the model exposes them via
 * {@see \App\Models\Server::logoUrl()}. Mirrors
 * {@see \App\Livewire\Sites\Concerns\ManagesSiteLogo} — servers have no public
 * URL, so there's no favicon-pull variant.
 *
 * Raster formats only (png/jpg/webp/gif/ico) — SVG is rejected to avoid serving
 * script-bearing SVGs from our own origin.
 */
trait ManagesServerLogo
{
    use WithFileUploads;

    public $server_logo_upload = null;

    /** Fires when a file is chosen — validate, store, and set it immediately. */
    public function updatedServerLogoUpload(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'server_logo_upload' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon',
                'max:1024', // KB
            ],
        ], attributes: ['server_logo_upload' => __('logo')]);

        $old = $this->server->logo_path;
        $ext = $this->extensionFor($this->server_logo_upload->getMimeType());
        $path = 'server-logos/'.$this->server->id.'-'.Str::lower(Str::random(8)).'.'.$ext;

        Storage::disk('site_assets')->put($path, file_get_contents($this->server_logo_upload->getRealPath()));
        $this->server->forceFill(['logo_path' => $path])->save();

        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('site_assets')->delete($old);
        }

        $this->reset('server_logo_upload');
        $this->recordServerLogoChange($old, $path, 'upload');
        session()->flash('logo_status', __('Logo updated.'));
    }

    public function removeServerLogo(): void
    {
        $this->authorize('update', $this->server);

        $old = $this->server->logo_path;
        if (is_string($old) && $old !== '') {
            Storage::disk('site_assets')->delete($old);
            $this->server->forceFill(['logo_path' => null])->save();
            $this->recordServerLogoChange($old, null, 'remove');
        }

        session()->flash('logo_status', __('Logo removed.'));
    }

    private function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'png',
        };
    }

    private function recordServerLogoChange(?string $old, ?string $new, string $source): void
    {
        if ($this->server->organization) {
            audit_log(
                $this->server->organization,
                auth()->user(),
                'server.logo.updated',
                $this->server,
                ['logo_path' => $old],
                ['logo_path' => $new, 'source' => $source],
            );
        }
    }
}
