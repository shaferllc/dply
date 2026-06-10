<?php

namespace App\Livewire\Organizations;

use App\Actions\Organizations\DeleteOrganizationAction;
use App\Models\Organization;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

#[Layout('layouts.app')]
class Settings extends Component
{
    use WithFileUploads;

    public Organization $organization;

    public string $name = '';

    public string $slug = '';

    public string $email = '';

    public string $description = '';

    public string $timezone = '';

    public $org_icon_upload = null;

    public string $delete_confirm = '';

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);

        // The route-bound model is already fresh — hydrate directly off it.
        $this->organization = $organization;
        $this->name = (string) $organization->name;
        $this->slug = (string) $organization->slug;
        $this->email = (string) ($organization->email ?? '');
        $this->description = (string) ($organization->description ?? '');
        $this->timezone = (string) ($organization->timezone ?? '');
    }

    public function saveGeneral(): void
    {
        $this->authorize('update', $this->organization);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('organizations', 'slug')->ignore($this->organization->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL))],
        ]);

        $before = $this->organization->only(['name', 'slug', 'email', 'description', 'timezone']);

        $this->organization->update([
            'name' => $validated['name'],
            'slug' => Str::lower($validated['slug']),
            'email' => $validated['email'] ?: null,
            'description' => $validated['description'] ?: null,
            'timezone' => $validated['timezone'] ?: null,
        ]);

        $this->slug = (string) $this->organization->slug;

        audit_log(
            $this->organization,
            auth()->user(),
            'organization.updated',
            $this->organization,
            $before,
            $this->organization->only(['name', 'slug', 'email', 'description', 'timezone']),
        );

        session()->flash('settings_status', __('Organization settings saved.'));
    }

    /** Fires when an icon file is chosen — validate, store, set it immediately. */
    public function updatedOrgIconUpload(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'org_icon_upload' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon',
                'max:1024', // KB
            ],
        ], attributes: ['org_icon_upload' => __('icon')]);

        $old = $this->organization->icon_path;
        $ext = $this->extensionFor($this->org_icon_upload->getMimeType());
        $path = 'org-logos/'.$this->organization->id.'-'.Str::lower(Str::random(8)).'.'.$ext;

        Storage::disk('public')->put($path, file_get_contents($this->org_icon_upload->getRealPath()));
        $this->organization->forceFill(['icon_path' => $path])->save();

        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->reset('org_icon_upload');
        $this->recordIconChange($old, $path);
        session()->flash('settings_status', __('Icon updated.'));
    }

    public function removeOrgIcon(): void
    {
        $this->authorize('update', $this->organization);

        $old = $this->organization->icon_path;
        if (is_string($old) && $old !== '') {
            Storage::disk('public')->delete($old);
            $this->organization->forceFill(['icon_path' => null])->save();
            $this->recordIconChange($old, null);
        }

        session()->flash('settings_status', __('Icon removed.'));
    }

    public function deleteOrganization(DeleteOrganizationAction $action): mixed
    {
        $this->authorize('delete', $this->organization);

        $this->validate([
            'delete_confirm' => ['required', 'same:name'],
        ], [
            'delete_confirm.same' => __('Type the organization name exactly to confirm.'),
        ]);

        $action->handle($this->organization, auth()->user());

        // Land the user on another organization they belong to.
        $next = auth()->user()->organizations()->first();
        if ($next) {
            session(['current_organization_id' => $next->id]);
        } else {
            Session::forget('current_organization_id');
        }
        Session::forget('current_team_id');

        Session::flash('success', __('Organization deleted.'));

        return $this->redirect(route('organizations.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.organizations.settings', [
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::ALL),
        ]);
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

    private function recordIconChange(?string $old, ?string $new): void
    {
        audit_log(
            $this->organization,
            auth()->user(),
            'organization.icon.updated',
            $this->organization,
            ['icon_path' => $old],
            ['icon_path' => $new],
        );
    }
}
