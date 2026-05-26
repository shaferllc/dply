<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Actions\Edge\CreateEdgeSite;
use App\Actions\Edge\CreateHybridEdgeStack;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Livewire\Forms\EdgeCreateForm;
use App\Models\EdgeSiteEnvVar;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Billing\ManagedProductCostEstimator;
use App\Services\Cloud\CloudRouter;
use App\Services\Edge\EdgeMonorepoDetector;
use App\Services\Edge\Frameworks\EdgeFrameworkPresetRegistry;
use App\Jobs\DetectRepositoryRuntimeJob;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Support\Edge\EdgeSsrDetection;
use App\Support\Edge\FakeEdgeProvision;
use App\Support\Edge\HybridEdgeOriginMatcher;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Component;

/**
 * Git-connected create flow for dply Edge — static/SSG builds only in v1.
 */
class Create extends Component
{
    use DetectsRepositoryRuntime;
    use DispatchesToastNotifications;
    use RefreshesLinkedSourceControlAccounts;

    public EdgeCreateForm $form;

    /** 'manual' = type owner/name. 'connected' = pick from linked Git account. */
    public string $repo_source = 'manual';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    public string $repo = '';

    public string $branch = 'main';

    /**
     * @var list<array{id: string, provider: string, label: string}>
     */
    public array $linkedSourceControlAccounts = [];

    /**
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $availableRepositories = [];

    public bool $runtimeModeTouched = false;

    public bool $originUrlTouched = false;

    public bool $buildOverridesTouched = false;

    public bool $confirmingHybridStack = false;

    private bool $prefillingOrigin = false;

    private bool $prefillingFromDetection = false;

    private string $lastDetectionFingerprint = '';

    /** @var list<array{path: string, label: string}> */
    public array $monorepoPackages = [];

    public bool $monorepoDetected = false;

    /** @var list<string> */
    public array $monorepoMarkers = [];

    public bool $repoRootTouched = false;

    /**
     * Detection-in-flight bookkeeping. Off-thread detection writes its
     * result into Cache; the view polls pollRuntimeDetection() while
     * this flag is true, then flips it off when the cache key resolves.
     */
    public bool $runtimeDetectionPending = false;

    public string $runtimeDetectionKey = '';

    // ── Repo ref picker state (Branch / Tag / Commit search) ──────────────
    // Pops a panel under the ref input so the operator can search the
    // repo's actual branches/tags/commits instead of typing blind.
    // Hits the public GitHub API directly (authed via the user's linked
    // identity if present, anonymous otherwise). GitLab/Bitbucket fall
    // back to the static text input — adding them later is incremental.
    public bool $refPickerOpen = false;

    public string $refPickerTab = 'branches';

    public string $refPickerSearch = '';

    /** @var list<array{label: string, sha: string, meta: ?string}> */
    public array $refPickerResults = [];

    public ?string $refPickerError = null;

    public bool $refPickerLoading = false;

    /**
     * Env vars handed over from the Import wizard, pending persistence
     * until the site is actually created. Keyed by env name, values are
     * the importer's plaintext payload. Filled by {@see applyQueryPrefills}
     * when ?import_envs=<session-key> is present; flushed in {@see deploy}.
     *
     * @var array<string, string|int|float>
     */
    public array $pendingImportedEnvVars = [];

    public function mount(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(Feature::active('surface.edge'), 404);

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());
        if ($this->linkedSourceControlAccounts !== []) {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->loadRepositoriesForSelectedAccount();
            $this->repo_source = 'connected';
        }

