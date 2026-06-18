<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\FunctionAction;
use App\Models\Site;
use InvalidArgumentException;

/**
 * Defines OpenWhisk sequence actions — codeless compositions that chain the
 * output of one action into the next.
 *
 * A sequence is a {@see FunctionAction} with `kind=sequence`: it has no
 * runtime and no code, only an ordered `components` list referencing other
 * actions. Components must be code actions in the same OpenWhisk namespace
 * (the same host server) as the sequence — OpenWhisk resolves components
 * namespace-wide, so they may come from any package-Site on the host.
 *
 * v1 chains code actions only; nesting a sequence inside a sequence is
 * deferred (it needs cycle detection).
 */
class ServerlessSequenceBuilder
{
    /**
     * Create or update a sequence action on a Site.
     *
     * @param  array<string, mixed> $componentActionIds  ordered FunctionAction ids
     */
    public function define(Site $site, string $name, array $componentActionIds): FunctionAction
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A sequence needs a name.');
        }

        if (count($componentActionIds) < 2) {
            throw new InvalidArgumentException('A sequence must chain at least two actions.');
        }

        $components = $this->resolveComponents($site, $componentActionIds);

        return FunctionAction::query()->updateOrCreate(
            ['site_id' => $site->id, 'name' => $name],
            [
                'kind' => FunctionAction::KIND_SEQUENCE,
                'runtime' => '',
                'entrypoint' => '',
                'components' => $components,
            ],
        );
    }

    /**
     * Resolve and validate the ordered component actions.
     *
     * @param  array<string, mixed> $componentActionIds
     * @return list<array{id: string, name: string}>
     */
    private function resolveComponents(Site $site, array $componentActionIds): array
    {
        $found = FunctionAction::query()
            ->with('site')
            ->whereIn('id', $componentActionIds)
            ->get()
            ->keyBy('id');

        $components = [];
        foreach ($componentActionIds as $id) {
            $action = $found->get($id);

            if ($action === null) {
                throw new InvalidArgumentException('Sequence component not found: '.$id);
            }
            if ($action->kind !== FunctionAction::KIND_CODE) {
                throw new InvalidArgumentException('A sequence can only chain code actions — "'.$action->name.'" is not one.');
            }
            if ($action->site?->server_id !== $site->server_id) {
                throw new InvalidArgumentException('Sequence component "'.$action->name.'" is on a different namespace.');
            }

            $components[] = ['id' => $action->id, 'name' => $action->name];
        }

        return $components;
    }
}
