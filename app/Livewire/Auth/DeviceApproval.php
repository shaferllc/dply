<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\ApiToken;
use App\Models\DeviceAuthorization;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web approval page for the dply CLI's device-flow login. The CLI
 * prints a short `user_code` in the terminal and a verification URL;
 * the user opens that URL (or follows the deep link), confirms the
 * scopes the token will hold, and clicks Approve. That mints an
 * ApiToken in the user's current org and parks the plaintext on the
 * device_authorization row so the polling CLI can pick it up exactly
 * once.
 */
#[Layout('layouts.app')]
class DeviceApproval extends Component
{
    public const DEFAULT_ABILITIES = ['edge.read', 'edge.deploy', 'edge.write'];

    #[Url(as: 'user_code', except: '')]
    public string $userCode = '';

    public ?string $organizationId = null;

    /** @var list<string> */
    public array $selectedAbilities = self::DEFAULT_ABILITIES;

    public ?string $resolvedUserCode = null;

    /** Set to one of: approved | denied — gates the "all done" view. */
    public ?string $completedState = null;

    public function mount(): void
    {
        $org = Auth::user()?->currentOrganization();
        if ($org) {
            $this->organizationId = (string) $org->id;
        }

        if ($this->userCode !== '') {
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $this->resetErrorBag();
        $this->resolvedUserCode = null;

        $record = DeviceAuthorization::resolveUserCode($this->userCode);
        if ($record === null || ! $record->isPending()) {
            $this->addError('userCode', __('That code is invalid, already used, or expired. Re-run `dply login` to get a fresh code.'));

            return;
        }

        $this->resolvedUserCode = $record->user_code;
    }

    public function toggleAbility(string $ability): void
    {
        if (! in_array($ability, self::DEFAULT_ABILITIES, true)) {
            return;
        }

        if (in_array($ability, $this->selectedAbilities, true)) {
            $this->selectedAbilities = array_values(array_filter(
                $this->selectedAbilities,
                fn (string $a): bool => $a !== $ability
            ));

            return;
        }

        $this->selectedAbilities[] = $ability;
    }

    public function approve(): void
    {
        $record = $this->lockedPendingRecord();
        if ($record === null) {
            return;
        }

        $user = Auth::user();
        $org = $this->resolvedOrganization();
        if ($user === null || $org === null) {
            $this->addError('userCode', __('Pick an organization to authorize this device against.'));

            return;
        }

        $abilities = array_values(array_intersect($this->selectedAbilities, self::DEFAULT_ABILITIES));
        if ($abilities === []) {
            $this->addError('selectedAbilities', __('Pick at least one scope.'));

            return;
        }

        try {
            ApiToken::assertAbilitiesValidForStorage($abilities);
        } catch (\InvalidArgumentException $e) {
            $this->addError('selectedAbilities', $e->getMessage());

            return;
        }

        $created = ApiToken::createToken(
            $user,
            $org,
            __('dply CLI'),
            null,
            $abilities,
            null,
        );

        $record->forceFill([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'api_token_id' => $created['token']->id,
            'token_plaintext' => $created['plaintext'],
            'status' => DeviceAuthorization::STATUS_AUTHORIZED,
            'authorized_at' => Carbon::now(),
        ])->save();

        audit_log($org, $user, 'api_token.device_authorized', $created['token'], null, [
            'device_authorization_id' => (string) $record->id,
            'token_id' => (string) $created['token']->id,
            'token_name' => 'dply CLI',
            'abilities' => $abilities,
        ]);

        $this->completedState = 'approved';
    }

    public function deny(): void
    {
        $record = $this->lockedPendingRecord();
        if ($record === null) {
            return;
        }

        $record->forceFill([
            'user_id' => Auth::id(),
            'status' => DeviceAuthorization::STATUS_DENIED,
        ])->save();

        $this->completedState = 'denied';
    }

    protected function lockedPendingRecord(): ?DeviceAuthorization
    {
        if ($this->resolvedUserCode === null) {
            $this->addError('userCode', __('Enter the code from your terminal first.'));

            return null;
        }

        $record = DeviceAuthorization::resolveUserCode($this->resolvedUserCode);
        if ($record === null || ! $record->isPending()) {
            $this->addError('userCode', __('That code is invalid, already used, or expired. Re-run `dply login` to get a fresh code.'));

            return null;
        }

        return $record;
    }

    protected function resolvedOrganization(): ?Organization
    {
        if ($this->organizationId === null) {
            return null;
        }

        $org = Organization::query()->find($this->organizationId);
        if (! $org) {
            return null;
        }

        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        // Token belongs to the user + org; require user to belong to the org.
        if (! $user->organizations()->where('organizations.id', $org->id)->exists()) {
            return null;
        }

        return $org;
    }

    public function render(): View
    {
        $user = Auth::user();
        $organizations = $user
            ? $user->organizations()->orderBy('name')->get()
            : collect();

        return view('livewire.auth.device-approval', [
            'organizations' => $organizations,
            'availableScopes' => [
                ['ability' => 'edge.read', 'label' => __('Read Edge sites, deployments, and logs')],
                ['ability' => 'edge.deploy', 'label' => __('Deploy, roll back, promote previews')],
                ['ability' => 'edge.write', 'label' => __('Manage custom domains and cache')],
            ],
        ]);
    }
}
