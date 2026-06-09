@props([
    'server',
    'site',
    'currentLabel',
    'currentIcon' => null,
    'contextualDocSlug' => null,
])

<x-breadcrumb-trail
    :items="\App\Support\Sites\SiteWorkspaceBreadcrumbs::items($server, $site, $currentLabel, $currentIcon)"
    :site="$site"
    doc-contextual
    :contextual-doc-slug="$contextualDocSlug"
/>
