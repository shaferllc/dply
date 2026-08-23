<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Collection;

/**
 * In-memory paging for the settings list pages (API keys, SSH keys, CLI
 * sessions, webserver templates, notification channels).
 *
 * These lists are already fetched whole — they are small, searched client-side
 * or with one LIKE, and the page needs the unfiltered count for its header — so
 * this slices a Collection rather than reaching for a database paginator. The
 * clamp matters: deleting the last row of the last page must not strand the
 * reader on an empty one.
 */
trait PaginatesSettingsLists
{
    /**
     * @param  Collection<int, mixed>  $rows
     * @param  string  $pageProperty  public int property on the component holding the current page
     * @return array{rows: Collection<int, mixed>, page: int, pages: int, total: int, perPage: int}
     */
    protected function paginateSettingsList(Collection $rows, string $pageProperty, int $perPage = 10): array
    {
        $total = $rows->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $this->{$pageProperty}), $pages);

        $this->{$pageProperty} = $page;

        return [
            'rows' => $rows->forPage($page, $perPage)->values(),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'perPage' => $perPage,
        ];
    }
}
