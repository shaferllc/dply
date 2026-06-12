<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Enums\SiteRedirectKind;
use App\Models\SiteRedirect;
use App\Support\SiteRedirectConfigSupport;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteRedirects
{
    public string $new_redirect_from = '';

    public string $new_redirect_to = '';

    /** @var value-of<SiteRedirectKind> */
    public string $new_redirect_kind = 'http';

    public int $new_redirect_code = 301;

    /**
     * Optional HTTP response headers for new redirect rows (HTTP redirects only).
     *
     * @var list<array{name: string, value: string}>
     */
    public array $new_redirect_header_rows = [['name' => '', 'value' => '']];

    public string $new_redirect_comment = '';

    /**
     * Bulk paste for redirects — one rule per line, comma-separated:
     * `from,to[,code]`. Internal rewrites still go through the single-add form.
     */
    public string $bulk_redirect_input = '';

    /** When non-null, the redirects list shows an inline edit form for this row. */
    public ?string $editing_redirect_id = null;

    /** @var value-of<SiteRedirectKind> */
    public string $editing_redirect_kind = 'http';

    public string $editing_redirect_from = '';

    public string $editing_redirect_to = '';

    public int $editing_redirect_code = 301;

    /** @var list<array{name: string, value: string}> */
    public array $editing_redirect_header_rows = [['name' => '', 'value' => '']];

    public string $editing_redirect_comment = '';

    public function addRedirectRule(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_redirect_kind' => ['required', Rule::in(array_column(SiteRedirectKind::cases(), 'value'))],
            'new_redirect_from' => 'required|string|max:512',
            'new_redirect_to' => [
                'required',
                'string',
                'max:1024',
                Rule::when(
                    $this->new_redirect_kind === SiteRedirectKind::InternalRewrite->value,
                    ['regex:/^\/$|^\/[a-zA-Z0-9\/_\-]+$/']
                ),
            ],
            'new_redirect_code' => [
                Rule::requiredIf(fn () => $this->new_redirect_kind === SiteRedirectKind::Http->value),
                'nullable',
                'integer',
                Rule::in(SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes()),
            ],
            'new_redirect_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $responseHeaders = null;
        if ($this->new_redirect_kind === SiteRedirectKind::Http->value) {
            $responseHeaders = $this->validateAndNormalizeRedirectHeaders($this->new_redirect_header_rows, 'new_redirect_header_rows');
        }

        SiteRedirect::query()->create([
            'site_id' => $this->site->id,
            'kind' => SiteRedirectKind::from($this->new_redirect_kind),
            'from_path' => $this->new_redirect_from,
            'to_url' => $this->new_redirect_to,
            'status_code' => $this->new_redirect_kind === SiteRedirectKind::InternalRewrite->value
                ? 301
                : (int) $this->new_redirect_code,
            'response_headers' => $responseHeaders,
            'comment' => trim($this->new_redirect_comment) ?: null,
            'sort_order' => (int) ($this->site->redirects()->max('sort_order') ?? 0) + 1,
        ]);
        $this->new_redirect_from = '';
        $this->new_redirect_to = '';
        $this->new_redirect_kind = SiteRedirectKind::Http->value;
        $this->new_redirect_code = 301;
        $this->new_redirect_header_rows = [['name' => '', 'value' => '']];
        $this->new_redirect_comment = '';
        $this->finalizeRoutingMutation('Redirect added.');
    }

    /**
     * Shared validation + normalization for redirect response headers.
     * Used by both `addRedirectRule()` and `saveEditedRedirect()` so the
     * inline edit form gets the same per-field error UX as the add form.
     *
     * @param  array<int, array{name?: string|null, value?: string|null}>  $rows
     * @return array<string, string>|null Normalized headers, or null if all rows were blank.
     */
    protected function validateAndNormalizeRedirectHeaders(array $rows, string $errorKeyPrefix): ?array
    {
        foreach ($rows as $i => $row) {
            $n = trim((string) ($row['name'] ?? ''));
            $v = trim((string) ($row['value'] ?? ''));
            if ($n === '' && $v === '') {
                continue;
            }
            if ($n === '' || $v === '') {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.name" => [__('Provide both a header name and value, or clear the row.')],
                ]);
            }
            if (! SiteRedirectConfigSupport::isValidHeaderName($n)) {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.name" => [__('Use a valid header name (letters, digits, and !#$&-.^_`|~).')],
                ]);
            }
            if (! SiteRedirectConfigSupport::isValidHeaderValue($v)) {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.value" => [__('Header value is too long or contains invalid characters.')],
                ]);
            }
            if (SiteRedirectConfigSupport::isForbiddenResponseHeaderName($n)) {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.name" => [__('This header cannot be set from a redirect.')],
                ]);
            }
        }
        $normalized = SiteRedirectConfigSupport::normalizeResponseHeaders($rows);

        return $normalized === [] ? null : $normalized;
    }

    public function confirmRemoveRedirect(int|string $redirectId): void
    {
        $this->authorize('update', $this->site);
        // Cast to string so the trait round-trips the value through Livewire
        // serialization without surprises. deleteRedirectRule accepts
        // int|string anyway (whereKey coerces).
        $this->openConfirmActionModal(
            'deleteRedirectRule',
            [(string) $redirectId],
            __('Remove redirect'),
            __('Remove this redirect rule? Linked traffic will stop being redirected after the next webserver apply.'),
            __('Remove redirect'),
            true,
        );
    }

    public function editRedirect(int|string $redirectId): void
    {
        $this->authorize('update', $this->site);
        $redirect = SiteRedirect::query()->where('site_id', $this->site->id)->findOrFail($redirectId);
        $this->editing_redirect_id = (string) $redirect->id;
        $this->editing_redirect_kind = $redirect->kind instanceof SiteRedirectKind
            ? $redirect->kind->value
            : (string) $redirect->kind;
        $this->editing_redirect_from = (string) $redirect->from_path;
        $this->editing_redirect_to = (string) $redirect->to_url;
        $this->editing_redirect_code = (int) $redirect->status_code;
        $headers = is_array($redirect->response_headers) ? $redirect->response_headers : [];
        $rows = [];
        foreach ($headers as $name => $value) {
            $rows[] = ['name' => (string) $name, 'value' => (string) $value];
        }
        if ($rows === []) {
            $rows = [['name' => '', 'value' => '']];
        }
        $this->editing_redirect_header_rows = $rows;
        $this->editing_redirect_comment = (string) ($redirect->comment ?? '');
    }

    public function cancelEditRedirect(): void
    {
        $this->editing_redirect_id = null;
        $this->editing_redirect_kind = SiteRedirectKind::Http->value;
        $this->editing_redirect_from = '';
        $this->editing_redirect_to = '';
        $this->editing_redirect_code = 301;
        $this->editing_redirect_header_rows = [['name' => '', 'value' => '']];
        $this->editing_redirect_comment = '';
    }

    public function addEditingRedirectHeaderRow(): void
    {
        if (count($this->editing_redirect_header_rows) >= 8) {
            return;
        }
        $this->editing_redirect_header_rows[] = ['name' => '', 'value' => ''];
    }

    public function removeEditingRedirectHeaderRow(int $index): void
    {
        unset($this->editing_redirect_header_rows[$index]);
        $this->editing_redirect_header_rows = array_values($this->editing_redirect_header_rows);
        if ($this->editing_redirect_header_rows === []) {
            $this->editing_redirect_header_rows = [['name' => '', 'value' => '']];
        }
    }

    public function saveEditedRedirect(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_redirect_id === null) {
            return;
        }
        $redirect = SiteRedirect::query()->where('site_id', $this->site->id)->findOrFail($this->editing_redirect_id);
        $this->validate([
            'editing_redirect_kind' => ['required', Rule::in(array_column(SiteRedirectKind::cases(), 'value'))],
            'editing_redirect_from' => 'required|string|max:512',
            'editing_redirect_to' => [
                'required',
                'string',
                'max:1024',
                Rule::when(
                    $this->editing_redirect_kind === SiteRedirectKind::InternalRewrite->value,
                    ['regex:/^\/$|^\/[a-zA-Z0-9\/_\-]+$/']
                ),
            ],
            'editing_redirect_code' => [
                Rule::requiredIf(fn () => $this->editing_redirect_kind === SiteRedirectKind::Http->value),
                'nullable',
                'integer',
                Rule::in(SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes()),
            ],
            'editing_redirect_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $responseHeaders = null;
        if ($this->editing_redirect_kind === SiteRedirectKind::Http->value) {
            $responseHeaders = $this->validateAndNormalizeRedirectHeaders($this->editing_redirect_header_rows, 'editing_redirect_header_rows');
        }

        $redirect->forceFill([
            'kind' => SiteRedirectKind::from($this->editing_redirect_kind),
            'from_path' => $this->editing_redirect_from,
            'to_url' => $this->editing_redirect_to,
            'status_code' => $this->editing_redirect_kind === SiteRedirectKind::InternalRewrite->value
                ? 301
                : (int) $this->editing_redirect_code,
            'response_headers' => $responseHeaders,
            'comment' => trim($this->editing_redirect_comment) ?: null,
        ])->save();

        $this->cancelEditRedirect();
        $this->finalizeRoutingMutation('Redirect updated.');
    }

    /**
     * Bulk paste — `from,to[,code]` per line. Internal rewrites still go
     * through the single-add form (kind selector lives there). Code
     * defaults to 301; values must match allowed status codes.
     */
    public function bulkImportRedirects(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_redirect_input' => 'required|string|max:65535']);

        $allowedCodes = SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes();
        $lines = preg_split('/\r\n|\r|\n/', trim($this->bulk_redirect_input)) ?: [];
        $parsed = [];
        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 2) {
                $this->addError('bulk_redirect_input', sprintf('Line %d: expected `from,to[,code]`.', $i + 1));

                return;
            }
            [$from, $to] = $parts;
            $code = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : 301;
            if ($from === '' || $to === '') {
                $this->addError('bulk_redirect_input', sprintf('Line %d: from/to may not be blank.', $i + 1));

                return;
            }
            if (! in_array($code, $allowedCodes, true)) {
                $this->addError('bulk_redirect_input', sprintf('Line %d: status code %d is not allowed.', $i + 1, $code));

                return;
            }
            $parsed[] = ['from' => $from, 'to' => $to, 'code' => $code];
        }

        $sortBase = (int) ($this->site->redirects()->max('sort_order') ?? 0);
        foreach ($parsed as $row) {
            SiteRedirect::query()->create([
                'site_id' => $this->site->id,
                'kind' => SiteRedirectKind::Http,
                'from_path' => $row['from'],
                'to_url' => $row['to'],
                'status_code' => $row['code'],
                'response_headers' => null,
                'sort_order' => ++$sortBase,
            ]);
        }

        $this->bulk_redirect_input = '';
        $this->finalizeRoutingMutation(__(':count redirect(s) imported.', ['count' => count($parsed)]));
    }

    public function addNewRedirectHeaderRow(): void
    {
        if (count($this->new_redirect_header_rows) >= 8) {
            return;
        }
        $this->new_redirect_header_rows[] = ['name' => '', 'value' => ''];
    }

    public function removeNewRedirectHeaderRow(int $index): void
    {
        unset($this->new_redirect_header_rows[$index]);
        $this->new_redirect_header_rows = array_values($this->new_redirect_header_rows);
        if ($this->new_redirect_header_rows === []) {
            $this->new_redirect_header_rows = [['name' => '', 'value' => '']];
        }
    }

    public function deleteRedirectRule(int|string $id): void
    {
        $this->authorize('update', $this->site);
        SiteRedirect::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->finalizeRoutingMutation('Redirect removed.');
    }
}
