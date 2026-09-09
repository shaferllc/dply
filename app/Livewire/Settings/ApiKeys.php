<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\PaginatesSettingsLists;
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
    use PaginatesSettingsLists;

    public string $token_name = '';

    public ?string $token_expires_at = null;

    /** Comma- or newline-separated IPs */
    public string $token_allowed_ips_text = '';

    /** @var list<string> */
    public array $selected_abilities = [];

    /** @var list<string> */
    public array $expanded_categories = [];

    public string $token_list_search = '';

    public int $token_page = 1;


    public ?string $organization_id = null;

    public ?string $new_token_plaintext = null;

    public ?string $new_token_name = null;

    /** Token whose scopes are open in the edit modal. */
    public ?string $editing_token_id = null;

    public ?string $editing_token_name = null;

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

        if (config('dply.api_tokens_require_paid_plan', false) && ! $org->onAnyPaidPlan()) {
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

        audit_log($org, $user, 'api_token.created', $created['token'], null, [
            'token_name' => $this->token_name,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->toIso8601String(),
            'allowed_ips' => $allowedIps,
            'token_id' => (string) $created['token']->id,
        ]);

        $this->new_token_plaintext = $created['plaintext'];
        $this->new_token_name = $this->token_name;
        $this->reset(['token_name', 'token_expires_at', 'token_allowed_ips_text', 'selected_abilities']);
        $this->expanded_categories = [];
        $this->dispatch('close-modal', 'create-api-token-modal');
    }

    public function updatedTokenListSearch(): void
    {
        // A search that lands you on page 3 of the old list looks empty.
        $this->token_page = 1;
    }

    public function openCreateApiTokenModal(): void
    {
        if ($this->adminOrganizations()->isEmpty()) {
            return;
        }

        $this->resetErrorBag();
        $this->reset([
            'token_name',
            'token_expires_at',
            'token_allowed_ips_text',
        ]);
        $this->selected_abilities = [];
        $this->expanded_categories = [];
        $this->dispatch('open-modal', 'create-api-token-modal');
    }

    public function closeCreateApiTokenModal(): void
    {
        $this->resetErrorBag();
        $this->reset([
            'token_name',
            'token_expires_at',
            'token_allowed_ips_text',
        ]);
        $this->selected_abilities = [];
        $this->expanded_categories = [];
        $this->dispatch('close-modal', 'create-api-token-modal');
    }

    /**
     * Open the scope editor for an existing token. Same picker as create —
     * abilities are stored on the token, so changing them is an update, not a
     * revoke-and-reissue.
     */
    public function openEditTokenAbilitiesModal(int|string $apiTokenId): void
    {
        $token = $this->ownedToken($apiTokenId);
        if (! $token) {
            return;
        }

        $this->resetErrorBag();
        $this->editing_token_id = (string) $token->id;
        $this->editing_token_name = $token->name;
        $this->selected_abilities = $token->abilities ?? [];
        $this->expanded_categories = [];
        $this->dispatch('open-modal', 'edit-api-token-abilities-modal');
    }

    public function closeEditTokenAbilitiesModal(): void
    {
        $this->resetErrorBag();
        $this->editing_token_id = null;
        $this->editing_token_name = null;
        $this->selected_abilities = [];
        $this->expanded_categories = [];
        $this->dispatch('close-modal', 'edit-api-token-abilities-modal');
    }

    public function updateTokenAbilities(): void
    {
        $token = $this->editing_token_id ? $this->ownedToken($this->editing_token_id) : null;
        if (! $token) {
            return;
        }

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

        $org = $token->organization;
        $user = Auth::user();

        // Same cap as issuance: a deployer must not be able to widen a token
        // past the role's allowlist by editing it after the fact.
        $abilities = $this->applyDeployerAbilityCap($org, $user, $this->selected_abilities);

        if ($abilities === []) {
            $this->addError('selected_abilities', __('Your organization role does not allow these permissions.'));

            return;
        }

        $before = $token->abilities ?? [];

        if ($abilities === $before) {
            $this->closeEditTokenAbilitiesModal();

            return;
        }

        $token->update(['abilities' => $abilities]);

        audit_log($org, $user, 'api_token.abilities_updated', $token,
            ['abilities' => $before],
            ['abilities' => $abilities],
        );

        $this->closeEditTokenAbilitiesModal();
    }

    /**
     * The caller's own token in the selected organization — the same scoping
     * revokeToken() uses, so neither path can reach someone else's token.
     */
    protected function ownedToken(int|string $apiTokenId): ?ApiToken
    {
        $org = $this->resolvedOrganization();
        if (! $org) {
            return null;
        }

        $this->authorize('update', $org);

        return ApiToken::query()
            ->where('organization_id', $org->id)
            ->where('user_id', Auth::id())
            ->find($apiTokenId);
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

        $snapshot = [
            'token_id' => (string) $token->id,
            'token_name' => $token->name,
            'token_prefix' => $token->token_prefix,
            'abilities' => $token->abilities,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
        $token->delete();

        audit_log($org, Auth::user(), 'api_token.revoked', null, $snapshot, null);
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

        $paged = $this->paginateSettingsList($tokens, 'token_page');

        return view('livewire.settings.api-keys', [
            'adminOrganizations' => $orgs,
            'organization' => $org,
            'tokens' => $tokens,
            'pagedTokens' => $paged['rows'],
            'tokenPageState' => $paged,
            'permissionCategories' => config('api_token_permissions.categories', []),
            'isDeployerRole' => $org ? $org->userIsDeployer(Auth::user()) : false,
            'requiresPaidPlan' => (bool) config('dply.api_tokens_require_paid_plan', false),
            'orgHasProPlan' => $org?->onAnyPaidPlan() ?? false,
        ]);
    }
}
