<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteGitSource;
use App\Modules\WordPress\Jobs\SyncSiteGitSourceJob;
use App\Modules\WordPress\Materializers\GitSourceMaterializerFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Themes and plugins from their own Git repositories.
 *
 * WordPress-only, and layout-agnostic by design: the operator adds "this theme
 * lives at this repo" and {@see GitSourceMaterializerFactory} decides whether
 * that means a clone into wp-content (classic) or a Composer vcs require
 * (Bedrock). The form is identical either way.
 *
 * Every git operation is queued — see {@see SyncSiteGitSourceJob}. Nothing here
 * touches SSH inline.
 */
#[Layout('layouts.app')]
class GitSources extends Component
{
    use AuthorizesRequests;
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    public string $kind = SiteGitSource::KIND_THEME;

    public string $slug = '';

    public string $repository_url = '';

    public string $git_branch = 'main';

    public string $composer_package = '';

    public function mount(Server $server, Site $site, GitSourceMaterializerFactory $factory): void
    {
        $this->authorize('view', $site);

        // Only WordPress has a themes/plugins directory to materialize into.
        // The sidebar hides the tab for other apps; guard the direct hit too.
        abort_unless($factory->supports($site), 404);

        $this->server = $server;
        $this->site = $site;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(SiteGitSource::KINDS)],
            // WordPress resolves themes and plugins by directory name, so the
            // slug is an identifier, not a label — keep it to what a directory
            // and a composer package suffix can both hold.
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9\-]*$/'],
            'repository_url' => ['required', 'string', 'max:255'],
            'git_branch' => ['required', 'string', 'max:120'],
            'composer_package' => ['nullable', 'string', 'max:150', 'regex:#^[a-z0-9]([a-z0-9\-\.]*)/[a-z0-9]([a-z0-9\-\.]*)$#'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'slug.regex' => __('Use lowercase letters, numbers and dashes — this becomes a directory name.'),
            'composer_package.regex' => __('Use a Composer package name, like acme/my-theme.'),
        ];
    }

    public function updatedRepositoryUrl(string $value): void
    {
        // Convenience only: the repo name is almost always the slug the author
        // intended, and re-typing it is busywork. Never overwrites a slug the
        // operator already set.
        if ($this->slug !== '') {
            return;
        }

        $guess = Str::of($value)->trim()->afterLast('/')->beforeLast('.git')->slug()->value();
        if ($guess !== '') {
            $this->slug = $guess;
        }
    }

    public function add(): void
    {
        $this->authorize('update', $this->site);

        $data = $this->validate();

        // WordPress cannot hold two themes in one directory, so (kind, slug) is
        // the real conflict — checked here for a friendly message, and enforced
        // by a unique index underneath.
        $exists = $this->site->gitSources()
            ->where('kind', $data['kind'])
            ->where('slug', $data['slug'])
            ->exists();

        if ($exists) {
            $this->addError('slug', __('This site already has a :kind with that slug.', ['kind' => $data['kind']]));

            return;
        }

        $source = $this->site->gitSources()->create([
            'kind' => $data['kind'],
            'slug' => $data['slug'],
            'repository_url' => trim($data['repository_url']),
            'git_branch' => trim($data['git_branch']),
            'composer_package' => trim((string) ($data['composer_package'] ?? '')) ?: null,
            'status' => SiteGitSource::STATUS_PENDING,
        ]);

        // Generated before the job runs so the panel can show the public key
        // immediately — a private repo needs it added as a deploy key, and the
        // first sync will fail until the operator does that.
        $source->ensureDeployKey();

        SyncSiteGitSourceJob::dispatch($source->id);

        $this->reset(['slug', 'repository_url', 'composer_package']);
        $this->git_branch = 'main';

        $this->toastSuccess(__('Added. Syncing it onto the server now — add the deploy key below if the repo is private.'));
    }

    public function resync(string $sourceId): void
    {
        $this->authorize('update', $this->site);

        $source = $this->site->gitSources()->findOrFail($sourceId);

        SyncSiteGitSourceJob::dispatch($source->id);

        $this->toastSuccess(__('Re-syncing :slug.', ['slug' => $source->slug]));
    }

    public function remove(string $sourceId): void
    {
        $this->authorize('update', $this->site);

        $source = $this->site->gitSources()->findOrFail($sourceId);

        // The row survives until the files are actually gone: deleting it here
        // would orphan a directory (or a composer require) on the box with
        // nothing left in dply pointing at it.
        SyncSiteGitSourceJob::dispatch($source->id, remove: true);

        $this->toastSuccess(__('Removing :slug from the server.', ['slug' => $source->slug]));
    }

    public function render(GitSourceMaterializerFactory $factory): View
    {
        return view('livewire.sites.git-sources', [
            'sources' => $this->site->gitSources()->orderBy('kind')->orderBy('slug')->get(),
            'bedrock' => $factory->layoutOf($this->site) === 'bedrock',
        ]);
    }
}
