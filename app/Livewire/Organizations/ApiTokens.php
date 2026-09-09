<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-wide token inventory — every member's tokens, not just your own — moved
 * off Organization settings, where 16 rows buried a four-field identity form.
 * Issuing still happens in one place (Settings\ApiKeys); this page views and
 * revokes.
 */
#[Layout('layouts.app')]
class ApiTokens extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public Organization $organization;

    public string $search = '';

    /** 'all' | 'active' | 'expired' */
    public string $filter = 'all';

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
    }

    /**
     * @return Collection<int, ApiToken>
     */
    public function tokens(): Collection
    {
        $needle = trim($this->search);

        return ApiToken::query()
            ->where('organization_id', $this->organization->id)
            // Whose token it is, is the column an org-wide inventory exists for.
            ->with('user:id,name,email')
            ->when($needle !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.$needle.'%')
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'ilike', '%'.$needle.'%')
                    ->orWhere('email', 'ilike', '%'.$needle.'%'))))
            // Expired last, then most recently used first: the list is read to
            // answer "what is live right now?".
            ->orderByRaw('(expires_at is not null and expires_at < now())')
            ->orderByRaw('last_used_at desc nulls last')
            ->orderBy('name')
            ->get()
            ->when(
                $this->filter !== 'all',
                fn (Collection $rows) => $rows->filter(fn (ApiToken $t) => $this->filter === 'expired'
                    ? ($t->expires_at !== null && $t->expires_at->isPast())
                    : ! ($t->expires_at !== null && $t->expires_at->isPast()))
            )
            ->values();
    }

    public function promptRevokeApiToken(string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($apiTokenId)
            ->first();

        if ($apiToken === null) {
            return;
        }

        $this->openConfirmActionModal(
            'revokeApiToken',
            [$apiToken->id],
            __('Revoke API token'),
            __('Revoke :name? Integrations using this token will stop working immediately. This cannot be undone.', ['name' => $apiToken->name]),
            __('Revoke token'),
            true
        );
    }

    public function revokeApiToken(int|string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::where('organization_id', $this->organization->id)->findOrFail($apiTokenId);

        $snapshot = [
            'token_id' => (string) $apiToken->id,
            'token_name' => $apiToken->name,
            'token_prefix' => $apiToken->token_prefix,
            'abilities' => $apiToken->abilities,
            'expires_at' => $apiToken->expires_at?->toIso8601String(),
        ];
        $apiToken->delete();

        audit_log($this->organization, auth()->user(), 'api_token.revoked', null, $snapshot, null);

        $this->toastSuccess(__('API token revoked.'));
    }

    public function render(): View
    {
        return view('livewire.organizations.api-tokens', [
            'tokens' => $this->tokens(),
        ]);
    }
}
