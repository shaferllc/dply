<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Third-party tag / script manager (customer-facing name; Zaraz-class capability).
 */
class Tags extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public bool $consent_required = false;

    /** @var list<array{name: string, src: string, async: bool}> */
    public array $tools = [];

    /**
     * Starter script URLs operators can one-click add. Placeholders in the URL
     * (G-XXXXXXXX, etc.) must be replaced before Save — Tags only injects
     * `<script src>` loaders, not vendor config snippets.
     *
     * @return list<array{key: string, name: string, label: string, src: string, hint: string}>
     */
    public static function exampleCatalog(): array
    {
        return [
            [
                'key' => 'ga4',
                'name' => 'Google Analytics',
                'label' => 'GA4',
                'src' => 'https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX',
                'hint' => __('Replace G-XXXXXXXXXX with your Measurement ID.'),
            ],
            [
                'key' => 'gtm',
                'name' => 'Google Tag Manager',
                'label' => 'GTM',
                'src' => 'https://www.googletagmanager.com/gtm.js?id=GTM-XXXXXXX',
                'hint' => __('Replace GTM-XXXXXXX with your container ID.'),
            ],
            [
                'key' => 'meta',
                'name' => 'Meta Pixel',
                'label' => 'Meta',
                'src' => 'https://connect.facebook.net/en_US/fbevents.js',
                'hint' => __('Loader only — set your Pixel ID via Snippets or your CMP if needed.'),
            ],
            [
                'key' => 'clarity',
                'name' => 'Microsoft Clarity',
                'label' => 'Clarity',
                'src' => 'https://www.clarity.ms/tag/XXXXXXXXXX',
                'hint' => __('Replace XXXXXXXXXX with your Clarity project ID.'),
            ],
            [
                'key' => 'hotjar',
                'name' => 'Hotjar',
                'label' => 'Hotjar',
                'src' => 'https://static.hotjar.com/c/hotjar-XXXXXXX.js?sv=6',
                'hint' => __('Replace XXXXXXX with your Hotjar site ID.'),
            ],
            [
                'key' => 'plausible',
                'name' => 'Plausible',
                'label' => 'Plausible',
                'src' => 'https://plausible.io/js/script.js',
                'hint' => __('Add data-domain via Snippets if your Plausible setup requires it.'),
            ],
        ];
    }

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['tags'] ?? null) ? $site->edgeMeta()['tags'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->consent_required = (bool) ($cfg['consent_required'] ?? false);
        $tools = is_array($cfg['tools'] ?? null) ? $cfg['tools'] : [];
        $this->tools = $tools !== [] ? array_values(array_map(fn ($t) => [
            'name' => (string) ($t['name'] ?? 'tag'),
            'src' => (string) ($t['src'] ?? ''),
            'async' => (bool) ($t['async'] ?? true),
        ], $tools)) : [[
            'name' => 'analytics',
            'src' => '',
            'async' => true,
        ]];
    }

    public function addTool(): void
    {
        $this->tools[] = ['name' => 'tag', 'src' => '', 'async' => true];
    }

    public function addExample(string $key): void
    {
        $example = collect(self::exampleCatalog())->firstWhere('key', $key);
        if (! is_array($example)) {
            return;
        }

        $row = [
            'name' => (string) $example['name'],
            'src' => (string) $example['src'],
            'async' => true,
        ];

        // Replace the empty default placeholder row so the first click doesn't
        // leave a useless blank "analytics" entry behind.
        $onlyBlankPlaceholder = count($this->tools) === 1
            && trim((string) $this->tools[0]['src']) === ''
            && in_array(trim((string) $this->tools[0]['name']), ['', 'analytics', 'tag'], true);

        if ($onlyBlankPlaceholder) {
            $this->tools = [$row];
        } else {
            $this->tools[] = $row;
        }

        $this->enabled = true;
    }

    public function removeTool(int $index): void
    {
        unset($this->tools[$index]);
        $this->tools = array_values($this->tools);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Tags require Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'tools.*.name' => ['required', 'string', 'max:64'],
            'tools.*.src' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
        ]);

        // Consent helper needs the tag manager on — otherwise KV never receives
        // a tags block and window.__dplyTags never appears on the live site.
        if ($this->consent_required) {
            $this->enabled = true;
        }

        $this->site->mergeEdgeMeta([
            'tags' => [
                'enabled' => $this->enabled,
                'consent_required' => $this->consent_required,
                'tools' => array_values(array_filter(
                    $this->tools,
                    static fn (array $t): bool => trim((string) $t['src']) !== '',
                )),
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Tags saved.'));
    }

    public function render(): View
    {
        $repo = $this->edgeRepoConfigSection('tags');

        return view('livewire.sites.edge.workspace.tags', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-tags'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'examples' => self::exampleCatalog(),
                'sourcePath' => $repo['source_path'],
                'repoTags' => $repo['section'],
            ],
        ));
    }
}
