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

    /**
     * UFW application-profile name (e.g. "OpenSSH", "Nginx Full"). When set, the apply pipeline
     * emits `ufw allow <app_profile>` and the port/protocol fields are ignored. Profiles live in
     * /etc/ufw/applications.d on the host; Dply doesn't manage them — operators pick from what's
     * already there.
     */
    public ?string $app_profile = null;

    /**
     * Network-interface scoping. When `$iface` is set we emit `ufw <verb> <iface_direction> on
     * <iface> ...rest`, e.g. `allow in on eth0 to any port 80/tcp`. Direction defaults to "in"
     * (the common case — restricting WHICH interface accepts traffic). "out" is for outbound
     * filtering on a specific egress interface.
     */
    public ?string $iface = null;

    public ?string $iface_direction = null;

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
                // Port is only required when there's no app profile AND the rule is on tcp/udp.
                // App-profile rules omit port (UFW picks it up from the profile definition).
                Rule::requiredIf(fn () => in_array($this->protocol, ['tcp', 'udp'], true) && trim((string) $this->app_profile) === ''),
                'nullable',
                'integer',
                'min:1',
                'max:65535',
            ],
            'protocol' => ['required', 'in:tcp,udp,icmp,ipv6-icmp'],
            'app_profile' => [
                'nullable',
                'string',
                'max:64',
                // UFW application names are typically alnum + space + dot + dash + underscore.
                'regex:/^[A-Za-z0-9 ._-]+$/',
            ],
            'iface' => [
                'nullable',
                'string',
                'max:32',
                // Linux interface naming: letters, digits, dot, colon (alias), at-sign (vlan).
                'regex:/^[A-Za-z0-9._:@-]+$/',
            ],
            'iface_direction' => [
                // Closure rules run only when the field has a value, so we use required_with
                // to fire the "pick a direction" message when iface is set but direction isn't.
                'required_with:iface',
                'nullable',
                Rule::in(['in', 'out']),
            ],
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
            'action' => [
                'required',
                'in:allow,deny,limit',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === 'limit' && $this->protocol !== 'tcp') {
                        $fail(__('UFW only supports the limit action on TCP rules.'));
                    }
                },
            ],
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
        $this->app_profile = $rule->app_profile;
        $this->iface = $rule->iface;
        $this->iface_direction = $rule->iface_direction;
        $tagList = $rule->tags;
        $this->tags = is_array($tagList) && $tagList !== [] ? implode(', ', $tagList) : null;
        $this->runbook_url = $rule->runbook_url;
        $this->site_id = $rule->site_id;
    }

    public function resetForNew(): void
    {
        $this->reset([
            'name', 'port', 'protocol', 'source', 'action', 'enabled',
            'profile', 'app_profile', 'iface', 'iface_direction',
            'tags', 'runbook_url', 'site_id',
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
        $this->action = in_array($d['action'] ?? 'allow', ['allow', 'deny', 'limit'], true)
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
        $this->action = in_array($p['action'] ?? 'allow', ['allow', 'deny', 'limit'], true)
            ? $p['action']
            : 'allow';
        $this->enabled = (bool) ($p['enabled'] ?? true);
    }
}
