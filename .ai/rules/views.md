---
paths:
  - 'resources/views/**'
---

# Views

## Blade directives don't compile inside <x-component> attributes — use {!! Js::from() !!}
The component tag compiler runs BEFORE directive compilation and freezes every attribute into a PHP string literal. So this ships the literal text `@js($queue)` to the browser:

    <x-secondary-button wire:click="pauseQueue(@js($queueName))">   {{-- BROKEN --}}

Alpine then throws "Invalid or unexpected token" and the button silently does nothing — no toast, no network request, no server-side clue. This shipped 20 dead controls across the queue, insights, worker-fleet and resources views.

Inside a component attribute use a raw echo, which DOES compile there and emits exactly what `@js()` would (`Js::__toString()` === `toHtml()`):

    <x-secondary-button wire:click="pauseQueue({!! \Illuminate\Support\Js::from($queueName) !!})">

`@js()` is still correct on a plain `<button>`/`<span>`/`<div>`. `{{ $x }}` also compiles inside a component attribute but only `e()`-escapes, so a value containing a quote still breaks out — prefer `Js::from`.

Guarded by tests/Feature/BladeDirectivesCompileInComponentTagsTest.php, which compiles every view and fails on a surviving `@js(`.
