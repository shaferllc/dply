<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\WebserverTemplate;
use App\Services\Webserver\NginxConfigSyntaxTester;
use App\Services\Webserver\WebserverTemplateRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WebserverTemplates extends Component
{
    use ConfirmsActionWithModal;

    public Organization $organization;

    public string $label = '';

    public string $content = '';

    public ?int $editingId = null;

    public ?string $testMessage = null;

    public bool $testOk = false;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
        $this->content = $this->defaultTemplateStub();
    }

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
        $this->content = $template->content;
        $this->testMessage = null;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->label = '';
        $this->content = $this->defaultTemplateStub();
        $this->testMessage = null;
    }

    public function save(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:500000'],
        ]);

        $banner = (string) config('webserver_templates.required_banner_line', '');
        if ($banner !== '' && ! str_contains($this->content, $banner)) {
            $this->addError('content', __('The template must include: :line', ['line' => $banner]));

            return;
        }

        $user = Auth::user();
        $payload = [
            'label' => $this->label,
            'content' => $this->content,
            'user_id' => $user?->id,
        ];

        if ($this->editingId) {
            $template = WebserverTemplate::query()
                ->where('organization_id', $this->organization->id)
                ->findOrFail($this->editingId);
            $template->update($payload);
            session()->flash('success', __('Template updated.'));
        } else {
            $this->organization->webserverTemplates()->create($payload);
            session()->flash('success', __('Template created.'));
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

        session()->flash('success', __('Template deleted.'));
    }

    public function testDraft(WebserverTemplateRenderer $renderer, NginxConfigSyntaxTester $tester): void
    {
        $this->authorize('view', $this->organization);

        $substituted = $renderer->substituteForTest($this->content)['content'];
        $result = $tester->testServerBlock($substituted);

        $this->testOk = $result['ok'];
        $this->testMessage = $result['message'];
    }

    public function testSaved(int $id, WebserverTemplateRenderer $renderer, NginxConfigSyntaxTester $tester): void
    {
        $this->authorize('view', $this->organization);

        $template = WebserverTemplate::query()
            ->where('organization_id', $this->organization->id)
            ->findOrFail($id);

        $substituted = $renderer->substituteForTest($template->content)['content'];
        $result = $tester->testServerBlock($substituted);

        $this->testOk = $result['ok'];
        $this->testMessage = $result['message'];
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
        ]);
    }
}