        $this->applyQueryPrefills(request()->query());
    }

    /**
     * Honor ?repo=…&branch=…&framework=…&runtime_mode=…&build_command=…&output_dir=…&name=…
     * query params on initial mount so the import wizard + template
     * gallery can hand off a ready-to-deploy state without re-asking
     * the user to type anything they've already picked.
     *
     * Only fields the user hasn't manually touched (still empty) get
     * filled — refreshing the page doesn't blow away in-progress
     * edits.
     *
     * @param  array<string, mixed>  $query
     */
    private function applyQueryPrefills(array $query): void
    {
        $stringValue = static function ($v): string {
            return is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : '');
        };

        $repo = $stringValue($query['repo'] ?? null);
        if ($repo !== '') {
            $this->repo = EdgeCreateForm::normalizeRepo($repo);
            $this->repo_source = 'manual';
            $this->maybeSeedAppNameFromRepo();
        }

        $branch = $stringValue($query['branch'] ?? null);
        if ($branch !== '') {
            $this->branch = $branch;
        }

        $name = $stringValue($query['name'] ?? null);
        if ($name !== '' && $this->form->name === '') {
            $this->form->name = $name;
        }

        $runtimeMode = strtolower($stringValue($query['runtime_mode'] ?? null));
        if (in_array($runtimeMode, ['static', 'hybrid', 'ssr'], true)) {
            $this->form->runtime_mode = $runtimeMode;
            $this->runtimeModeTouched = true;
        }

        $build = $stringValue($query['build_command'] ?? null);
        if ($build !== '' && $this->form->build_command === '') {
            $this->form->build_command = $build;
            $this->buildOverridesTouched = true;
        }

        $output = $stringValue($query['output_dir'] ?? null);
        if ($output !== '' && ($this->form->output_dir === '' || $this->form->output_dir === 'dist')) {
            $this->form->output_dir = $output;
            $this->buildOverridesTouched = true;
        }

        // Env-var transfer from the Import wizard. The wizard stashes
        // the importer's plaintext values in session keyed by a ULID
        // and passes the key through ?import_envs=… (values stay out
        // of the URL — too sensitive + too long). On consume we pop
        // the session entry and stash the array on the component;
        // deploy() writes EdgeSiteEnvVar rows after the site lands.
        $importEnvsKey = $stringValue($query['import_envs'] ?? null);
        if ($importEnvsKey !== '') {
            $envs = session()->pull('edge.import.envs.'.$importEnvsKey);
            if (is_array($envs)) {
                $this->pendingImportedEnvVars = array_filter(
                    $envs,
                    static fn ($v, $k): bool => is_string($k) && (is_string($v) || is_int($v) || is_float($v)),
                    ARRAY_FILTER_USE_BOTH,
                );
            }
        }

        if ($repo !== '') {
            $this->maybeAutoDetectFromRepository();
        }
    }

    public function updatedRepoSource(string $value): void
    {
        if ($value === 'manual') {
            $this->repository_selection = '';
            $this->maybeAutoDetectFromRepository();

            return;
        }

        if ($this->repository_selection !== '') {
            return;
        }

        $this->runDetection('', $this->branch);
    }

    public function openRefPicker(): void
    {
        $this->refPickerOpen = true;
        $this->refPickerTab = $this->resolvePickerTabFromRefKind();
        $this->refreshRefPicker();
    }

    public function closeRefPicker(): void
    {
        $this->refPickerOpen = false;
    }

    public function setRefPickerTab(string $tab): void
    {
        if (! in_array($tab, ['branches', 'tags', 'commits'], true)) {
            return;
        }
        $this->refPickerTab = $tab;
        $this->refreshRefPicker();
    }

    public function updatedRefPickerSearch(): void
    {
        if ($this->refPickerOpen) {
            $this->refreshRefPicker();
        }
    }

    /**
     * Filling the ref input: writes label into `branch` and flips
     * `form.ref_kind` so the segmented control + downstream form data
     * line up with what was picked.
     */
    public function selectRefPickerValue(string $value, string $kind): void
    {
        if (! in_array($kind, ['branch', 'tag', 'commit'], true)) {
            return;
        }
        $this->branch = $value;
        $this->form->ref_kind = $kind;
        $this->refPickerOpen = false;
        $this->maybeAutoDetectFromRepository();
    }

    private function resolvePickerTabFromRefKind(): string
    {
        return match ($this->form->ref_kind) {
            'tag' => 'tags',
            'commit' => 'commits',
            default => 'branches',
        };
    }

    /**
     * Fetch refs from the host's REST API. Currently GitHub-only;
     * GitLab/Bitbucket fall back to a friendly "use manual entry" notice.
     * Auth via the user's linked GitIdentity when available so private
     * repos work and rate limits move from 60/hr → 5000/hr.
     */
    private function refreshRefPicker(): void
    {
        $this->refPickerLoading = true;
        $this->refPickerError = null;
        $this->refPickerResults = [];

        try {
            $ownerName = $this->parseGitHubOwnerName(trim($this->repo));
            if ($ownerName === null) {
                $this->refPickerError = __('Ref picker currently supports GitHub repos. Enter a branch / tag / SHA manually for other hosts.');

                return;
            }

            [$owner, $name] = $ownerName;
            $http = $this->githubHttpClient();

            if ($this->refPickerTab === 'branches') {
                $response = $http->get("/repos/{$owner}/{$name}/branches", ['per_page' => 100]);
                if (! $response->successful()) {
                    $this->refPickerError = (string) ($response->json('message') ?: __('Could not load branches.'));

                    return;
                }
                $this->refPickerResults = $this->filterResults(
                    collect($response->json() ?? [])->map(fn (array $b): array => [
                        'label' => (string) ($b['name'] ?? ''),
                        'sha' => (string) ($b['commit']['sha'] ?? ''),
                        'meta' => ! empty($b['protected']) ? __('protected') : null,
                    ])->all(),
                );
            } elseif ($this->refPickerTab === 'tags') {
                $response = $http->get("/repos/{$owner}/{$name}/tags", ['per_page' => 100]);
                if (! $response->successful()) {
                    $this->refPickerError = (string) ($response->json('message') ?: __('Could not load tags.'));

                    return;
                }
                $this->refPickerResults = $this->filterResults(
                    collect($response->json() ?? [])->map(fn (array $t): array => [
                        'label' => (string) ($t['name'] ?? ''),
                        'sha' => (string) ($t['commit']['sha'] ?? ''),
                        'meta' => null,
                    ])->all(),
                );
            } else { // commits
                $branchForCommits = trim($this->branch) !== '' && $this->form->ref_kind !== 'commit'
                    ? trim($this->branch)
                    : 'main';
                $response = $http->get("/repos/{$owner}/{$name}/commits", [
                    'sha' => $branchForCommits,
                    'per_page' => 30,
                ]);
                if (! $response->successful()) {
                    $this->refPickerError = (string) ($response->json('message') ?: __('Could not load commits.'));

                    return;
                }
                $this->refPickerResults = $this->filterResults(
                    collect($response->json() ?? [])->map(function (array $c): array {
                        $sha = (string) ($c['sha'] ?? '');
                        $msg = (string) ($c['commit']['message'] ?? '');
                        $firstLine = explode("\n", $msg, 2)[0] ?? '';

                        return [
                            'label' => substr($sha, 0, 7),
                            'sha' => $sha,
                            'meta' => trim($firstLine),
                        ];
                    })->all(),
                );
            }
        } catch (\Throwable $e) {
            $this->refPickerError = $e->getMessage();
        } finally {
            $this->refPickerLoading = false;
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseGitHubOwnerName(string $repo): ?array
    {
        if ($repo === '') {
            return null;
        }
        $normalized = EdgeCreateForm::normalizeRepo($repo);
        if (preg_match('#^https?://github\.com/#i', $normalized) === 1) {
            return null; // GitLab/Bitbucket normalize to full URL, skip
        }
        if (str_contains($normalized, '/') && substr_count($normalized, '/') === 1) {
            [$owner, $name] = explode('/', $normalized, 2);
            $owner = trim($owner);
            $name = trim($name);
            if ($owner !== '' && $name !== '') {
                return [$owner, $name];
            }
        }

        return null;
    }

    private function githubHttpClient(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->timeout(8)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);

        $user = auth()->user();
        if ($user !== null) {
            $identity = app(GitIdentityResolver::class)->forUserProvider($user, 'github');
            $token = $identity?->accessToken();
            if (is_string($token) && $token !== '') {
                $client = $client->withToken($token);
            }
        }

        return $client;
    }

    /**
     * @param  list<array{label: string, sha: string, meta: ?string}>  $rows
     * @return list<array{label: string, sha: string, meta: ?string}>
     */
    private function filterResults(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => ($r['sha'] ?? '') !== ''));
        $search = mb_strtolower(trim($this->refPickerSearch));
        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $r) use ($search): bool {
            foreach (['label', 'sha', 'meta'] as $field) {
                $value = mb_strtolower((string) ($r[$field] ?? ''));
                if ($value !== '' && str_contains($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function updatedRepo(): void
    {
        if ($this->repo_source !== 'manual') {
            return;
        }

        // Operators commonly paste a full GitHub URL (browser tab, README badge,
        // `git clone` line). Canonicalize to `owner/name` so the rest of the
        // flow — detection fingerprint, persisted edgeMeta.source.repo, alias
        // hostnames — sees the shape it expects. Also lift a branch hint out
        // of `/tree/<branch>` URLs, but only when the user hasn't typed their
        // own branch yet (default 'main' counts as untouched).
        $raw = trim($this->repo);
        if ($raw !== '') {
            $branchHint = EdgeCreateForm::branchHintFromUrl($raw);
            $normalized = EdgeCreateForm::normalizeRepo($raw);
            if ($normalized !== $raw) {
                $this->repo = $normalized;
            }
            if ($branchHint !== null && ($this->branch === '' || $this->branch === 'main')) {
                $this->branch = $branchHint;
            }

            $this->maybeSeedAppNameFromRepo();
        }

        // Inline validation — push a clear error into the bag so the operator
        // doesn't have to wait until Deploy to learn "the top one seems
        // usless" isn't a valid repo. Empty strings are skipped here so
        // the field doesn't yell at someone who's just clicked into it.
        if (trim($this->repo) !== '') {
            $this->resetErrorBag('repo');
            if (! $this->isPlausibleRepoRef($this->repo)) {
                $this->addError('repo', __('Enter :format or a full GitHub / GitLab / Bitbucket URL.', ['format' => 'owner/name']));

                return;
            }
        }

        $this->maybeAutoDetectFromRepository();
    }

    /**
     * Auto-set $form->name from the current $repo value when the name
     * field is empty. Centralized so the connected-account picker,
     * manual-typing path, and query-prefill path all benefit. Sticky
     * once the operator types anything.
     */
    private function maybeSeedAppNameFromRepo(): void
    {
        if (trim($this->form->name) !== '') {
            return;
        }
        $repoName = $this->extractRepoNameForApp($this->repo);
        if ($repoName !== '') {
            $this->form->name = $repoName;
        }
    }

    /**
     * Pull the repo name (no owner, no host) out of a normalized repo
     * reference. Used to seed the app name field. Returns '' when the
     * shape doesn't yield an obvious name.
     */
    private function extractRepoNameForApp(string $value): string
    {
        $value = trim($value, " \t\n\r/");
        if ($value === '') {
            return '';
        }

        // Full URL → keep just the last path segment
        if (preg_match('#^https?://[^/]+/(.+)$#i', $value, $m) === 1) {
            $value = $m[1];
        } elseif (preg_match('#^git@[^:]+:(.+)$#i', $value, $m) === 1) {
            $value = $m[1];
        }

        // Strip a trailing `.git`
        $value = preg_replace('/\.git$/i', '', $value) ?? $value;

        $parts = array_filter(explode('/', trim($value, '/')));
        $name = (string) end($parts);

        return strtolower(trim($name));
    }

    /**
     * Loose plausibility check — passes the common shapes (owner/name,
     * normalized full URLs for the three known hosts, SSH git@ URLs) and
     * rejects free text like "the top one seems useless". Backend
     * validation still runs at deploy time; this is a pre-flight nudge.
     */
    private function isPlausibleRepoRef(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // owner/name shorthand (what GitHub URLs normalize to)
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value) === 1) {
            return true;
        }

        // Full URL on a known host (GitLab / Bitbucket normalize here)
        if (preg_match('#^https?://(github\.com|gitlab\.com|bitbucket\.org)/[^\s]+$#i', $value) === 1) {
            return true;
        }

        // SSH form (rare on the create form but accept for paste-from-clone)
        if (preg_match('#^git@(github\.com|gitlab\.com|bitbucket\.org):[^\s]+$#i', $value) === 1) {
            return true;
        }

        return false;
    }

    public function updatedBranch(): void
    {
        if ($this->repo_source === 'connected') {
            if (trim($this->repo) !== '') {
                $this->detectFromRepository();
            }

            return;
        }

        $this->maybeAutoDetectFromRepository();
    }

    public function updatedSourceControlAccountId(): void
    {
        $this->repository_selection = '';
        $this->loadRepositoriesForSelectedAccount();
    }

    public function updatedRepositorySelection(string $value): void
    {
        if ($value === '') {
            return;
        }

        $match = collect($this->availableRepositories)->firstWhere('url', $value);
        if (! is_array($match)) {
            return;
        }

        $this->repo = EdgeCreateForm::normalizeRepo((string) $match['url']);
        $this->branch = is_string($match['branch'] ?? null) && $match['branch'] !== ''
            ? (string) $match['branch']
            : 'main';

        $this->maybeSeedAppNameFromRepo();
        $this->detectFromRepository();
    }

    public function detectFromRepository(): void
    {
        $this->runDetection($this->normalizeToCloneUrl($this->repo), $this->branch);
        $this->detectMonorepoFromRepository();
    }

    /**
     * Override the trait's blocking detection with a queue-backed flow.
     * Repos like withastro/starlight take >30s to clone+inspect and
     * crash PHP's request wall-clock. We dispatch a DetectRepositoryRuntimeJob,
     * cache the result keyed by (url, branch), and let the view poll
     * pollRuntimeDetection until it lands. Same-key repeats reuse the
     * cached result — no re-cloning when the operator just retypes.
     */
    public function runDetection(string $url, string $branch): void
    {
        // Defensive ceiling — anything slow here is a bug we want to
        // see fail loud (queue dispatch + 200ms HTTP fetches are
        // nowhere near this), but if a fall-through to the trait's
        // sync clone ever happens we shouldn't crash at PHP's
        // 30s default. Bumped to 120s gives the worker headroom too.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        // Breadcrumb so operators can confirm which code path is
        // actually running. Grep storage/logs/laravel.log for this
        // string — if it's missing during a slow request, opcache is
        // serving stale code and `valet restart` is required.
        \Illuminate\Support\Facades\Log::info('[edge.create.runDetection]', [
            'url' => $url, 'branch' => $branch, 'pid' => getmypid(),
        ]);

        $url = trim($url);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';

        if ($url === '') {
            $this->detectedPlan = [];
            $this->runtimeDetectionPending = false;
            $this->runtimeDetectionKey = '';
            $this->applyDetectedRuntimePrefills();

            return;
        }

        $key = 'edge-detect:'.sha1($url.'|'.$branch);
        $this->runtimeDetectionKey = $key;
        $cached = Cache::get($key);

        // Already done — surface immediately, no job dispatch.
        if (is_array($cached) && in_array($cached['state'] ?? null, ['done', 'failed'], true)) {
            $this->detectedPlan = (array) ($cached['plan'] ?? []);
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return;
        }

        // Fast path: GitHub repos can be detected by fetching ~25 small
        // files via Contents API in parallel (200-400ms) instead of
        // cloning. Skip the queue entirely when this succeeds. Falls
        // through to the queued clone for non-GitHub repos, private
        // repos without auth, or repos where the fast path errors.
        if ($this->tryFastGitHubDetection($url, $branch, $key)) {
            return;
        }

        $this->detectedPlan = [];
        $this->runtimeDetectionPending = true;

        // Only dispatch if no fresh job is already in flight for this key.
        // "Stuck" entries (queued/running but old) get re-dispatched.
        $shouldDispatch = ! is_array($cached)
            || ! in_array($cached['state'] ?? null, ['queued', 'running'], true)
            || $this->isStaleDetectionEntry($cached);

        if ($shouldDispatch) {
            Cache::put($key, [
                'state' => 'queued',
                'url' => $url,
                'branch' => $branch,
                'dispatched_at' => now()->toIso8601String(),
            ], now()->addHours(24));
            DetectRepositoryRuntimeJob::dispatch($key, $url, $branch);
        }
    }

    /**
     * Sync GitHub-only fast path. Returns true when detection
     * completes inline (cache + state populated, no job dispatched).
     * Returns false to signal the caller to fall back to the queued
     * clone-based detection.
     *
     * For Node repos we skip the heavy RepositoryRuntimePlanComposer
     * entirely — the composer runs every runtime detector (Node,
     * Python, Ruby, Go, PHP, Static) against the on-disk tree, which
     * is overkill when we already know we have package.json. A
     * straight package.json parse + framework heuristic returns a
     * usable plan in <50ms instead of >1s.
     */
    private function tryFastGitHubDetection(string $url, string $branch, string $cacheKey): bool
    {
        // Only handle GitHub URLs (or owner/name shorthand which we
        // normalize to a GitHub host). GitLab/Bitbucket fall back.
        //
        // Lazy `+?` + explicit boundary so repo names containing dots
        // (tailwindcss.com, react.dev, nodejs.org) match correctly;
        // the previous `[^/.]+` stopped at the first dot and made the
        // owner/repo pair wrong → 404 → fall-through to the slow path.
        if (preg_match('~github\.com[/:]([^/]+)/([^/\s?#]+?)(?:\.git)?(?:[/?#]|$)~i', $url, $m) !== 1) {
            return false;
        }
        $owner = $m[1];
        $repo = rtrim($m[2], '/');

        try {
            // Single Contents API call for package.json — that's all the
            // Node fast path needs. ~200ms round-trip end-to-end.
            $packageJson = $this->fetchPackageJsonFromGitHub($owner, $repo, $branch);
            if ($packageJson === null) {
                return false;
            }

            $planArray = $this->synthesizeNodePlan($packageJson, $url, $branch);
            if ($planArray === null) {
                // package.json present but framework unknown — defer to
                // the clone-based composer which has broader heuristics.
                return false;
            }

            Cache::put($cacheKey, [
                'state' => 'done',
                'plan' => $planArray,
                'source' => 'fast-path',
            ], now()->addHours(24));

            $this->detectedPlan = $planArray;
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return true;
        } catch (\Throwable) {
            // Network blip, GitHub rate-limit, malformed response — let
            // the queued clone path handle it.
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPackageJsonFromGitHub(string $owner, string $repo, string $branch): ?array
    {
        // Path 1: raw.githubusercontent.com — direct file content, no
        // API metadata wrapping, no base64 decode, no per-IP rate limit
        // worth worrying about. Works for any PUBLIC repo. Typically
        // 80-150ms.
        try {
            $rawUrl = sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/package.json',
                $owner,
                $repo,
                $branch,
            );
            $rawResponse = Http::timeout(5)->get($rawUrl);
            if ($rawResponse->successful()) {
                $parsed = json_decode($rawResponse->body(), true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        } catch (\Throwable) {
            // Fall through to authenticated API path.
        }

        // Path 2: authenticated Contents API for private repos. Slightly
        // slower (base64 wrap + 1 extra hop) but the only way to read
        // private repos when the user has a linked GitHub identity.
        $user = auth()->user();
        $token = null;
        if ($user !== null) {
            try {
                $identity = app(GitIdentityResolver::class)->forUserProvider($user, 'github');
                $accessToken = $identity?->accessToken();
                if (is_string($accessToken) && $accessToken !== '') {
                    $token = $accessToken;
                }
            } catch (\Throwable) {
                // Anonymous fall-through.
            }
        }

        if ($token === null) {
            // No auth and raw failed — private repo we can't read, or
            // 404 — defer to the queued clone path.
            return null;
        }

        $apiResponse = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->timeout(6)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withToken($token)
            ->get("/repos/{$owner}/{$repo}/contents/package.json", ['ref' => $branch]);

        if (! $apiResponse->successful()) {
            return null;
        }

        $body = $apiResponse->json();
        if (! is_array($body) || ! isset($body['content'])) {
            return null;
        }

        $decoded = base64_decode((string) preg_replace('/\s+/', '', (string) $body['content']), true);
        if ($decoded === false) {
            return null;
        }

        $parsed = json_decode($decoded, true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Heuristic plan synthesis from a parsed package.json. Recognizes
     * the most common JS frameworks operators deploy to Edge and maps
     * each to a sensible default build command + output dir. Returns
     * null when we can't classify (caller falls back to clone).
     *
     * @param  array<string, mixed>  $pkg
     * @return array<string, mixed>|null
     */
    private function synthesizeNodePlan(array $pkg, string $url, string $branch): ?array
    {
        $deps = array_merge(
            is_array($pkg['dependencies'] ?? null) ? $pkg['dependencies'] : [],
            is_array($pkg['devDependencies'] ?? null) ? $pkg['devDependencies'] : [],
        );

        // Framework lookup: dep name → [framework, default build, default output]
        $frameworkMap = [
            'astro' => ['astro', 'npm run build', 'dist'],
            'next' => ['nextjs', 'npm run build', 'out'],
            'nuxt' => ['nuxt', 'npm run generate', '.output/public'],
            'gatsby' => ['gatsby', 'npm run build', 'public'],
            '@sveltejs/kit' => ['sveltekit', 'npm run build', 'build'],
            'vite' => ['vite', 'npm run build', 'dist'],
            '@11ty/eleventy' => ['eleventy', 'npm run build', '_site'],
            'vitepress' => ['vitepress', 'npm run docs:build', 'docs/.vitepress/dist'],
            '@docusaurus/core' => ['docusaurus', 'npm run build', 'build'],
        ];

        $framework = null;
        $build = (string) ($pkg['scripts']['build'] ?? '');
        $output = null;
        foreach ($frameworkMap as $dep => [$f, $b, $o]) {
            if (isset($deps[$dep])) {
                $framework = $f;
                $build = $build !== '' ? 'npm run build' : $b;
                $output = $o;
                break;
            }
        }

        // Fallback Node plan when no framework matched but a build
        // script exists — assume vite/webpack-style dist output.
        if ($framework === null) {
            if (! isset($pkg['scripts']['build'])) {
                return null;
            }
            $framework = 'node_generic';
            $build = 'npm run build';
            $output = 'dist';
        }

        $engines = (string) ($pkg['engines']['node'] ?? '');
        $version = $engines !== '' ? $engines : null;

        return [
            'url' => $url,
            'branch' => $branch,
            'runtime' => 'node',
            'version' => $version,
            'framework' => $framework,
            'build_command' => $build,
            'start_command' => null,
            'app_port' => null,
            'output_dir' => $output,
            'confidence' => 'high',
            'sources' => ['package.json'],
            'reasons' => ['Fast-path GitHub API detection'],
            'warnings' => [],
            'has_manifest' => true,
            'processes' => [],
        ];
    }

    /**
     * Detection job died / never wrote a result and the cache entry
     * has been sitting in queued/running for longer than the job's
     * timeout. Pop it so the next dispatch attempt gets a fresh run.
     *
     * @param  array<string, mixed>  $cached
     */
    private function isStaleDetectionEntry(array $cached): bool
    {
        $dispatchedAt = $cached['dispatched_at'] ?? null;
        if (! is_string($dispatchedAt) || $dispatchedAt === '') {
            return false;
        }
        try {
            $ts = \Carbon\Carbon::parse($dispatchedAt);
        } catch (\Throwable) {
            return true;
        }

        return $ts->diffInSeconds(now()) > 200;
    }

    /**
     * View-side wire:poll target while the runtime detection job is in
     * flight. Reads the cache key the matching dispatch wrote to and
     * flips state back to "done" once the worker stores a result.
     */
    public function pollRuntimeDetection(): void
    {
        if (! $this->runtimeDetectionPending || $this->runtimeDetectionKey === '') {
            return;
        }

        $cached = Cache::get($this->runtimeDetectionKey);

        // Cache entry vanished entirely — TTL expired without a result
        // landing. Treat as a failure so the operator isn't stuck on a
        // forever spinner.
        if (! is_array($cached)) {
            $this->detectedPlan = [
                'error' => __('Detection timed out. Click "Detect runtime" to retry.'),
            ];
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return;
        }

        $state = $cached['state'] ?? null;

        // Job dispatched too long ago and still queued/running — worker
        // most likely died (Horizon stopped, OOM, …). Surface as failed
        // so the UI unblocks and the next Detect runtime click re-dispatches.
        if (in_array($state, ['queued', 'running'], true) && $this->isStaleDetectionEntry($cached)) {
            $this->detectedPlan = [
                'error' => __('Detection took longer than expected and may have died. Click "Detect runtime" to try again.'),
            ];
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return;
        }

        if (! in_array($state, ['done', 'failed'], true)) {
            return; // still in flight, keep polling
        }

        $this->detectedPlan = (array) ($cached['plan'] ?? []);
        $this->runtimeDetectionPending = false;
        $this->applyDetectedRuntimePrefills();
    }

    public function updatedFormRepoRoot(): void
    {
        $this->repoRootTouched = true;
    }

    private function detectMonorepoFromRepository(): void
    {
        $url = $this->normalizeToCloneUrl($this->repo);
        $branch = trim($this->branch) !== '' ? trim($this->branch) : 'main';
        if ($url === '') {
            $this->monorepoDetected = false;
            $this->monorepoPackages = [];
            $this->monorepoMarkers = [];

            return;
        }

        try {
            $result = app(EdgeMonorepoDetector::class)->inspectUrl($url, $branch);
            $this->monorepoDetected = (bool) ($result['is_monorepo'] ?? false);
            $this->monorepoPackages = is_array($result['packages'] ?? null) ? $result['packages'] : [];
            $this->monorepoMarkers = is_array($result['markers'] ?? null) ? $result['markers'] : [];

            if ($this->monorepoDetected && ! $this->repoRootTouched && count($this->monorepoPackages) === 1) {
                $this->form->repo_root = (string) ($this->monorepoPackages[0]['path'] ?? '');
            }
        } catch (\Throwable) {
            $this->monorepoDetected = false;
            $this->monorepoPackages = [];
            $this->monorepoMarkers = [];
        }
    }

    private function maybeAutoDetectFromRepository(): void
    {
        $repo = trim($this->repo);
        $branch = trim($this->branch) !== '' ? trim($this->branch) : 'main';

        if ($repo === '') {
            $this->lastDetectionFingerprint = '';
            $this->runDetection('', $branch);

            return;
        }

        if (! $this->repoLooksComplete($repo) || $branch === '') {
            return;
        }

        $fingerprint = $this->normalizeToCloneUrl($repo).'|'.$branch;
        if ($fingerprint === $this->lastDetectionFingerprint) {
            return;
        }

        $this->lastDetectionFingerprint = $fingerprint;
        $this->detectFromRepository();
    }

    private function repoLooksComplete(string $repo): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $repo) === 1 || str_starts_with($repo, 'git@')) {
            return true;
        }

        $normalized = trim($repo, '/');
        if (! str_contains($normalized, '/')) {
            return false;
        }

        [$owner, $name] = array_pad(explode('/', $normalized, 2), 2, '');

        return trim($owner) !== '' && trim($name) !== '';
    }

    public function updatedFormName(): void
    {
        if ($this->prefillingFromDetection) {
            return;
        }

        if ($this->form->runtime_mode === 'hybrid' && ! $this->originUrlTouched) {
            $this->applyHybridOriginSuggestion();
        }
    }

    public function updatedFormRuntimeMode(): void
    {
        if ($this->prefillingFromDetection) {
            return;
        }

        $this->runtimeModeTouched = true;

        if ($this->form->runtime_mode === 'hybrid') {
            $this->applyHybridOriginSuggestion();
        }
    }

    public function updatedFormOriginUrl(): void
    {
        if ($this->prefillingOrigin) {
            return;
        }

        $this->originUrlTouched = true;
    }

    public function updatedFormOriginCloudSiteId(string $value): void
    {
        if ($value === '') {
            if (! $this->originUrlTouched) {
                $this->prefillingOrigin = true;
                $this->form->origin_url = '';
                $this->prefillingOrigin = false;
            }

            return;
        }

        $site = $this->findOrgCloudSite($value);
        if ($site === null) {
            return;
        }

        $liveUrl = $site->containerLiveUrl();
        $this->form->origin_url = $liveUrl ?? '';
        $this->originUrlTouched = $liveUrl !== null;
    }

    public function updatedFormBuildCommand(): void
    {
        $this->buildOverridesTouched = true;
    }

    public function updatedFormOutputDir(): void
    {
        $this->buildOverridesTouched = true;
    }

    protected function applyDetectedRuntimePrefills(): void
    {
        if ($this->buildOverridesTouched) {
            return;
        }

        // Detection ships its own build_command + output_dir when
        // they're confident; we honor those verbatim. When either is
        // missing we fall back to the framework preset registry —
        // single source of truth shared with the build cache + import
        // wizard.
        $preset = EdgeFrameworkPresetRegistry::byDetectionPlan($this->detectedPlan);

        $build = trim((string) ($this->detectedPlan['build_command'] ?? ''));
        if ($build !== '') {
            $this->form->build_command = $build;
        } elseif ($preset->buildCommand !== '') {
            $this->form->build_command = $preset->buildCommand;
        }

        $detectedOutput = trim((string) ($this->detectedPlan['output_dir'] ?? ''));
        if ($detectedOutput !== '') {
            $this->form->output_dir = $detectedOutput;

            return;
        }

        if ($this->form->output_dir === '' || $this->form->output_dir === 'dist') {
            $this->form->output_dir = $preset->outputDir !== '' ? $preset->outputDir : 'dist';
        }

        $this->applyDetectedDeliveryPrefills();
    }

    private function applyDetectedDeliveryPrefills(): void
    {
        if ($this->runtimeModeTouched || $this->detectedPlan === []) {
            return;
        }

        if (! EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)) {
            return;
        }

        if (trim($this->form->name) === '' && trim($this->repo) !== '') {
            $this->prefillingFromDetection = true;
            $this->form->name = $this->defaultNameFromRepo();
            $this->prefillingFromDetection = false;
        }

        $this->prefillingFromDetection = true;
        $this->form->runtime_mode = 'hybrid';
        $this->prefillingFromDetection = false;
        $this->applyHybridOriginSuggestion();
    }

    private function defaultNameFromRepo(): string
    {
        $repo = HybridEdgeOriginMatcher::normalizeRepo(trim($this->repo));
        if ($repo === '') {
            return '';
        }

        $segment = (string) (array_slice(explode('/', $repo), -1)[0] ?? '');

        return Str::title(str_replace(['-', '_'], ' ', $segment));
    }

    private function applyHybridOriginSuggestion(): void
    {
        if ($this->originUrlTouched) {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $this->prefillingOrigin = true;

        try {
            $repo = HybridEdgeOriginMatcher::normalizeRepo(trim($this->repo));
            if ($repo !== '') {
                $matched = HybridEdgeOriginMatcher::findForRepo($org, $repo);
                if ($matched !== null) {
                    $this->form->origin_cloud_site_id = (string) $matched->id;
                    $this->form->origin_url = $matched->containerLiveUrl() ?? '';

                    return;
                }
            }

            $name = trim($this->form->name) ?: $this->defaultNameFromRepo();
            if ($name === '') {
                $this->form->origin_cloud_site_id = '';
                $this->form->origin_url = '';

                return;
            }

            $matched = HybridEdgeOriginMatcher::findForEdgeName($org, $name);
            if ($matched !== null) {
                $this->form->origin_cloud_site_id = (string) $matched->id;
                $this->form->origin_url = $matched->containerLiveUrl() ?? '';

                return;
            }

            $this->form->origin_cloud_site_id = '';
            $this->form->origin_url = $this->suggestedHybridOriginUrl($name);
        } finally {
            $this->prefillingOrigin = false;
        }
    }

    public function suggestedHybridOriginUrlForName(): string
    {
        $name = trim($this->form->name);

        return $name !== '' ? $this->suggestedHybridOriginUrl($name) : '';
    }

    private function suggestedHybridOriginUrl(string $name): string
    {
        if ($this->canProvisionCloudOrigin()) {
            return '';
        }

        if (FakeCloudProvision::enabled()) {
            $slug = Str::slug($name) ?: 'app';

            return 'https://'.$slug.'.fake-cloud.dply.local';
        }

        return '';
    }

    private function findOrgCloudSite(string $siteId): ?Site
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return null;
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->find($siteId);
    }

    /**
     * @return list<array{id: string, label: string, live_url: ?string, repo: ?string}>
     */
    private function orgCloudSitesForPicker(): array
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return [];
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->orderBy('name')
            ->get()
            ->map(function (Site $site): array {
                $container = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
                $source = is_array($container['source'] ?? null) ? $container['source'] : [];

                return [
                    'id' => (string) $site->id,
                    'label' => (string) $site->name,
                    'live_url' => $site->containerLiveUrl(),
                    'repo' => is_string($source['repo'] ?? null) ? (string) $source['repo'] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function validateCreateForm(): void
    {
        $this->validate([
            'repo' => ['required', 'string', 'max:200'],
            'branch' => ['required', 'string', 'max:120'],
        ]);

        $this->form->validate();
    }

    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validateCreateForm();

        if ($this->detectedPlan !== [] && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)
            && ! in_array($this->form->runtime_mode, ['hybrid', 'ssr'], true)) {
            $this->toastError(__('This repository looks like an SSR app. Pick "Worker-native SSR" (Next.js via OpenNext), hybrid mode with an origin URL, or use dply Cloud for full server workloads.'));

            return;
        }

        if ($this->form->runtime_mode === 'hybrid' && trim($this->form->origin_url) === '' && $this->shouldAutoProvisionHybridOrigin()) {
            $this->deployHybridStack();

            return;
        }

        if ($this->form->runtime_mode === 'hybrid' && trim($this->form->origin_url) === '') {
            $this->toastError(__('Enter the SSR origin URL for hybrid delivery.'));

            return;
        }

        try {
            $site = (new CreateEdgeSite)->handle(
                auth()->user(),
                $org,
                $this->form->createEdgeSitePayload(
                    (string) ($this->detectedPlan['framework'] ?? ''),
                    $this->repo,
                    $this->branch,
                ),
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $importedCount = $this->persistImportedEnvVars($site);
        if ($importedCount > 0) {
            $this->toastSuccess(__('Edge app build queued — :count env var(s) imported.', ['count' => $importedCount]));
        } else {
            $this->toastSuccess(__('Edge app build queued. We\'ll keep the site workspace updated as it goes live.'));
        }
        $this->redirect(route('sites.show', ['server' => $site->server, 'site' => $site]), navigate: true);
    }

    /**
     * Flush imported env vars onto the freshly-created site as
     * production-scope EdgeSiteEnvVar rows. Skips keys that conflict
     * with platform-reserved names (HOST_MAP, ASSETS, etc) or aren't
     * shaped as ALL_CAPS_WITH_UNDERSCORES. Returns the count actually
     * persisted so the success toast can report it.
     */
    private function persistImportedEnvVars(Site $site): int
    {
        if ($this->pendingImportedEnvVars === []) {
            return 0;
        }

        $count = 0;
        foreach ($this->pendingImportedEnvVars as $key => $value) {
            if (! is_string($key) || ! EdgeSiteEnvVar::keyIsValid($key)) {
                continue;
            }
            $stringValue = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            if ($stringValue === '') {
                // Importer signals secret-redacted values with an
                // empty string (Cloudflare Pages); skip — the user
                // re-enters them via the dashboard env panel.
                continue;
            }
            (new EdgeSiteEnvVar([
                'site_id' => $site->id,
                'key' => $key,
                'value' => $stringValue,
                'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
                'created_by_user_id' => auth()->id(),
            ]))->save();
            $count++;
        }
        $this->pendingImportedEnvVars = [];

        return $count;
    }

    public function openHybridStackModal(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validateCreateForm();

        $this->confirmingHybridStack = true;
        $this->dispatch('open-modal', 'edge-create-hybrid-stack-confirmation');
    }

    public function closeHybridStackModal(): void
    {
        $this->confirmingHybridStack = false;
        $this->dispatch('close-modal', 'edge-create-hybrid-stack-confirmation');
    }

    public function deployHybridStack(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validateCreateForm();

        if ($this->detectedPlan !== [] && ! EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)) {
            $this->toastError(__('Hybrid stack deploy is only available for server-rendered JavaScript frameworks.'));

            return;
        }

        try {
            $result = (new CreateHybridEdgeStack)->handle(
                auth()->user(),
                $org,
                $this->form->hybridStackPayload($this->detectedPlan, $this->repo, $this->branch),
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->closeHybridStackModal();

        if ($result['redirect_to'] === 'edge' && $result['edge_site'] instanceof Site) {
            $this->toastSuccess(__('Edge hybrid app build queued. We\'ll keep the site workspace updated as it goes live.'));
            $this->redirect(route('sites.show', ['server' => $result['edge_site']->server, 'site' => $result['edge_site']]), navigate: true);

            return;
        }

        $this->toastSuccess(__('Cloud origin queued. We\'ll create the Edge hybrid app when the origin is live.'));
        $this->redirect(route('sites.show', ['server' => $result['cloud_site']->server, 'site' => $result['cloud_site']]), navigate: true);
    }

    private function canProvisionCloudOrigin(): bool
    {
        if (! Feature::active('surface.cloud')) {
            return false;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return false;
        }

        return CloudRouter::pickAutoBackend($org->id) !== null;
    }

    private function showHybridStackCta(): bool
    {
        return $this->shouldAutoProvisionHybridOrigin();
    }

    private function shouldAutoProvisionHybridOrigin(): bool
    {
        return $this->form->runtime_mode === 'hybrid'
            && trim($this->form->origin_url) === ''
            && $this->detectedPlan !== []
            && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)
            && $this->canProvisionCloudOrigin();
    }

    private function loadRepositoriesForSelectedAccount(): void
    {
        if ($this->source_control_account_id === '') {
            $this->availableRepositories = [];

            return;
        }

        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->source_control_account_id)
            : null;
        $this->availableRepositories = $account
            ? app(SourceControlRepositoryBrowser::class)->repositoriesForAccount($account)
            : [];
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        if ($this->source_control_account_id === '') {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
        }

        $this->loadRepositoriesForSelectedAccount();
        $this->repo_source = 'connected';
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $cloudflareCredentials = $org
            ? ProviderCredential::query()
                ->where('organization_id', $org->id)
                ->where('provider', 'cloudflare')
                ->latest()
                ->get()
            : collect();

        return view('livewire.edge.create', [
            'fakeEdgeActive' => FakeEdgeProvision::enabled(),
            'edgeFee' => app(ManagedProductCostEstimator::class)->edgeFee(),
            'edgeUsageBillingEnabled' => app(ManagedProductCostEstimator::class)->edgeUsageBillingEnabled(),
            'edgeUsageRates' => app(ManagedProductCostEstimator::class)->edgeUsageRates(),
            'cloudflareCredentials' => $cloudflareCredentials,
            'orgCloudSites' => $this->orgCloudSitesForPicker(),
            'ssrDetected' => $this->detectedPlan !== [] && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan),
            'suggestedHybridOriginUrl' => $this->suggestedHybridOriginUrlForName(),
            'showHybridStackCta' => $this->showHybridStackCta(),
            'autoProvisionHybridOrigin' => $this->shouldAutoProvisionHybridOrigin(),
            'canProvisionCloudOrigin' => $this->canProvisionCloudOrigin(),
            'cloudFee' => app(ManagedProductCostEstimator::class)->cloudFee(),
        ])->layout('layouts.app');
    }
}
