@php
    $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    $pathHint = '/' . ltrim(request()->path(), '/');
@endphp

<div
    class="not-found-experience"
    x-data="{
        caught: 0,
        needed: 3,
        blipX: 62,
        blipY: 38,
        tracing: false,
        restored: false,
        logs: [],
        quip: @js(__('Click the ping three times to restore the route.')),
        quips: @js([
            __('Signal drifting… catch the ping!'),
            __('Almost there — one more ping.'),
            __('Route restored. Nice reflexes.'),
        ]),
        traceSteps: @js([
            __('Resolving host…'),
            __('TCP handshake…'),
            __('TLS negotiated…'),
            __('GET :path'),
            __('Response: 404 Not Found'),
        ]),
        pathHint: @js($pathHint),
        moveBlip() {
            this.blipX = 12 + Math.random() * 76;
            this.blipY = 12 + Math.random() * 76;
        },
        catchBlip() {
            if (this.restored) return;
            this.caught = Math.min(this.needed, this.caught + 1);
            this.quip = this.quips[Math.min(this.caught - 1, this.quips.length - 1)] ?? this.quips[0];
            if (this.caught >= this.needed) {
                this.restored = true;
                this.quip = this.quips[2];
                return;
            }
            this.moveBlip();
        },
        async traceRoute() {
            if (this.tracing) return;
            this.tracing = true;
            this.logs = [];
            for (const step of this.traceSteps) {
                const line = step.replace(':path', this.pathHint);
                this.logs.push(line);
                await new Promise((r) => setTimeout(r, 420));
            }
            this.tracing = false;
        },
    }"
    x-on:keydown.window="
        if ($event.key === 'Enter' && $event.target === $el.querySelector('[data-trace-route]')) traceRoute();
    "
>
    <div class="relative mx-auto mb-8 max-w-lg" aria-hidden="true">
        <div class="not-found-radar relative mx-auto aspect-square w-full max-w-[min(100%,18rem)] rounded-full border border-brand-ink/10 bg-brand-sand/25 shadow-inner shadow-brand-forest/10 dark:bg-brand-sand/10">
            <div class="not-found-radar-sweep absolute inset-2 rounded-full"></div>
            <div class="not-found-radar-grid absolute inset-0 rounded-full"></div>

            <svg class="absolute inset-0 h-full w-full text-brand-forest/35 dark:text-brand-forest/50" viewBox="0 0 200 200" fill="none" aria-hidden="true">
                <circle cx="100" cy="100" r="72" stroke="currentColor" stroke-width="0.75" stroke-dasharray="4 6" />
                <circle cx="100" cy="100" r="48" stroke="currentColor" stroke-width="0.75" stroke-dasharray="3 5" />
                <circle cx="100" cy="100" r="24" stroke="currentColor" stroke-width="0.75" />
                <line x1="100" y1="28" x2="100" y2="172" stroke="currentColor" stroke-width="0.5" opacity="0.5" />
                <line x1="28" y1="100" x2="172" y2="100" stroke="currentColor" stroke-width="0.5" opacity="0.5" />
                <g class="not-found-node not-found-node--a">
                    <circle cx="52" cy="58" r="5" fill="var(--color-brand-gold)" />
                </g>
                <g class="not-found-node not-found-node--b">
                    <circle cx="148" cy="72" r="5" fill="var(--color-brand-sage)" />
                </g>
                <g class="not-found-node not-found-node--c">
                    <circle cx="118" cy="148" r="5" fill="var(--color-brand-copper)" />
                </g>
                <path d="M52 58 L148 72 L118 148 L52 58" stroke="currentColor" stroke-width="1" stroke-dasharray="5 4" opacity="0.45" />
            </svg>

            <button
                type="button"
                class="not-found-blip absolute z-10 flex h-9 w-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-brand-cream bg-brand-rust text-brand-cream shadow-lg shadow-brand-rust/40 transition-transform hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold focus-visible:ring-offset-2 focus-visible:ring-offset-brand-cream dark:focus-visible:ring-offset-brand-cream"
                :style="`left: ${blipX}%; top: ${blipY}%;`"
                x-on:click="catchBlip()"
                :aria-label="restored ? @js(__('Route restored')) : @js(__('Catch the ping'))"
                :disabled="restored"
            >
                <span class="not-found-blip-pulse absolute inset-0 rounded-full" x-show="!restored"></span>
                <x-heroicon-s-signal class="relative h-4 w-4" />
            </button>

            <div
                class="pointer-events-none absolute inset-0 flex items-center justify-center"
                x-show="restored"
                x-transition.opacity.duration.500ms
            >
                <span class="rounded-full bg-brand-forest/90 px-3 py-1 text-xs font-semibold tracking-wide text-brand-cream shadow-md">
                    {{ __('Online') }}
                </span>
            </div>
        </div>
    </div>

    <p
        class="not-found-code mb-2 text-6xl font-bold tracking-tighter text-brand-ink sm:text-7xl"
        :class="restored && 'not-found-code--restored'"
    >
        404
    </p>

    <p class="text-xl font-semibold text-brand-ink sm:text-2xl">
        {{ __('Page not found') }}
    </p>

    <p class="mx-auto mt-3 max-w-md text-base leading-relaxed text-brand-moss">
        {{ __('The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.') }}
    </p>

    <p class="mx-auto mt-4 min-h-[1.25rem] max-w-md text-sm font-medium text-brand-forest dark:text-brand-sage" x-text="quip"></p>

    @if (! empty($errorContext['server']) && empty($errorContext['site']))
        <p class="mx-auto mt-4 max-w-md text-sm text-brand-moss/80">
            {{ __('The server ":server" exists, but the specific page you requested could not be found.', ['server' => $errorContext['server']->name]) }}
        </p>
    @elseif (! empty($errorContext['site']))
        <p class="mx-auto mt-4 max-w-md text-sm text-brand-moss/80">
            {{ __('The site ":site" exists on server ":server", but the specific page was not found.', ['site' => $errorContext['site']->domain, 'server' => $errorContext['server']->name ?? '']) }}
        </p>
    @endif

    <div class="mx-auto mt-8 max-w-md text-left">
        <div class="flex flex-wrap items-center justify-center gap-3">
            <button
                type="button"
                data-trace-route
                class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-ink/10 transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-70"
                x-on:click="traceRoute()"
                x-bind:disabled="tracing"
            >
                <span class="inline-flex" x-bind:class="tracing && 'animate-spin'">
                    <x-heroicon-o-bolt class="h-4 w-4" />
                </span>
                <span x-text="tracing ? @js(__('Tracing…')) : @js(__('Trace route'))"></span>
            </button>
            <a
                href="{{ url('/') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/20 px-5 py-2.5 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/50"
            >
                <x-heroicon-o-home class="h-4 w-4" />
                {{ __('Go home') }}
            </a>
        </div>

        <div
            class="not-found-terminal mt-5 overflow-hidden rounded-xl border border-brand-ink/12 bg-brand-ink text-left shadow-inner"
            x-show="logs.length > 0"
            x-transition
            role="log"
            aria-live="polite"
            aria-relevant="additions"
        >
            <div class="flex items-center gap-2 border-b border-white/10 px-3 py-2">
                <span class="h-2.5 w-2.5 rounded-full bg-brand-rust/90"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-brand-gold/90"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-brand-sage/90"></span>
                <span class="ml-1 text-[10px] font-semibold uppercase tracking-widest text-brand-mist">{{ __('Route trace') }}</span>
            </div>
            <ul class="max-h-36 space-y-1 overflow-y-auto px-3 py-3 font-mono text-xs leading-relaxed text-brand-sand/90">
                <template x-for="(line, index) in logs" :key="index">
                    <li class="not-found-log-line flex gap-2">
                        <span class="shrink-0 text-brand-gold/80" x-text="String(index + 1).padStart(2, '0')"></span>
                        <span x-text="line"></span>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</div>

