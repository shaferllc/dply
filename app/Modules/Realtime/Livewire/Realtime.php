<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Livewire;

use App\Http\Controllers\OrgScopedRedirectController;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\SiteBinding;
use App\Modules\Realtime\Actions\CreateRealtimeApp;
use App\Modules\Realtime\Actions\DeleteRealtimeApp;
use App\Modules\Realtime\Actions\UpdateRealtimeApp;
use App\Modules\Realtime\Models\RealtimeApp;
use App\Modules\Realtime\Services\RealtimePublisher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-level dashboard for managed realtime (broadcasting) apps. Lists the
 * organization's apps with status, tier, usage and price, and lets admins move
 * an app between connection tiers or tear it down. Apps are provisioned from a
 * site's broadcasting binding; this is the place to manage them after the fact.
 */
#[Layout('layouts.app')]
class Realtime extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /** The app currently open in the tier-change modal, if any. */
    public ?string $managingAppId = null;

    public string $selectedTier = '';

    /** Re-consent required when a tier change raises the monthly price. */
    public bool $confirmTierCharge = false;

    /** The app currently open in the delete-confirmation modal, if any. */
    public ?string $deletingAppId = null;

    /** Create-app modal form. */
    public string $createName = '';

    public string $createTier = '';

    public bool $confirmCreateCharge = false;

    /** Playground: the app the demo console is pointed at. */
    public ?string $demoAppId = null;

    /** Playground: channel and event the demo publishes on. */
    public string $demoChannel = 'demo-channel';

    public string $demoEvent = 'demo-event';

    public string $demoMessage = 'Hello from dply 👋';

    /**
     * Session-scoped like every other product index (Edge, Cloud, Serverless);
     * the organization is no longer a route parameter. Legacy
     * `/organizations/{org}/realtime` URLs switch the session first — see
     * {@see OrgScopedRedirectController}.
     */
    public function mount(): void
    {
        $organization = auth()->user()?->currentOrganization();
        abort_if($organization === null, 403);

        $this->organization = $organization;
        $this->authorize('view', $organization);
        $this->createTier = (string) config('realtime.default_tier', 'starter');

        // The playground needs a live relay to talk to, so it opens on the first
        // active app rather than the newest — a provisioning app would give the
        // demo console nothing to connect to and read as broken.
        $this->demoAppId = $organization->realtimeApps()
            ->where('status', RealtimeApp::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->value('id');
    }

    /**
     * Open the create-app modal. Managed apps are a billed, dply-hosted resource,
     * so creation is gated on the surface flag (BYO broadcasting is always
     * available on a site) and org-update permission.
     */
    public function startCreate(): void
    {
        $this->authorize('update', $this->organization);

        if (! Feature::for($this->organization)->active('surface.realtime')) {
            $this->toastError(__('Managed realtime isn’t enabled for this workspace.'));

            return;
        }

        $this->reset(['createName', 'confirmCreateCharge']);
        $this->createTier = (string) config('realtime.default_tier', 'starter');
        $this->dispatch('open-modal', 'realtime-create-modal');
    }

    public function cancelCreate(): void
    {
        $this->reset(['createName', 'confirmCreateCharge']);
        $this->createTier = (string) config('realtime.default_tier', 'starter');
        $this->dispatch('close-modal', 'realtime-create-modal');
    }

    public function createApp(CreateRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        if (! Feature::for($this->organization)->active('surface.realtime')) {
            $this->toastError(__('Managed realtime isn’t enabled for this workspace.'));

            return;
        }

        $name = trim($this->createName);
        if ($name === '') {
            $this->toastError(__('Give the app a name.'));

            return;
        }

        $tiers = (array) config('realtime.tiers', []);
        if (! array_key_exists($this->createTier, $tiers)) {
            $this->toastError(__('Pick a connection tier.'));

            return;
        }

        // Provisioning a managed app adds its tier price to the workspace bill —
        // require explicit consent before spending money.
        if (! $this->confirmCreateCharge) {
            $this->toastError(__('Confirm the monthly charge to create the app.'));

            return;
        }

        $app = $action->handle(auth()->user(), $this->organization, [
            'name' => $name,
            'tier' => $this->createTier,
        ]);

        $this->toastSuccess(__('Provisioning :name — it’ll go active in a moment and is added to your workspace bill.', ['name' => $app->name]));
        $this->cancelCreate();
    }

    public function startTierChange(string $appId): void
    {
        $app = $this->findApp($appId);
        $this->managingAppId = $app->id;
        $this->selectedTier = $app->tierSlug();
        $this->confirmTierCharge = false;
        $this->dispatch('open-modal', 'realtime-tier-modal');
    }

    public function cancelTierChange(): void
    {
        $this->reset(['managingAppId', 'selectedTier', 'confirmTierCharge']);
        $this->dispatch('close-modal', 'realtime-tier-modal');
    }

    public function changeTier(UpdateRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        $app = $this->findApp((string) $this->managingAppId);
        $tiers = (array) config('realtime.tiers', []);

        if (! array_key_exists($this->selectedTier, $tiers)) {
            $this->toastError(__('Pick a connection tier.'));

            return;
        }

        if ($this->selectedTier === $app->tierSlug()) {
            $this->toastWarning(__('That app is already on the :tier tier.', ['tier' => $app->tierConfig()['label']]));

            return;
        }

        // Require explicit re-consent only when the change raises the bill.
        $newPriceCents = (int) ($tiers[$this->selectedTier]['price_cents'] ?? 0);
        if ($newPriceCents > $app->priceCents() && ! $this->confirmTierCharge) {
            $this->toastError(__('Confirm the new monthly charge to upgrade.'));

            return;
        }

        $action->changeTier($app, $this->selectedTier);

        $this->toastSuccess(__('Broadcasting app moved to the :tier tier. Your workspace bill updates to match.', [
            'tier' => (string) ($tiers[$this->selectedTier]['label'] ?? $this->selectedTier),
        ]));

        $this->cancelTierChange();
    }

    public function confirmDelete(string $appId): void
    {
        $this->deletingAppId = $this->findApp($appId)->id;
        $this->dispatch('open-modal', 'realtime-delete-modal');
    }

    public function cancelDelete(): void
    {
        $this->reset('deletingAppId');
        $this->dispatch('close-modal', 'realtime-delete-modal');
    }

    public function deleteApp(DeleteRealtimeApp $action): void
    {
        $this->authorize('update', $this->organization);

        $app = $this->findApp((string) $this->deletingAppId);
        $action->handle($app);

        $this->toastSuccess(__('Broadcasting app deleted. It no longer counts toward your bill.'));
        $this->cancelDelete();
    }

    /** Resolve an app id, scoped to this organization (404 otherwise). */
    private function findApp(string $appId): RealtimeApp
    {
        return $this->organization->realtimeApps()->findOrFail($appId);
    }

    /**
     * Publish a demo event from the console.
     *
     * The point is a complete round-trip an operator can watch: the page holds
     * a WebSocket open to the same app, this pushes a frame through the relay's
     * REST trigger, and the frame lands in the log a moment later. If that
     * works, their own client will too — and if it does not, the failure is the
     * relay's, not their integration's, which is a much cheaper thing to learn
     * here than halfway through wiring up Echo.
     *
     * `delivered: 0` is reported as its own outcome rather than an error: the
     * publish succeeded and nothing was listening, which is what an operator
     * sees if the socket dropped or the channel name drifted.
     */
    public function publishDemoEvent(RealtimePublisher $publisher): void
    {
        $this->authorize('update', $this->organization);

        $app = $this->demoAppId !== null ? $this->findApp($this->demoAppId) : null;
        if (! $app instanceof RealtimeApp) {
            $this->toastError(__('Pick an app to publish to.'));

            return;
        }

        $channel = trim($this->demoChannel) !== '' ? trim($this->demoChannel) : 'demo-channel';
        $event = trim($this->demoEvent) !== '' ? trim($this->demoEvent) : 'demo-event';

        try {
            $result = $publisher->publish($app, $channel, $event, [
                'message' => trim($this->demoMessage),
                // A client-side clock cannot be trusted to order frames from
                // different tabs, so the relay's sender stamps them.
                'sent_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if ($result['delivered'] === 0) {
            $this->toastWarning(__('Published, but nothing was subscribed to :channel.', ['channel' => $channel]));

            return;
        }

        $this->toastSuccess(trans_choice(
            'Delivered to :count subscriber.|Delivered to :count subscribers.',
            $result['delivered'],
            ['count' => $result['delivered']],
        ));
    }

    /**
     * The numbers the relay console reads out.
     *
     * Headroom is the verdict this product turns on: a tier is a hard connection
     * cap, so an app at 95% is about to start refusing clients, and that matters
     * more than the price or the app count. It is computed against the *active*
     * apps only — a provisioning app has no capacity yet, and a paused one is
     * not accepting connections, so folding either into the ratio would report
     * headroom the workspace does not actually have.
     *
     * `peak_connections` is a high-water mark reported by the relay, not a live
     * gauge, and everything downstream is labelled that way. Where the relay has
     * gone quiet ({@see staleApps}) the peak is stale too, which is why the
     * console surfaces stats freshness next to the number rather than under it.
     *
     * @param  Collection<int, RealtimeApp>  $apps
     * @param  Collection<int, RealtimeApp>  $activeApps
     * @param  int  $boundSites  Broadcasting bindings pointing at these apps.
     * @param  list<string>  $boundAppIds  Apps at least one site broadcasts through.
     * @return array<string, mixed>
     */
    private function relayMetrics(
        Collection $apps,
        Collection $activeApps,
        int $boundSites,
        array $boundAppIds,
        int $monthlyCents,
    ): array {
        $capacity = $activeApps->sum(fn (RealtimeApp $app): int => $app->maxConnections());
        $peak = $activeApps->sum(fn (RealtimeApp $app): int => (int) $app->peak_connections);

        // Billed but carrying nothing.
        $unbound = $activeApps->reject(
            fn (RealtimeApp $app): bool => in_array((string) $app->id, $boundAppIds, true)
        );

        // Over-provisioned: a cheaper tier still fits the observed peak.
        $downgradable = $activeApps->filter(
            fn (RealtimeApp $app): bool => self::cheaperTierFor($app) !== null
        );
        $downgradableSaving = $downgradable->sum(
            fn (RealtimeApp $app): int => $app->priceCents() - (int) (self::cheaperTierFor($app)['priceCents'] ?? $app->priceCents())
        );

        $tierSpread = $this->tierSpread($apps);

        // Apps the relay has not reported on recently. Null means it has never
        // reported, which for an active app is the same operational problem.
        $staleAfter = now()->subHours(24);
        $staleApps = $activeApps->filter(
            fn (RealtimeApp $app): bool => $app->last_stats_at === null || $app->last_stats_at->lt($staleAfter)
        );

        // The single app closest to its own cap. A workspace-wide ratio hides
        // one app at 99% behind three that are idle.
        $tightest = $activeApps
            ->sortByDesc(fn (RealtimeApp $app): float => $app->maxConnections() > 0
                ? (int) $app->peak_connections / $app->maxConnections()
                : 0.0)
            ->first();

        return [
            'apps' => $apps->count(),
            'activeApps' => $activeApps->count(),
            'provisioning' => $apps->where('status', RealtimeApp::STATUS_PROVISIONING)->count(),
            'failed' => $apps->where('status', RealtimeApp::STATUS_FAILED)->count(),
            'paused' => $apps->where('status', RealtimeApp::STATUS_PAUSED)->count(),
            'capacity' => $capacity,
            'peak' => $peak,
            'utilisation' => $capacity > 0 ? (int) round($peak / $capacity * 100) : 0,
            'tightestApp' => $tightest,
            'tightestPercent' => $tightest instanceof RealtimeApp && $tightest->maxConnections() > 0
                ? (int) round((int) $tightest->peak_connections / $tightest->maxConnections() * 100)
                : 0,
            'staleCount' => $staleApps->count(),
            'staleApps' => $staleApps->values(),
            'lastStatsAt' => $activeApps->max('last_stats_at'),
            'boundSites' => $boundSites,
            'monthlyCents' => $monthlyCents,
            'annualCents' => $monthlyCents * 12,
            'headroom' => max(0, $capacity - $peak),

            // Spend efficiency. Connections are the unit being bought, so cost
            // per thousand of *provisioned* capacity is the comparable number
            // across tiers — it is what makes an over-provisioned app visible.
            'centsPerThousandCapacity' => $capacity > 0
                ? (int) round($monthlyCents / ($capacity / 1000))
                : 0,

            // Apps that cost money and carry nothing. Two distinct kinds of
            // waste, kept apart because the fix differs: an unbound app wants
            // attaching or deleting, an idle one wants a smaller tier.
            'unboundBillable' => $unbound->count(),
            'unboundCents' => $unbound->sum(fn (RealtimeApp $app): int => $app->priceCents()),
            'neverUsed' => $activeApps->filter(fn (RealtimeApp $app): bool => (int) $app->peak_connections === 0)->count(),

            // Where the tier ladder would save money: an app whose peak fits
            // comfortably inside a cheaper tier's cap. Headroom is kept at 2x
            // observed peak so a recommendation does not put a live app on the
            // edge of its new ceiling.
            'downgradable' => $downgradable->count(),
            'downgradableCents' => $downgradableSaving,

            'tierSpread' => $tierSpread,
            'oldestAt' => $apps->min('created_at'),
        ];
    }

    /**
     * How many apps sit on each configured tier, in config order so the ladder
     * reads cheapest-first regardless of what the workspace happens to own.
     *
     * @param  Collection<int, RealtimeApp>  $apps
     * @return array<string, array{label: string, count: int, maxConnections: int, priceCents: int}>
     */
    private function tierSpread(Collection $apps): array
    {
        $spread = [];

        foreach ((array) config('realtime.tiers', []) as $slug => $tier) {
            $spread[(string) $slug] = [
                'label' => (string) ($tier['label'] ?? ucfirst((string) $slug)),
                'count' => $apps->filter(fn (RealtimeApp $app): bool => $app->tierSlug() === (string) $slug)->count(),
                'maxConnections' => (int) ($tier['max_connections'] ?? 0),
                'priceCents' => (int) ($tier['price_cents'] ?? 0),
            ];
        }

        return $spread;
    }

    /**
     * The cheapest tier that still fits an app's observed peak with room to
     * spare, or null when it is already on it.
     *
     * "Room to spare" is 2x the observed peak: a recommendation that lands an
     * app just under its new cap would be a downgrade into an outage the first
     * time traffic ticks up.
     *
     * @return array{slug: string, label: string, priceCents: int}|null
     */
    public static function cheaperTierFor(RealtimeApp $app): ?array
    {
        $needed = max(1, (int) $app->peak_connections) * 2;
        $currentCents = $app->priceCents();

        $best = null;
        foreach ((array) config('realtime.tiers', []) as $slug => $tier) {
            $cap = (int) ($tier['max_connections'] ?? 0);
            $cents = (int) ($tier['price_cents'] ?? 0);

            if ($cap < $needed || $cents >= $currentCents) {
                continue;
            }

            if ($best === null || $cents < $best['priceCents']) {
                $best = [
                    'slug' => (string) $slug,
                    'label' => (string) ($tier['label'] ?? ucfirst((string) $slug)),
                    'priceCents' => $cents,
                ];
            }
        }

        return $best;
    }

    public function render(): View
    {
        $apps = $this->organization->realtimeApps()
            ->orderByDesc('created_at')
            ->get();

        // Sites that depend on each app (broadcasting bindings), so the UI can
        // warn before deleting an app a live site still points at.
        $siteUsage = SiteBinding::query()
            ->where('type', 'broadcasting')
            ->where('target_type', 'realtime_app')
            ->whereIn('target_id', $apps->pluck('id'))
            ->with('site:id,name,organization_id')
            ->get()
            ->groupBy('target_id');

        $activeApps = $apps->where('status', RealtimeApp::STATUS_ACTIVE);
        $monthlyCents = $activeApps->sum(fn (RealtimeApp $app): int => $app->priceCents());

        return view('livewire.organizations.realtime', [
            'apps' => $apps,
            'siteUsage' => $siteUsage,
            'tiers' => (array) config('realtime.tiers', []),
            'activeCount' => $activeApps->count(),
            'monthlyCents' => $monthlyCents,
            // Bindings, not distinct sites: a site could broadcast through more
            // than one app, and each of those is a dependency to warn about.
            'metrics' => $this->relayMetrics(
                $apps,
                $activeApps,
                $siteUsage->flatten()->count(),
                $siteUsage->keys()->map(fn ($id): string => (string) $id)->all(),
                $monthlyCents,
            ),
            'demoApp' => $this->demoAppId !== null ? $apps->firstWhere('id', $this->demoAppId) : null,
            'managingApp' => $this->managingAppId !== null ? $apps->firstWhere('id', $this->managingAppId) : null,
            'deletingApp' => $this->deletingAppId !== null ? $apps->firstWhere('id', $this->deletingAppId) : null,
            'deletingAppSites' => $this->deletingAppId !== null
                ? ($siteUsage->get($this->deletingAppId) ?? new Collection)
                : new Collection,
            'featureActive' => Feature::for($this->organization)->active('surface.realtime'),
            'canManage' => auth()->user()?->can('update', $this->organization) ?? false,
            // Product breadcrumbs, matching Backups/Edge. The organization crumb
            // went with the org-settings shell: the active org is now the
            // session's, so naming it here would imply a choice the URL no
            // longer offers.
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Realtime'), 'icon' => 'signal'],
            ],
        ]);
    }
}
