<?php

declare(strict_types=1);

namespace App\Livewire\Launches;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Launch\FullStackArchitecturePlanner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Tier B workflow: paste a repo, get a multi-engine architecture plan,
 * then hand off to Edge / Cloud / BYO create flows with prefilled params.
 */
#[Layout('layouts.app')]
class FullStack extends Component
{
    use DispatchesToastNotifications;

    public string $step = 'repo';

    public string $repo = '';

    public string $branch = 'main';

    public bool $planning = false;

    /** @var array<string, mixed> */
    public array $plan = [];

    public function mount(): void
    {
        abort_unless(full_stack_wizard_active(), 404);

        $repo = trim((string) request()->query('repo', ''));
        if ($repo !== '') {
            $this->repo = $repo;
            $this->branch = trim((string) request()->query('branch', 'main')) ?: 'main';
        }
    }

    public function analyze(FullStackArchitecturePlanner $planner): void
    {
        $this->validate([
            'repo' => ['required', 'string', 'max:2048'],
            'branch' => ['required', 'string', 'max:255'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }

        $this->planning = true;
        $this->plan = [];

        try {
            $result = $planner->planFromUrl($this->repo, $this->branch);
            $this->plan = $result->toArray();
            $this->step = 'plan';
        } catch (GitCloneException $e) {
            $this->toastError(__('Could not clone the repository: :message', ['message' => $e->getMessage()]));
        } catch (Throwable $e) {
            $this->toastError(__('Architecture planning failed: :message', ['message' => $e->getMessage()]));
        } finally {
            $this->planning = false;
        }
    }

    public function backToRepo(): void
    {
        $this->step = 'repo';
    }

    public function showWiring(): void
    {
        if ($this->plan === []) {
            return;
        }

        $this->step = 'wiring';
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function launchUrl(string $routeName, array $params = []): string
    {
        return route($routeName, $params);
    }

    public function render(): View
    {
        return view('livewire.launches.full-stack');
    }
}
