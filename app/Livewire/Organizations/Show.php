<?php

namespace App\Livewire\Organizations;

use App\Enums\ServerProvider;
use App\Enums\TrialState;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Insights\Services\OrganizationInsightsMetricsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
        // The route-bound model is already fresh — only the relations need
        // loading. Skipping fresh() here avoids a duplicate organizations SELECT.
        $this->refreshOrganization(fresh: false);
    }

    protected function refreshOrganization(bool $fresh = true): void
    {
        $this->organization = ($fresh ? $this->organization->fresh() : $this->organization)
            ->loadCount(['servers', 'sites', 'sharedSecrets'])
            ->load([
                'users',
                'teams',
                'invitations' => fn ($q) => $q->where('expires_at', '>', now()),
                'apiTokens',
                'notificationChannels',
                'providerCredentials' => fn ($q) => $q->latest(),
            ]);
    }

    public function render(
        OrganizationInsightsMetricsService $insights,
        OrganizationBillingStateComputer $billingState,
    ): View {
        return view('livewire.organizations.show', [
            'ledger' => $this->ledger($insights, $billingState),
        ]);
    }

    /**
     * The overview page, as six lines: a number, the sentence that qualifies
     * it, and the page that changes it. Rows the viewer can't act on are
     * dropped rather than rendered dead.
     *
     * Each row: label, value, optional flag (amber suffix), detail, cta, href.
     *
     * @return list<array<string, mixed>>
     */
    private function ledger(
        OrganizationInsightsMetricsService $insights,
        OrganizationBillingStateComputer $billingState,
    ): array {
        $org = $this->organization;
        $user = auth()->user();

        return array_values(array_filter([
            $this->planRow($org),
            $this->fleetRow($org, $insights),
            $this->peopleRow($org),
            $this->accessRow($org, $user?->can('viewAny', ProviderCredential::class) ?? false),
            $this->automationRow($org, $user?->can('viewNotificationChannels', $org) ?? false),
            $org->hasAdminAccess($user) ? $this->spendRow($org, $billingState) : null,
        ], fn (?array $row): bool => $row !== null && ($row['show'] ?? true)));
    }

    /** @return array<string, mixed> */
    private function planRow(Organization $org): array
    {
        $state = $org->trialState();
        $hasCard = (bool) $org->hasDefaultPaymentMethod();

        // Whole days remaining, rounded up — same arithmetic as the trial banner.
        $daysLeft = $state === TrialState::ActiveTrial && $org->trial_ends_at
            ? max(0, (int) ceil(now()->diffInDays($org->trial_ends_at, false)))
            : null;

        $detail = match ($state) {
            TrialState::ActiveTrial => $hasCard
                ? __('Full access. Card on file — billing starts when the trial ends.')
                : __('Full access. No payment method on file, so deploys and scheduler runs stop the day it ends.'),
            TrialState::ExpiredSoft => __('Trial expired. Deploys and scheduler runs are blocked until a card is added.'),
            TrialState::ExpiredHard => __('Trial expired. The agent is disconnected; your config is intact and a card revives it.'),
            TrialState::Subscribed => $org->onSubscriptionGracePeriod()
                ? __('Set to cancel — you keep full access until the period ends.')
                : __('Active subscription. Nothing to do here.'),
            TrialState::NoTrial => __('No trial recorded on this workspace.'),
        };

        return [
            'label' => __('Plan'),
            'value' => $org->planTierLabel(),
            'flag' => $daysLeft === null
                ? null
                : trans_choice('{0} ends today|{1} 1 day left|[2,*] :count days left', $daysLeft, ['count' => $daysLeft]),
            'detail' => $detail,
            'cta' => $hasCard ? __('Billing & plan') : __('Add payment'),
            'href' => route('billing.show', $org),
        ];
    }

    /** @return array<string, mixed> */
    private function fleetRow(Organization $org, OrganizationInsightsMetricsService $insights): array
    {
        $serverIds = $org->serverIds();
        $criticalServers = $insights->perServerRollup($serverIds)
            ->where('worst', 'critical')
            ->count();

        $providers = $org->servers()
            ->distinct()
            ->pluck('provider')
            ->filter()
            ->map(fn ($provider): string => $provider instanceof ServerProvider
                ? $provider->label()
                : (ServerProvider::tryFrom((string) $provider)?->label() ?? (string) $provider))
            ->unique()
            ->values();

        $where = match ($providers->count()) {
            0 => __('No provider recorded.'),
            1 => __('All on :provider.', ['provider' => $providers->first()]),
            default => __('Across :providers.', ['providers' => $providers->join(', ', ' and ')]),
        };

        return [
            'label' => __('Fleet'),
            'value' => __(':servers :serverWord · :sites :siteWord', [
                'servers' => $org->servers_count,
                'serverWord' => trans_choice('server|servers', $org->servers_count),
                'sites' => $org->sites_count,
                'siteWord' => trans_choice('site|sites', $org->sites_count),
            ]),
            'flag' => null,
            'detail' => $where.' '.($criticalServers > 0
                ? trans_choice('{1} 1 server carries open critical findings.|[2,*] :count servers carry open critical findings.', $criticalServers, ['count' => $criticalServers])
                : __('No open critical findings.')),
            'cta' => __('Open fleet'),
            'href' => route('servers.index'),
        ];
    }

    /** @return array<string, mixed> */
    private function peopleRow(Organization $org): array
    {
        $members = $org->users->count();
        $pending = $org->invitations->count();

        $roles = $org->users
            ->groupBy(fn ($member): string => (string) ($member->pivot->role ?? 'member'))
            ->map->count()
            ->map(fn (int $count, string $role): string => $count.' '.trans_choice($role.'|'.$role.'s', $count))
            ->values();

        return [
            'label' => __('People'),
            'value' => __(':members :memberWord · :teams :teamWord', [
                'members' => $members,
                'memberWord' => trans_choice('member|members', $members),
                'teams' => $org->teams->count(),
                'teamWord' => trans_choice('team|teams', $org->teams->count()),
            ]),
            'flag' => $pending > 0
                ? trans_choice('{1} 1 invitation pending|[2,*] :count invitations pending', $pending, ['count' => $pending])
                : null,
            'detail' => $roles->isEmpty()
                ? __('No members yet.')
                : __(':roles.', ['roles' => $roles->join(', ', ' and ')]),
            'cta' => __('Members'),
            'href' => route('organizations.members', $org),
        ];
    }

    /** @return array<string, mixed> */
    private function accessRow(Organization $org, bool $canViewCredentials): array
    {
        $credentials = $org->providerCredentials;
        $newest = $credentials->first();

        return [
            'show' => $canViewCredentials,
            'label' => __('Access'),
            'value' => __(':credentials :credentialWord · :secrets :secretWord', [
                'credentials' => $credentials->count(),
                'credentialWord' => trans_choice('provider|providers', $credentials->count()),
                'secrets' => $org->shared_secrets_count,
                'secretWord' => trans_choice('secret|secrets', $org->shared_secrets_count),
            ]),
            'flag' => $credentials->isEmpty() ? __('nothing connected') : null,
            'detail' => $newest
                ? __('Newest: :provider, added :when.', [
                    'provider' => ServerProvider::tryFrom((string) $newest->provider)?->label() ?? $newest->provider,
                    'when' => $newest->created_at?->diffForHumans() ?? __('recently'),
                ])
                : __('Connect a provider before you provision — nothing can be launched without one.'),
            'cta' => __('Credentials'),
            'href' => route('organizations.credentials', $org),
        ];
    }

    /** @return array<string, mixed> */
    private function automationRow(Organization $org, bool $canViewChannels): array
    {
        $tokens = $org->apiTokens;
        $channels = $org->notificationChannels->count();

        // Stale = never used, or untouched for a quarter. Both are worth
        // revoking; neither is visible anywhere else on this page.
        $cutoff = now()->subDays(90);
        $stale = $tokens
            ->filter(fn ($token): bool => $token->last_used_at === null || $token->last_used_at->lt($cutoff))
            ->count();

        $detail = $channels === 0
            ? __('Nothing is set up to tell you when a deploy fails.')
            : trans_choice('{1} 1 channel receiving alerts.|[2,*] :count channels receiving alerts.', $channels, ['count' => $channels]);

        if ($stale > 0) {
            $detail = trans_choice(
                '{1} 1 token unused for 90 days.|[2,*] :count tokens unused for 90 days.',
                $stale,
                ['count' => $stale]
            ).' '.$detail;
        }

        return [
            'label' => __('Automation'),
            'value' => __(':tokens :tokenWord', [
                'tokens' => $tokens->count(),
                'tokenWord' => trans_choice('API token|API tokens', $tokens->count()),
            ]),
            'flag' => $channels === 0 ? __('no notification channels') : null,
            'detail' => $detail,
            'cta' => $channels === 0 && $canViewChannels ? __('Set up channels') : __('Settings'),
            'href' => $channels === 0 && $canViewChannels
                ? route('organizations.notification-channels', $org)
                : route('organizations.settings', $org),
        ];
    }

    /** @return array<string, mixed> */
    private function spendRow(Organization $org, OrganizationBillingStateComputer $billingState): array
    {
        $monthly = $billingState->compute($org)->monthlyTotalCents / 100;

        return [
            'label' => __('Spend'),
            'value' => __(':amount / month', ['amount' => '$'.number_format($monthly, 2)]),
            'flag' => null,
            'detail' => $org->trialState() === TrialState::ActiveTrial
                ? __('What this workspace would bill today. Nothing is charged during the trial.')
                : __('dply platform fee at your current fleet size. Your provider invoices separately.'),
            'cta' => __('Analytics'),
            'href' => route('billing.analytics', $org),
        ];
    }
}