<style>
    @keyframes not-found-radar-spin {
        to { transform: rotate(360deg); }
    }

    @keyframes not-found-blip-pulse {
        0%, 100% { transform: scale(1); opacity: 0.55; }
        50% { transform: scale(1.35); opacity: 0; }
    }

    @keyframes not-found-node-float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-3px); }
    }

    @keyframes not-found-code-glow {
        0%, 100% { text-shadow: 0 0 0 transparent; }
        50% { text-shadow: 0 0 28px color-mix(in srgb, var(--color-brand-gold) 45%, transparent); }
    }

    @keyframes not-found-log-in {
        from { opacity: 0; transform: translateX(-6px); }
        to { opacity: 1; transform: translateX(0); }
    }

    .not-found-radar-sweep {
        background: conic-gradient(from 0deg, transparent 0deg, color-mix(in srgb, var(--color-brand-gold) 22%, transparent) 42deg, transparent 78deg);
        animation: not-found-radar-spin 4.5s linear infinite;
        transform-origin: center;
    }

    .not-found-radar-grid {
        background-image:
            radial-gradient(circle at center, transparent 58%, color-mix(in srgb, var(--color-brand-ink) 6%, transparent) 59%),
            repeating-radial-gradient(circle at center, transparent 0, transparent 18px, color-mix(in srgb, var(--color-brand-ink) 5%, transparent) 19px, transparent 20px);
    }

    .not-found-blip-pulse {
        background: var(--color-brand-rust);
        animation: not-found-blip-pulse 1.6s ease-out infinite;
    }

    .not-found-node--a { animation: not-found-node-float 3.2s ease-in-out infinite; }
    .not-found-node--b { animation: not-found-node-float 3.8s ease-in-out infinite 0.4s; }
    .not-found-node--c { animation: not-found-node-float 4.1s ease-in-out infinite 0.8s; }

    .not-found-code--restored {
        animation: not-found-code-glow 2s ease-in-out 1;
    }

    .not-found-log-line {
        animation: not-found-log-in 0.35s ease-out both;
    }

    @media (prefers-reduced-motion: reduce) {
        .not-found-radar-sweep,
        .not-found-blip-pulse,
        .not-found-node--a,
        .not-found-node--b,
        .not-found-node--c,
        .not-found-code--restored,
        .not-found-log-line {
            animation: none !important;
        }
    }
</style>
