<?php

declare(strict_types=1);

/**
 * Generate one PHPStan harness file per Concerns root so traits are analysed in isolation.
 *
 * Usage: php scripts/generate-livewire-phpstan-harness.php
 */
$projectRoot = dirname(__DIR__);
$livewireRoot = $projectRoot.'/app/Livewire/';

$roots = [
    'app/Livewire/Concerns' => 'App\\Livewire\\Concerns',
    'app/Livewire/Servers/Concerns' => 'App\\Livewire\\Servers\\Concerns',
];

function traitShortName(string $fqcn): string
{
    $pos = strrpos($fqcn, '\\');

    return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
}

/** @var array<string, list<string>> */
$extraUses = [
    'BuildsCommandPaletteGroups' => [
        'App\\Livewire\\Concerns\\ManagesCommandPaletteStack',
        'App\\Livewire\\Concerns\\ResolvesCommandPaletteItems',
        'App\\Livewire\\Concerns\\RunsCommandPaletteActions',
    ],
    'ManagesCommandPaletteStack' => [
        'App\\Livewire\\Concerns\\BuildsCommandPaletteGroups',
        'App\\Livewire\\Concerns\\ResolvesCommandPaletteItems',
        'App\\Livewire\\Concerns\\RunsCommandPaletteActions',
    ],
    'ResolvesCommandPaletteItems' => [
        'App\\Livewire\\Concerns\\BuildsCommandPaletteGroups',
        'App\\Livewire\\Concerns\\ManagesCommandPaletteStack',
        'App\\Livewire\\Concerns\\RunsCommandPaletteActions',
    ],
    'RunsCommandPaletteActions' => [
        'App\\Livewire\\Concerns\\BuildsCommandPaletteGroups',
        'App\\Livewire\\Concerns\\ManagesCommandPaletteStack',
        'App\\Livewire\\Concerns\\ResolvesCommandPaletteItems',
    ],
    'ManagesEdgePreviews' => ['App\\Livewire\\Concerns\\Edge\\ManagesEdgeDeployCommit', 'App\\Livewire\\Concerns\\ConfirmsActionWithModal'],
    'ManagesEdgeDeploymentLifecycle' => ['App\\Livewire\\Concerns\\ConfirmsActionWithModal'],
    'ManagesDeployContract' => ['App\\Livewire\\Concerns\\ConfirmsActionWithModal'],
    'ManagesEdgeSite' => ['App\\Livewire\\Concerns\\ConfirmsActionWithModal'],
    'OptimizesPipeline' => [
        'App\\Livewire\\Concerns\\DispatchesToastNotifications',
        'App\\Livewire\\Concerns\\WatchesConsoleActionOutcomes',
    ],
    'ManagesSiteBindingActions' => ['App\\Livewire\\Concerns\\WatchesConsoleActionOutcomes'],
    'ManagesSiteBindingMail' => ['App\\Livewire\\Concerns\\WatchesConsoleActionOutcomes'],
    'VerifiesSiteBindings' => ['App\\Livewire\\Concerns\\WatchesConsoleActionOutcomes'],
    'ManagesNotificationChannels' => ['App\\Livewire\\Concerns\\ConfirmsActionWithModal', 'App\\Livewire\\Concerns\\DispatchesToastNotifications'],
    'ManagesSiteLogging' => ['App\\Livewire\\Concerns\\DispatchesToastNotifications', 'App\\Livewire\\Concerns\\WatchesConsoleActionOutcomes'],
    'InteractsWithServerWorkspace' => ['App\\Livewire\\Concerns\\DispatchesToastNotifications'],
    'RendersWorkspacePlaceholder' => ['App\\Livewire\\Servers\\Concerns\\InteractsWithServerWorkspace'],
];

