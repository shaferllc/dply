<?php

namespace App\Livewire\Cloud;

use App\Models\Cloud\CloudCluster;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Services\Cloud\ClusterProvisioner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Livewire component for creating a new dply Cloud cluster.
 */
class ClusterCreate extends Component
{
    public string $name = '';
    public string $region = 'nyc1';
    public string $tier = CloudCluster::TIER_STARTER;
    public ?string $providerCredentialId = null;

    public bool $showAdvanced = false;
    public bool $isCreating = false;

    public int $step = 1;
    public const TOTAL_STEPS = 3;

    /**
     * Get the current organization.
     */
    #[Computed]
    public function organization(): Organization
    {
        return Auth::user()->currentOrganization;
    }

    /**
     * Get available DigitalOcean credentials for this organization.
     */
    #[Computed]
    public function availableCredentials(): array
    {
        return $this->organization->providerCredentials()
            ->where('provider', 'digitalocean')
            ->get()
            ->map(fn (ProviderCredential $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'provider' => $c->provider->value,
            ])
            ->toArray();
    }

    /**
     * Get available regions with their metadata.
     */
    #[Computed]
    public function availableRegions(): array
    {
        return CloudCluster::availableRegions();
    }

    /**
     * Get available tiers with descriptions and pricing.
     */
    #[Computed]
    public function availableTiers(): array
    [
        return [
            CloudCluster::TIER_STARTER => [
                'name' => 'Starter',
                'description' => 'Perfect for small apps and prototypes',
                'price' => '$25/month',
                'specs' => '1 node, 1 vCPU, 2 GB RAM',
                'features' => [
                    'Shared node pool',
                    'Manual scaling',
                    'Basic monitoring',
                    'Community support',
                ],
            ],
            CloudCluster::TIER_PRO => [
                'name' => 'Pro',
                'description' => 'For production applications',
                'price' => '$75/month',
                'specs' => '2+ nodes, autoscaling 2-4',
                'features' => [
                    'Dedicated node pool',
                    'Autoscaling (2-4 nodes)',
                    'Advanced monitoring',
                    'Priority support',
                    'Custom domains',
                ],
            ],
            CloudCluster::TIER_ENTERPRISE => [
                'name' => 'Enterprise',
                'description' => 'High-availability mission-critical',
                'price' => '$200/month',
                'specs' => '3+ nodes, HA, autoscaling 3-10',
                'features' => [
                    'High availability',
                    'Autoscaling (3-10 nodes)',
                    'Premium monitoring',
                    '24/7 support',
                    'Custom domains',
                    'Dedicated account manager',
                ],
            ],
        ];
    }

    /**
     * Get node pool spec preview based on selected tier.
     */
    #[Computed]
    public function nodePoolPreview(): array
    {
        return CloudCluster::defaultNodePoolSpec($this->tier);
    }

    /**
     * Get estimated monthly cost.
     */
    #[Computed]
    public function estimatedMonthlyCost(): string
    {
        $tierInfo = $this->availableTiers[$this->tier] ?? null;

        return $tierInfo['price'] ?? '$25/month';
    }

    /**
     * Validation rules.
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z][a-zA-Z0-9\-]+$/'],
            'region' => ['required', Rule::in(array_keys(CloudCluster::availableRegions()))],
            'tier' => ['required', Rule::in([CloudCluster::TIER_STARTER, CloudCluster::TIER_PRO, CloudCluster::TIER_ENTERPRISE])],
            'providerCredentialId' => ['required', 'exists:provider_credentials,id'],
        ];
    }

    /**
     * Custom validation messages.
     */
    protected function messages(): array
    {
        return [
            'name.regex' => 'The name must start with a letter and contain only letters, numbers, and hyphens.',
            'providerCredentialId.required' => 'Please select a DigitalOcean account to provision with.',
        ];
    }

    /**
     * Go to the next step.
     */
    public function nextStep(): void
    {
        $this->validate([
            'name' => $this->rules()['name'],
        ]);

        if ($this->step < self::TOTAL_STEPS) {
            $this->step++;
        }
    }

    /**
     * Go to the previous step.
     */
    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    /**
     * Select a tier.
     */
    public function selectTier(string $tier): void
    {
        if (in_array($tier, [CloudCluster::TIER_STARTER, CloudCluster::TIER_PRO, CloudCluster::TIER_ENTERPRISE], true)) {
            $this->tier = $tier;
        }
    }

    /**
     * Select a region.
     */
    public function selectRegion(string $region): void
    {
        if (CloudCluster::isValidRegion($region)) {
            $this->region = $region;
        }
    }

    /**
     * Create the cluster.
     */
    public function createCluster(ClusterProvisioner $provisioner): void
    {
        $this->validate();

        $this->isCreating = true;

        try {
            $cluster = CloudCluster::create([
                'organization_id' => $this->organization->id,
                'provider_credential_id' => (int) $this->providerCredentialId,
                'name' => $this->name,
                'slug' => Str::slug($this->name),
                'region' => $this->region,
                'tier' => $this->tier,
                'node_pool_spec' => CloudCluster::defaultNodePoolSpec($this->tier),
                'status' => CloudCluster::STATUS_PENDING,
            ]);

            // Ensure unique slug if needed
            $cluster->slug = Str::slug($this->name).'-'.substr($cluster->id, 0, 8);
            $cluster->save();

            // Initiate provisioning
            $provisioner->initiateProvisioning($cluster);

            session()->flash('success', "Cluster '{$this->name}' is being created. This may take 5-10 minutes.");

            $this->redirect(route('cloud.clusters.show', $cluster), navigate: true);

        } catch (\Throwable $e) {
            $this->isCreating = false;

            Log::error('Failed to create cluster', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'organization_id' => $this->organization->id,
            ]);

            $this->addError('general', 'Failed to create cluster: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.cloud.cluster-create');
    }
}
