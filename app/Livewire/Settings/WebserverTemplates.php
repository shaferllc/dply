<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\WebserverTemplate;
use App\Services\Webserver\WebserverConfigValidator;
use App\Services\Webserver\WebserverTemplateRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WebserverTemplates extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public Organization $organization;

    public string $label = '';

    /** Engine slug from {@see WebserverTemplate::ENGINES}. */
    public string $engine = 'nginx';

    /** Body that goes inside the server block (legacy `content` column). */
    public string $content = '';

    /** Optional content rendered ABOVE the server block (upstreams, maps, limit zones). */
    public string $content_before = '';

    /** Optional content rendered AFTER the server block (HTTP→HTTPS redirects, sibling servers). */
    public string $content_after = '';

    public ?int $editingId = null;

    public ?string $testMessage = null;

    public bool $testOk = false;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
        $this->content = $this->defaultTemplateStub();
    }

    /**
     * Stub used as the "in server block" body for new templates. The user
     * picks an engine separately; the stub itself shows the canonical nginx
     * shape (the most common engine) — switching engines doesn't rewrite
     * the body, just changes how the renderer assembles the test config.
     */
    public function defaultTemplateStub(): string
    {
        return <<<'NGINX'
# Dply webserver template — do not remove
server {
    listen 80;
    listen [::]:80;
    server_name {DOMAIN};
    root /home/{SYSTEM_USER}/{DOMAIN}{DIRECTORY};
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    public function startEdit(int $id): void
    {
        $this->authorize('update', $this->organization);

        $template = WebserverTemplate::query()
            ->where('organization_id', $this->organization->id)
            ->findOrFail($id);

        $this->editingId = $template->id;
        $this->label = $template->label;
        $this->engine = $template->engine ?: 'nginx';
        $this->content = (string) $template->content;
        $this->content_before = (string) ($template->content_before ?? '');
        $this->content_after = (string) ($template->content_after ?? '');
        $this->testMessage = null;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->label = '';
        $this->engine = 'nginx';
        $this->content = $this->defaultTemplateStub();
        $this->content_before = '';
        $this->content_after = '';
        $this->testMessage = null;
    }

    public function save(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'engine' => ['required', 'string', 'in:'.implode(',', array_keys(WebserverTemplate::ENGINES))],
            'content' => ['required', 'string', 'max:500000'],
            'content_before' => ['nullable', 'string', 'max:500000'],
            'content_after' => ['nullable', 'string', 'max:500000'],
        ]);

        // The banner check only applies to nginx — Apache uses `# `
        // comments fine, and other engines have their own comment syntax
        // entirely. Skipping for non-nginx keeps the gate from blocking
        // legitimate Apache/Caddy/etc. templates.
        if ($this->engine === 'nginx') {
            $banner = (string) config('webserver_templates.required_banner_line', '');
            if ($banner !== '' && ! str_contains($this->content, $banner)) {
                $this->addError('content', __('The template must include: :line', ['line' => $banner]));

                return;
            }
        }

        $user = Auth::user();
        $payload = [
            'label' => $this->label,
            'engine' => $this->engine,
            'content' => $this->content,
            'content_before' => $this->content_before !== '' ? $this->content_before : null,
            'content_after' => $this->content_after !== '' ? $this->content_after : null,
            'user_id' => $user?->id,
        ];

        if ($this->editingId) {
            $template = WebserverTemplate::query()
                ->where('organization_id', $this->organization->id)
                ->findOrFail($this->editingId);
            $before = ['label' => $template->label, 'engine' => $template->engine];
            $template->update($payload);
            audit_log($this->organization, $user, 'webserver_template.updated', $template, $before, [
                'label' => $template->label,
                'engine' => $template->engine,
            ]);
            $this->toastSuccess(__('Template updated.'));
        } else {
            $template = $this->organization->webserverTemplates()->create($payload);
            audit_log($this->organization, $user, 'webserver_template.created', $template, null, [
                'label' => $template->label,
                'engine' => $template->engine,
            ]);
            $this->toastSuccess(__('Template created.'));
        }

        $this->cancelEdit();
    }

    public function delete(int|string $id): void
    {
        $this->authorize('update', $this->organization);

        $template = WebserverTemplate::query()
            ->where('organization_id', $this->organization->id)
            ->findOrFail($id);

        $template->delete();

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }

        $this->toastSuccess(__('Template deleted.'));
    }

    public function testDraft(WebserverTemplateRenderer $renderer, WebserverConfigValidator $validator): void
    {
        $this->authorize('view', $this->organization);

        $assembled = $this->assembleForRendering($this->content_before, $this->content, $this->content_after);
        $substituted = $renderer->substituteForTest($assembled)['content'];
        $result = $validator->validate($this->engine, $substituted);

        $this->testOk = $result['ok'];
        $this->testMessage = $result['message'];
    }

    public function testSaved(int $id, WebserverTemplateRenderer $renderer, WebserverConfigValidator $validator): void
    {
        $this->authorize('view', $this->organization);

        $template = WebserverTemplate::query()
            ->where('organization_id', $this->organization->id)
            ->findOrFail($id);

        $assembled = $this->assembleForRendering(
            (string) ($template->content_before ?? ''),
            (string) $template->content,
            (string) ($template->content_after ?? ''),
        );
        $substituted = $renderer->substituteForTest($assembled)['content'];
        $result = $validator->validate($template->engine ?: 'nginx', $substituted);

        $this->testOk = $result['ok'];
        $this->testMessage = $result['message'];
    }

    /**
     * Sandwich the three body sections together with blank-line separators
     * so the resulting blob reads like a real config file. Empty sections
     * drop out cleanly so the test output isn't polluted with blank gaps.
     */
    private function assembleForRendering(string $before, string $inside, string $after): string
    {
        return collect([$before, $inside, $after])
            ->map(fn (string $chunk) => trim($chunk))
            ->filter(fn (string $chunk) => $chunk !== '')
            ->implode("\n\n");
    }

    public function render(): View
    {
        $templates = $this->organization->webserverTemplates()->latest()->get();
        $canManage = $this->organization->hasAdminAccess(Auth::user());

        return view('livewire.settings.webserver-templates', [
            'templates' => $templates,
            'canManage' => $canManage,
            'placeholders' => config('webserver_templates.placeholders', []),
            'orgShellSection' => 'webserver',
            'engines' => WebserverTemplate::ENGINES,
        ]);
    }
}
