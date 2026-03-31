<?php

namespace App\Livewire\Forms;

use App\Models\ApiToken;
use App\Models\ServerFirewallRule;
use Illuminate\Validation\Rule;
use Livewire\Form;

class FirewallRuleForm extends Form
{
    public ?string $name = null;

    /** Defaults match config/server_firewall.php until mount/reset applies env overrides. */
    public ?int $port = 443;

    public string $protocol = 'tcp';

    /** `any` or a single IP / CIDR */
    public string $source = 'any';

    public string $action = 'allow';

    public bool $enabled = true;

    /** Optional: web, db, admin, internal, other, or custom (max 32). */
    public ?string $profile = null;

    /** Comma-separated tags for filters and exports. */
    public ?string $tags = null;

    public ?string $runbook_url = null;

    public ?string $site_id = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'port' => [
                Rule::requiredIf(fn () => in_array($this->protocol, ['tcp', 'udp'], true)),
                'nullable',
                'integer',
                'min:1',
                'max:65535',
            ],
            'protocol' => ['required', 'in:tcp,udp,icmp,ipv6-icmp'],
            'source' => [
                'required',
                'string',
                'max:128',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! self::sourceIsValid((string) $value)) {
                        $fail(__('Use :keyword, a valid IP, or a CIDR range (IPv4 or IPv6).', ['keyword' => 'any']));
                    }
                },
            ],
            'action' => ['required', 'in:allow,deny'],
            'enabled' => ['boolean'],
            'profile' => ['nullable', 'string', 'max:32'],
            'tags' => ['nullable', 'string', 'max:500'],
            'runbook_url' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
                        $fail(__('Enter a valid URL or leave blank.'));
                    }
                },
            ],
            'site_id' => ['nullable', 'string', 'ulid'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function tagsStringToArray(?string $tags): array
    {
        if ($tags === null || trim($tags) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $tags)))));
    }

    public static function sourceIsValid(string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return false;
        }
        if (strtolower($v) === 'any') {
            return true;
        }
        if (ApiToken::ipOrCidrIsValid($v)) {
            return true;
        }
        if (str_contains($v, '/')) {
            [$addr, $mask] = explode('/', $v, 2);
            $addr = trim($addr);
            if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return ctype_digit($mask) && (int) $mask >= 0 && (int) $mask <= 128;
            }
        }

        return false;
    }

    public function setForEdit(ServerFirewallRule $rule): void
    {
        $this->name = $rule->name;
        $this->port = $rule->port;
        $this->protocol = $rule->protocol;
        $this->source = (string) $rule->source;
        $this->action = $rule->action;
        $this->enabled = (bool) $rule->enabled;
        $this->profile = $rule->profile;
        $tagList = $rule->tags;
        $this->tags = is_array($tagList) && $tagList !== [] ? implode(', ', $tagList) : null;
        $this->runbook_url = $rule->runbook_url;
        $this->site_id = $rule->site_id;
    }

    public function resetForNew(): void
    {
        $this->reset([
            'name', 'port', 'protocol', 'source', 'action', 'enabled',
            'profile', 'tags', 'runbook_url', 'site_id',
        ]);
        $this->applyNewRuleDefaults();
    }

    /**
     * Apply configured defaults for a brand-new rule (see config/server_firewall.php).
     */
    public function applyNewRuleDefaults(): void
    {
        $d = config('server_firewall.new_rule', []);
        $this->name = null;
        $this->port = (int) ($d['port'] ?? 443);
        $proto = $d['protocol'] ?? 'tcp';
        $this->protocol = in_array($proto, ['tcp', 'udp', 'icmp', 'ipv6-icmp'], true)
            ? $proto
            : 'tcp';
        if (in_array($this->protocol, ['icmp', 'ipv6-icmp'], true)) {
            $this->port = null;
        }
        $this->source = is_string($d['source'] ?? null) && $d['source'] !== ''
            ? $d['source']
            : 'any';
        $this->action = in_array($d['action'] ?? 'allow', ['allow', 'deny'], true)
            ? $d['action']
            : 'allow';
        $this->enabled = (bool) ($d['enabled'] ?? true);
    }

    /**
     * Fill the form from a named preset (quick-add shortcuts).
     */
    public function applyPreset(string $key): void
    {
        $presets = config('server_firewall.presets', []);
        if (! isset($presets[$key]) || ! is_array($presets[$key])) {
            return;
        }
        $p = $presets[$key];
        $this->name = isset($p['name']) ? (string) $p['name'] : null;
        $proto = (string) ($p['protocol'] ?? 'tcp');
        $this->protocol = in_array($proto, ['tcp', 'udp', 'icmp', 'ipv6-icmp'], true) ? $proto : 'tcp';
        if (in_array($this->protocol, ['icmp', 'ipv6-icmp'], true)) {
            $this->port = null;
        } else {
            $this->port = (int) ($p['port'] ?? config('server_firewall.new_rule.port', 443));
        }
        $this->source = is_string($p['source'] ?? null) && $p['source'] !== ''
            ? $p['source']
            : 'any';
        $this->action = in_array($p['action'] ?? 'allow', ['allow', 'deny'], true)
            ? $p['action']
            : 'allow';
        $this->enabled = (bool) ($p['enabled'] ?? true);
    }
}
