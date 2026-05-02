<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;

/**
 * Shared draft IO for the four create-server wizard step components.
 *
 * Drafts are scoped to (user, organization), one row per pair (Q5). The "step" column is a
 * high-water mark — direct-URL access to a step beyond it redirects back to the actual
 * current step (Q8). Back-navigation is always allowed.
 */
trait InteractsWithServerCreateDraft
{
    /**
     * Discard-draft modal state — surfaced on every step's footer link.
     */
    public bool $showDiscardDraftModal = false;

    /**
     * Step number this component represents. Each step component sets this constant.
     */
    abstract protected function stepNumber(): int;

    public function openDiscardDraftModal(): void
    {
        $this->showDiscardDraftModal = true;
    }

    public function closeDiscardDraftModal(): void
    {
        $this->showDiscardDraftModal = false;
    }

    public function confirmDiscardDraft(): mixed
    {
        $this->deleteCurrentDraft();
        $this->showDiscardDraftModal = false;

        return $this->redirect(route('servers.index'), navigate: true);
    }

    protected function currentUser(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }

    protected function currentOrganization(): ?Organization
    {
        return $this->currentUser()?->currentOrganization();
    }

    /**
     * Find the draft for the current (user, organization) pair, or null if none exists.
     */
    protected function currentDraft(): ?ServerCreateDraft
    {
        return ServerCreateDraft::forCurrentScope($this->currentUser(), $this->currentOrganization());
    }

    /**
     * Apply the strict navigation gate (Q8). If a draft is missing or its high-water
     * `step` is below this component's step number, redirect to wherever the user
     * actually is. Returns a redirect response when redirection is needed; the caller
     * (typically Livewire mount()) must return it to short-circuit rendering.
     */
    protected function enforceDraftGate(): RedirectResponse|Redirector|null
    {
        $draft = $this->currentDraft();
        $step = $this->stepNumber();

        if ($draft === null) {
            // No draft yet — only step 1 is reachable; any other step bounces to step 1.
            return $step === 1 ? null : redirect()->route('servers.create');
        }

        if ($draft->step < $step) {
            return redirect()->route(self::routeNameForStep($draft->step));
        }

        return null;
    }

    /**
     * Hydrate the local Livewire form object from the draft payload.
     */
    protected function hydrateFormFromDraft(ServerCreateForm $form, ?ServerCreateDraft $draft): void
    {
        if ($draft === null) {
            return;
        }

        foreach ($draft->payload as $field => $value) {
            if (property_exists($form, $field)) {
                $form->{$field} = $value;
            }
        }
    }

    /**
     * Persist the form's current public properties into the draft, refreshing TTL.
     * Creates the draft on first save (step 1).
     */
    protected function saveDraftFromForm(ServerCreateForm $form, ?int $advanceTo = null): ServerCreateDraft
    {
        $user = $this->currentUser();
        $org = $this->currentOrganization();

        if ($user === null || $org === null) {
            abort(403);
        }

        $payload = $this->extractFormPayload($form);

        $draft = ServerCreateDraft::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'organization_id' => $org->getKey(),
        ]);

        $existing = is_array($draft->payload ?? null) ? $draft->payload : [];
        $draft->payload = array_replace($existing, $payload);

        if ($advanceTo !== null && $advanceTo > (int) ($draft->step ?? 1)) {
            $draft->step = $advanceTo;
        } elseif ((int) ($draft->step ?? 0) < 1) {
            $draft->step = 1;
        }

        $draft->bumpExpiry();
        $draft->save();

        return $draft;
    }

    /**
     * Delete the current draft (Discard action — Q12). No-op when none exists.
     */
    protected function deleteCurrentDraft(): void
    {
        $this->currentDraft()?->delete();
    }

    /**
     * Map a step number (1..4) to its route name. Used by the gate redirect and the stepper.
     */
    public static function routeNameForStep(int $step): string
    {
        return match (max(1, min(ServerCreateDraft::TOTAL_STEPS, $step))) {
            1 => 'servers.create',
            2 => 'servers.create.where',
            3 => 'servers.create.what',
            4 => 'servers.create.review',
        };
    }

    /**
     * Extract only the form's public (non-readonly) scalar/bool properties.
     * Avoids accidentally serialising injected services or computed properties.
     *
     * @return array<string, mixed>
     */
    protected function extractFormPayload(ServerCreateForm $form): array
    {
        $reflection = new \ReflectionObject($form);
        $payload = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $payload[$prop->getName()] = $prop->getValue($form);
        }

        return $payload;
    }
}