/** @var array<string, list<string>> */
$extraProperties = [
    'ManagesEdgeBuildSettings' => ['public Site $site;', 'public EdgeBuildSettingsForm $buildForm;'],
    'ManagesEdgeDanger' => ['public Site $site;'],
    'ManagesEdgeDeployCommit' => ['public Site $site;'],
    'ManagesEdgeDomains' => ['public Site $site;'],
    'ManagesEdgeLogs' => ['public Site $site;'],
    'ManagesEdgePreviews' => ['public Site $site;'],
    'ManagesEdgeRedeploy' => ['public Site $site;'],
    'MountsEdgeWorkspaceSection' => ['public Site $site;', 'public Server $server;'],
    'PicksRepositoryRef' => ['public Site $site;'],
    'ManagesContainerSite' => ['public Site $site;'],
    'ManagesDeployContract' => ['public Site $site;'],
    'ManagesEdgeDeploymentLifecycle' => ['public Site $site;', 'public ?EdgeDeployment $deployment = null;'],
    'ManagesSiteLogging' => ['public Site $site;'],
    'OptimizesPipeline' => ['public Site $site;'],
    'RequiresFeature' => ['protected string $requiredFeature = \'\';'],
    'RefreshesLinkedSourceControlAccounts' => ['/** @var list<array<string, mixed>> */', 'public array $linkedSourceControlAccounts = [];'],
    'InteractsWithServerWorkspace' => ['public ?Server $server = null;'],
    'RendersWorkspacePlaceholder' => ['public ?Server $server = null;'],
    'GuardsBilledDeploys' => ['public Site $site;'],
    'EnforcesSiteQuota' => ['public Organization $organization;'],
    'MountsSiteWorkspace' => ['public Site $site;', 'public Server $server;'],
    'ManagesEdgeSiteProvisioning' => ['public Site $site;'],
    'ManagesServerlessRuntime' => ['public Site $site;'],
    'ManagesSiteBindings' => ['public Site $site;'],
    'ManagesSiteBindingActions' => ['public Site $site;'],
    'ManagesSiteBindingCredentials' => ['public Site $site;'],
    'ManagesSiteBindingMail' => ['public Site $site;'],
    'ManagesSiteBindingStorage' => ['public Site $site;'],
    'VerifiesSiteBindings' => ['public Site $site;'],
    'WatchesConsoleActionOutcomes' => ['public Site $site;'],
    'StreamsRemoteSshLivewire' => ['public Server $server;'],
    'CreatesNotificationChannelInline' => ['public Organization $organization;'],
    'ConfiguresGitRepository' => ['public Site $site;'],
    'DetectsRepositoryRuntime' => ['public Site $site;'],
    'ManagesProviderCredentials' => ['public Organization $organization;'],
    'ManagesGitProviderTokens' => ['public User $user;'],
    'ManagesServerRemovalForm' => ['public Server $server;'],
    'SurfacesBindingConsumers' => ['public Site $site;'],
    'BuildsCommandPaletteGroups' => [
        'public string $query = \'\';',
        '/** @var list<array{type: string, id: ?string, label: string}> */',
        'public array $stack = [];',
        '/** @var list<string> */',
        'public array $deploySyncSelected = [];',
    ],
    'ManagesCommandPaletteStack' => [
        'public string $query = \'\';',
        '/** @var list<array{type: string, id: ?string, label: string}> */',
        'public array $stack = [];',
        '/** @var list<string> */',
        'public array $deploySyncSelected = [];',
    ],
    'ResolvesCommandPaletteItems' => [
        'public string $query = \'\';',
        '/** @var list<array{type: string, id: ?string, label: string}> */',
        'public array $stack = [];',
    ],
    'RunsCommandPaletteActions' => [
        'public string $query = \'\';',
        '/** @var list<array{type: string, id: ?string, label: string}> */',
        'public array $stack = [];',
        '/** @var list<string> */',
        'public array $deploySyncSelected = [];',
    ],
    'BuildsSiteBindingFormDefaults' => ['public Site $site;'],
    'AuthorsBackupDestinations' => ['public Server $server;'],
    'StagesBackupDownloads' => ['public Server $server;'],
    'InteractsWithUnsavedChangesBar' => ['public bool $hasUnsavedChanges = false;'],
    'EmitsPanelEvent' => ['public string $panelEvent = \'\';'],
    'ManagesEdgeSite' => ['public Site $site;'],
    'ClonesServer' => ['public Server $server;'],
    'ManagesBackupDestinationModal' => ['public Server $server;'],
    'ManagesRedisSnapshots' => ['public Server $server;'],
    'RunsServerMaintenanceActions' => ['public Server $server;'],
    'RunsServerConsoleCommands' => ['public ?Server $server = null;'],
];

/** @var array<string, list<string>> */
$extraMethods = [
    'DismissesConsoleActionRun' => ['protected function consoleActionSubject(): Model { return new Site(); }'],
    'SurfacesErrorStream' => [
        '/** @return Builder<ErrorEvent> */',
        'protected function scopedErrors(): Builder { return ErrorEvent::query(); }',
        'protected function authorizeErrorAccess(): void {}',
    ],
    'ManagesNotificationChannels' => [
        'protected function owner(): User|Organization|Team { return match (random_int(0, 2)) { 0 => new User(), 1 => new Organization(), default => new Team(), }; }',
        '/** @return array<string, mixed> */',
        'protected function notificationChannelsViewData(): array { return []; }',
    ],
    'StagesBackupDownloads' => ['protected function resolveDownloadableBackup(string $type, string $backupId): ?Model { return null; }'],
    'DetectsRepositoryRuntime' => ['protected function applyDetectedRuntimePrefills(): void {}'],
    'InteractsWithServerCreateDraft' => ['protected function stepNumber(): int { return 1; }'],
    'ServerCreateActions' => ['protected function stepNumber(): int { return 1; }'],
    'OptimizesPipeline' => [
        'protected function seedQueuedConsoleAction(string $kind, ?string $label = null): \\App\\Models\\ConsoleAction { return new \\App\\Models\\ConsoleAction(); }',
        'protected function syncEditingPipelineBranches(): void {}',
    ],
    'ManagesSiteBindingActions' => [
        'protected function seedQueuedConsoleAction(string $kind, ?string $label = null): \\App\\Models\\ConsoleAction { return new \\App\\Models\\ConsoleAction(); }',
    ],
    'ManagesSiteBindingMail' => [
        'protected function seedQueuedConsoleAction(string $kind, ?string $label = null): \\App\\Models\\ConsoleAction { return new \\App\\Models\\ConsoleAction(); }',
    ],
    'VerifiesSiteBindings' => [
        'protected function seedQueuedConsoleAction(string $kind, ?string $label = null): \\App\\Models\\ConsoleAction { return new \\App\\Models\\ConsoleAction(); }',
    ],
];

