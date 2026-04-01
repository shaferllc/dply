<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class ApiKeys extends Component
{
    use ConfirmsActionWithModal;

    public string $token_name = '';

    public ?string $token_expires_at = null;

    /** Comma- or newline-separated IPs */
    public string $token_allowed_ips_text = '';

    /** @var list<string> */
    public array $selected_abilities = [];

    /** @var list<string> */
    public array $expanded_categories = [];

    public string $token_list_search = '';

    public ?string $organization_id = null;

    public ?string $new_token_plaintext = null;

    public ?string $new_token_name = null;

    public function mount(): void
    {
        $orgs = $this->adminOrganizations();
        if ($orgs->isEmpty()) {
            return;
        }

        $current = Auth::user()->currentOrganization();
        $pick = $current && $orgs->contains('id', $current->id)
            ? $current->id
            : $orgs->first()->id;

        $this->organization_id = (string) $pick;
    }

    public function updatedOrganizationId(): void
    {
        $this->resetErrorBag();
    }

    public function toggleCategoryExpand(string $categoryId): void
    {
        if (in_array($categoryId, $this->expanded_categories, true)) {
            $this->expanded_categories = array_values(array_filter(
                $this->expanded_categories,
                fn (string $id) => $id !== $categoryId
            ));
        } else {
            $this->expanded_categories[] = $categoryId;
        }
    }

    public function toggleAbility(string $ability): void
    {
        if (in_array($ability, $this->selected_abilities, true)) {
            $this->selected_abilities = array_values(array_filter(
                $this->selected_abilities,
                fn (string $a) => $a !== $ability
            ));
        } else {
            $this->selected_abilities[] = $ability;
        }
    }

    public function toggleAllPermissions(): void
    {
        $all = $this->allCatalogAbilities();
        if (count($this->selected_abilities) === count($all)) {
            $this->selected_abilities = [];

            return;
        }

        $this->selected_abilities = $all;
    }

    public function createToken(): void
    {
        $org = $this->resolvedOrganization();
        if (! $org) {
            return;
        }

        $this->authorize('update', $org);

        if (config('dply.api_tokens_require_paid_plan', false) && ! $org->onProSubscription()) {
            $this->addError('token_name', __('API tokens require an active Pro plan for this organization.'));

            return;
        }

        $this->validate([
            'token_name' => ['required', 'string', 'max:255'],
            'token_expires_at' => ['nullable', 'date', 'after:today'],
            'token_allowed_ips_text' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($this->selected_abilities === []) {
            $this->addError('selected_abilities', __('Select at least one permission.'));

            return;
        }

        try {
            ApiToken::assertAbilitiesValidForStorage($this->selected_abilities);
        } catch (InvalidArgumentException $e) {
            $this->addError('selected_abilities', $e->getMessage());

            return;
        }

        $user = Auth::user();
        $abilities = $this->applyDeployerAbilityCap($org, $user, $this->selected_abilities);

        if ($abilities === []) {
            $this->addError('selected_abilities', __('Your organization role does not allow these permissions.'));

            return;
        }

        $expiresAt = $this->token_expires_at ? Carbon::parse($this->token_expires_at) : null;

        $allowedIps = ApiToken::parseAllowedIpsInput($this->token_allowed_ips_text, 'token_allowed_ips_text');

        $created = ApiToken::createToken(
            $user,
            $org,
            $this->token_name,
            $expiresAt,
            $abilities,
            $allowedIps
        );

        $this->new_token_plaintext = $created['plaintext'];
        $this->new_token_name = $this->token_name;
        $this->reset(['token_name', 'token_expires_at', 'token_allowed_ips_text', 'selected_abilities']);
    }

    public function clearNewToken(): void
    {
        $this->new_token_plaintext = null;
        $this->new_token_name = null;
    }

    public function revokeToken(int|string $apiTokenId): void
    {
        $org = $this->resolvedOrganization();
        if (! $org) {
            return;
        }

        $this->authorize('update', $org);

        $token = ApiToken::query()
            ->where('organization_id', $org->id)
            ->where('user_id', Auth::id())
            ->findOrFail($apiTokenId);

        $token->delete();
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    protected function applyDeployerAbilityCap(Organization $organization, User $user, array $abilities): array
    {
        if (! $organization->userIsDeployer($user)) {
            return array_values(array_unique($abilities));
        }

        $allowed = ApiToken::deployerApiAllowlist();

        return array_values(array_intersect($abilities, $allowed));
    }

    /**
     * @return list<string>
     */
    protected function allCatalogAbilities(): array
    {
        return ApiToken::catalogAbilities();
    }

    protected function resolvedOrganization(): ?Organization
    {
        if ($this->organization_id === null) {
            return null;
        }

        $org = Organization::query()->find($this->organization_id);
        if (! $org || ! $org->hasAdminAccess(Auth::user())) {
            return null;
        }

        return $org;
    }

    /**
     * @return Collection<int, Organization>
     */
    protected function adminOrganizations()
    {
        return Auth::user()
            ->organizations()
            ->get()
            ->filter(fn (Organization $o) => $o->hasAdminAccess(Auth::user()))
            ->values();
    }

    public function render(): View
    {
        $orgs = $this->adminOrganizations();
        $org = $this->resolvedOrganization();

        $tokens = collect();
        if ($org) {
            $q = ApiToken::query()
                ->where('organization_id', $org->id)
                ->where('user_id', Auth::id())
                ->orderByDesc('id');

            if (trim($this->token_list_search) !== '') {
                $needle = mb_strtolower(trim($this->token_list_search));
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']);
            }

            $tokens = $q->get();
        }

        return view('livewire.settings.api-keys', [
            'adminOrganizations' => $orgs,
            'organization' => $org,
            'tokens' => $tokens,
            'permissionCategories' => config('api_token_permissions.categories', []),
            'isDeployerRole' => $org ? $org->userIsDeployer(Auth::user()) : false,
            'requiresPaidPlan' => (bool) config('dply.api_tokens_require_paid_plan', false),
            'orgHasProPlan' => $org?->onProSubscription() ?? false,
        ]);
    }
}
