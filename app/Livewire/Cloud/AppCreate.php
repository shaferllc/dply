<?php

namespace App\Livewire\Cloud;

use App\Models\Cloud\CloudApp;
use App\Models\Cloud\CloudCluster;
use App\Services\Cloud\RuntimeDetector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Livewire component for creating a new application on dply Cloud.
 */
class AppCreate extends Component
{
    public CloudCluster $cluster;

    public string $name = '';
    public string $gitRepositoryUrl = '';
    public string $gitBranch = 'main';
    public string $runtime = '';
    public string $framework = '';
    public ?string $primaryDomain = null;

    public bool $autoDetect = true;
    public bool $isDetecting = false;

    public int $cpuLimit = 500; // millicores (0.5 vCPU)
    public int $memoryLimit = 512; // MB
    public int $minReplicas = 1;
    public int $maxReplicas = 3;

    public int $step = 1;
    public const TOTAL_STEPS = 3;

    public function mount(CloudCluster $cluster): void
    {
        $this->cluster = $cluster;
    }

    /**
     * Get available runtimes.
     */
    #[Computed]
    public function availableRuntimes(): array
    {
        return CloudApp::availableRuntimes();
    }

    /**
     * Get available frameworks for the selected runtime.
     */
    #[Computed]
    public function availableFrameworks(): array
    {
        if (!$this->runtime) {
            return [];
        }

        return CloudApp::frameworksForRuntime($this->runtime);
    }

    /**
     * Detect runtime from the repository.
     */
    public function detectRuntime(RuntimeDetector $detector): void
    {
        if (!$this->gitRepositoryUrl) {
            return;
        }

        $this->isDetecting = true;
        $this->validate([
            'gitRepositoryUrl' => ['required', 'url', 'regex:/github\.com|gitlab\.com|bitbucket\.org/i'],
            'gitBranch' => ['required', 'string'],
        ]);

        try {
            $profile = $detector->detect($this->gitRepositoryUrl, $this->gitBranch);

            $this->runtime = $profile->runtimeString();
            $this->framework = $profile->framework;

            // Set default resource specs based on runtime
            $defaultSpec = CloudApp::defaultResourceSpec($this->runtime);
            $this->cpuLimit = (int) ($defaultSpec['cpu_limit'] * 1000); // Convert to millicores
            $this->memoryLimit = $defaultSpec['memory_limit'];
            $this->minReplicas = $defaultSpec['min_replicas'];
            $this->maxReplicas = $defaultSpec['max_replicas'];

            session()->flash('success', "Detected: {$profile->framework} on {$profile->runtime} {$profile->runtimeVersion}");

        } catch (\Throwable $e) {
            // Fall back to generic PHP if detection fails
            $this->runtime = CloudApp::RUNTIME_PHP_83;
            $this->framework = CloudApp::FRAMEWORK_GENERIC;

            session()->flash('warning', 'Could not auto-detect runtime. Defaulted to PHP 8.3. Please adjust manually.');
        }

        $this->isDetecting = false;
    }

    /**
     * Validation rules.
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z][a-z0-9\-]+$/'],
            'gitRepositoryUrl' => ['required', 'url', 'regex:/github\.com|gitlab\.com|bitbucket\.org/i'],
            'gitBranch' => ['required', 'string', 'max:100'],
            'runtime' => ['required', Rule::in(array_keys(CloudApp::availableRuntimes()))],
            'framework' => ['nullable', 'string'],
            'primaryDomain' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9\-\.]+\.[a-z]{2,}$/i'],
            'cpuLimit' => ['required', 'integer', 'min:100', 'max:4000'],
            'memoryLimit' => ['required', 'integer', 'min:128', 'max':8192],
            'minReplicas' => ['required', 'integer', 'min:1', 'max:10'],
            'maxReplicas' => ['required', 'integer', 'min:1', 'max:50', 'gte:minReplicas'],
        ];
    }

    /**
     * Go to next step.
     */
    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'name' => $this->rules()['name'],
                'gitRepositoryUrl' => $this->rules()['gitRepositoryUrl'],
                'gitBranch' => $this->rules()['gitBranch'],
            ]);

            if ($this->autoDetect && !$this->runtime) {
                $this->detectRuntime(app(RuntimeDetector::class));
            }
        }

        if ($this->step === 2) {
            $this->validate([
                'runtime' => $this->rules()['runtime'],
                'cpuLimit' => $this->rules()['cpuLimit'],
                'memoryLimit' => $this->rules()['memoryLimit'],
                'minReplicas' => $this->rules()['minReplicas'],
                'maxReplicas' => $this->rules()['maxReplicas'],
            ]);
        }

        if ($this->step < self::TOTAL_STEPS) {
            $this->step++;
        }
    }

    /**
     * Go to previous step.
     */
    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    /**
     * Create the application.
     */
    public function createApp(): void
    {
        $this->validate();

        try {
            $domains = $this->primaryDomain ? [$this->primaryDomain] : [];

            $app = CloudApp::create([
                'cloud_cluster_id' => $this->cluster->id,
                'organization_id' => $this->cluster->organization_id,
                'name' => $this->name,
                'slug' => Str::slug($this->name),
                'runtime' => $this->runtime,
                'framework' => $this->framework ?: CloudApp::FRAMEWORK_GENERIC,
                'git_repository_url' => $this->gitRepositoryUrl,
                'git_branch' => $this->gitBranch,
                'cpu_limit' => $this->cpuLimit / 1000, // Convert millicores to cores
                'memory_limit' => $this->memoryLimit,
                'min_replicas' => $this->minReplicas,
                'max_replicas' => $this->maxReplicas,
                'domains' => $domains,
                'status' => CloudApp::STATUS_PENDING,
            ]);

            // Ensure unique slug
            $app->slug = Str::slug($this->name).'-'.substr($app->id, 0, 8);
            $app->save();

            session()->flash('success', "Application '{$this->name}' created successfully.");

            $this->redirect(route('cloud.apps.show', ['cluster' => $this->cluster, 'app' => $app]), navigate: true);

        } catch (\Throwable $e) {
            Log::error('Failed to create CloudApp', [
                'error' => $e->getMessage(),
                'cluster_id' => $this->cluster->id,
            ]);

            $this->addError('general', 'Failed to create application: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.cloud.app-create');
    }
}