$skipTraits = [
    'RequiresFeature',
];

foreach ($roots as $relativeDir => $namespace) {
    $dir = $projectRoot.'/'.$relativeDir;
    $traits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (str_contains($path, '/PhpStanHarness/') || $file->getFilename() === 'PhpStanTraitHarness.php') {
            continue;
        }

        $contents = file_get_contents($path);
        if ($contents === false || ! preg_match('/^trait\s+(\w+)/m', $contents, $match)) {
            continue;
        }

        $trait = $match[1];
        if (in_array($trait, $skipTraits, true)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($path, strlen($livewireRoot), -4));
        $traits[$trait] = 'App\\Livewire\\'.str_replace('/', '\\', $relative);
    }

    ksort($traits);

    $harnessDir = $dir.'/PhpStanHarness';
    if (is_dir($harnessDir)) {
        foreach (glob($harnessDir.'/*.php') ?: [] as $existing) {
            unlink($existing);
        }
        rmdir($harnessDir);
    }

    $legacy = $dir.'/PhpStanTraitHarness.php';
    if (is_file($legacy)) {
        unlink($legacy);
    }

    if (! is_dir($harnessDir) && ! mkdir($harnessDir, 0755, true) && ! is_dir($harnessDir)) {
        throw new RuntimeException("Could not create harness directory: {$harnessDir}");
    }

    $written = [];
    foreach ($traits as $trait => $fqcn) {
        $short = traitShortName($fqcn);
        $imports = array_values(array_unique(array_merge(
            [$fqcn],
            $extraUses[$trait] ?? [],
        )));
        sort($imports);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            "namespace {$namespace}\\PhpStanHarness;",
            '',
            'use Livewire\\Component;',
            'use App\\Models\\Site;',
            'use App\\Models\\Server;',
            'use App\\Models\\User;',
            'use App\\Models\\Organization;',
            'use App\\Models\\Team;',
            'use App\\Models\\ErrorEvent;',
            'use App\\Models\\EdgeDeployment;',
            'use App\\Livewire\\Forms\\EdgeBuildSettingsForm;',
            'use Illuminate\\Database\\Eloquent\\Model;',
            'use Illuminate\\Database\\Eloquent\\Builder;',
            '',
        ];

        foreach ($imports as $import) {
            $lines[] = "use {$import};";
        }

        $lines[] = '';
        $lines[] = "/** @internal PHPStan harness for {$short} */";
        $lines[] = "final class {$short}Harness extends Component";
        $lines[] = '{';

        foreach ($extraUses[$trait] ?? [] as $useTrait) {
            $lines[] = '    use '.traitShortName($useTrait).';';
        }

        $lines[] = "    use {$short};";

        foreach ($extraProperties[$trait] ?? [] as $property) {
            $lines[] = '    '.$property;
        }

        foreach ($extraMethods[$trait] ?? [] as $method) {
            $lines[] = '    '.$method;
        }

        $declared = implode("\n", array_merge($extraProperties[$trait] ?? [], $extraMethods[$trait] ?? []));
        foreach ([
            'server' => 'public ?Server $server = null;',
            'site' => 'public ?Site $site = null;',
            'organization' => 'public ?Organization $organization = null;',
            'user' => 'public ?User $user = null;',
            'team' => 'public ?Team $team = null;',
            'buildForm' => 'public EdgeBuildSettingsForm $buildForm;',
            'deployment' => 'public ?EdgeDeployment $deployment = null;',
        ] as $name => $property) {
            if (! str_contains($declared, '$'.$name)) {
                $lines[] = '    '.$property;
            }
        }

        $lines[] = '}';
        $lines[] = '';

        $target = $harnessDir.'/'.$short.'Harness.php';
        file_put_contents($target, implode("\n", $lines));
        $written[] = $target;
    }

    foreach (glob($harnessDir.'/*Harness.php') ?: [] as $existing) {
        if (! in_array($existing, $written, true)) {
            unlink($existing);
        }
    }

    echo 'Wrote '.count($written)." harness files under {$harnessDir}\n";
}
