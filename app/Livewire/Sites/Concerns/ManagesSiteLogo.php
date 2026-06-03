<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Services\Sites\SiteFaviconFetcher;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Custom site logo management for the settings workspace: upload an image,
 * pull the live site's favicon, or remove it. Logos live on the `public` disk
 * under site-logos/; the model exposes them via {@see \App\Models\Site::logoUrl()}.
 *
 * Raster formats only (png/jpg/webp/gif/ico) — SVG is rejected to avoid serving
 * script-bearing SVGs from our own origin.
 */
trait ManagesSiteLogo
{
    use WithFileUploads;

    public $site_logo_upload = null;

    /** Fires when a file is chosen — validate, store, and set it immediately. */
    public function updatedSiteLogoUpload(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'site_logo_upload' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon',
                'max:1024', // KB
            ],
        ], attributes: ['site_logo_upload' => __('logo')]);

        $old = $this->site->logo_path;
        $ext = $this->extensionFor($this->site_logo_upload->getMimeType());
        $path = 'site-logos/'.$this->site->id.'-'.Str::lower(Str::random(8)).'.'.$ext;

        Storage::disk('public')->put($path, file_get_contents($this->site_logo_upload->getRealPath()));
        $this->site->forceFill(['logo_path' => $path])->save();

        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->reset('site_logo_upload');
        $this->recordLogoChange($old, $path, 'upload');
        session()->flash('logo_status', __('Logo updated.'));
    }

    /** Pull the favicon from the site's live URL (inline; tight HTTP timeout). */
    public function pullSiteLogoFromFavicon(SiteFaviconFetcher $fetcher): void
    {
        $this->authorize('update', $this->site);

        try {
            $old = $this->site->logo_path;
            $path = $fetcher->fetch($this->site);
            $this->site->forceFill(['logo_path' => $path])->save();
            $this->recordLogoChange($old, $path, 'favicon');
            session()->flash('logo_status', __('Pulled the site favicon.'));
        } catch (\Throwable $e) {
            session()->flash('logo_error', $e->getMessage());
        }
    }

    public function removeSiteLogo(): void
    {
        $this->authorize('update', $this->site);

        $old = $this->site->logo_path;
        if (is_string($old) && $old !== '') {
            Storage::disk('public')->delete($old);
            $this->site->forceFill(['logo_path' => null])->save();
            $this->recordLogoChange($old, null, 'remove');
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

    private function recordLogoChange(?string $old, ?string $new, string $source): void
    {
        if ($this->site->organization) {
            audit_log(
                $this->site->organization,
                auth()->user(),
                'site.logo.updated',
                $this->site,
                ['logo_path' => $old],
                ['logo_path' => $new, 'source' => $source],
            );
        }
    }
}
