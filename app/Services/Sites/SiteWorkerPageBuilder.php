<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteProcess;

/**
 * Public page served for a worker-host site ({@see Site::isWorkerSite()}).
 *
 * A worker site has no web app — it only runs background/queue processes from
 * the deployed code. Caddy serves this page for every request so visitors get
 * a clear explanation instead of the "awaiting first deploy" placeholder (which
 * would never be replaced) or a browsable view of the deployed source.
 */
class SiteWorkerPageBuilder
{
    public function render(Site $site): string
    {
        $siteName = $this->escape($site->name !== '' ? $site->name : 'Worker');
        $serverName = $this->escape($site->server?->name ?? '—');
        $runtimeKey = $site->runtimeKey() ?: $site->type->value;
        $runtimeVersion = (string) ($site->runtimeVersion() ?? '');
        $runtimeLabel = $this->escape(strtoupper($runtimeKey).($runtimeVersion !== '' ? ' '.$runtimeVersion : ''));

        $processCount = $this->backgroundProcessCount($site);
        $processLabel = $this->escape($processCount === 1
            ? '1 background process'
            : $processCount.' background processes');

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{$siteName} · worker — no web interface</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23163127'/%3E%3Ctext x='32' y='42' font-family='Inter,sans-serif' font-size='32' font-weight='700' text-anchor='middle' fill='%23f4ead5'%3Ed%3C/text%3E%3C/svg%3E">
    <style>
        :root {
            color-scheme: light;
            --ink: #163127;
            --moss: #5e7369;
            --mist: #8aa097;
            --sand: #f4ead5;
            --cream: #fbf6ea;
            --sage: #5e9d7c;
            --forest: #2d6a4f;
            --panel: rgba(255, 255, 255, 0.92);
            --border: rgba(22, 49, 39, 0.12);
            --shadow: 0 30px 80px rgba(22, 49, 39, 0.16);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            min-height: 100dvh;
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
            background: var(--cream);
            position: relative;
            overflow-x: hidden;
            display: grid;
            place-items: center;
            padding: clamp(20px, 4vw, 56px);
        }
        .aurora {
            position: fixed;
            inset: -20% -10% auto -10%;
            height: 70vh;
            pointer-events: none;
            z-index: 0;
            filter: blur(80px);
            opacity: 0.7;
        }
        .aurora::before, .aurora::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            mix-blend-mode: multiply;
            animation: drift 22s ease-in-out infinite alternate;
        }
        .aurora::before {
            width: 60vmin; height: 60vmin;
            background: radial-gradient(circle, rgba(94,157,124,0.55), transparent 60%);
            left: 8%;
            top: 5%;
        }
        .aurora::after {
            width: 70vmin; height: 70vmin;
            background: radial-gradient(circle, rgba(244,234,213,0.85), transparent 60%);
            right: -8%;
            top: -8%;
            animation-delay: -8s;
        }
        @keyframes drift {
            0%   { transform: translate3d(0,0,0) scale(1); }
            50%  { transform: translate3d(2vw,3vh,0) scale(1.05); }
            100% { transform: translate3d(-1vw,-2vh,0) scale(0.98); }
        }
        main {
            position: relative;
            z-index: 1;
            width: min(720px, 100%);
            background: var(--panel);
            backdrop-filter: blur(24px) saturate(120%);
            -webkit-backdrop-filter: blur(24px) saturate(120%);
            border: 1px solid var(--border);
            border-radius: clamp(20px, 3vw, 32px);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 22px clamp(22px, 3.5vw, 40px) 0;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.02em;
        }
        .brand .mark {
            display: inline-grid;
            place-items: center;
            width: 30px; height: 30px;
            border-radius: 8px;
            background: var(--ink);
            color: var(--cream);
            font-size: 16px;
            font-weight: 700;
            box-shadow: inset 0 -2px 0 rgba(255,255,255,0.06);
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(94,157,124,0.16);
            color: var(--forest);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .pill .dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--sage);
            box-shadow: 0 0 0 0 rgba(94,157,124,0.7);
            animation: pulse 1.6s ease-out infinite;
        }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgba(94,157,124,0.7); }
            70%  { box-shadow: 0 0 0 10px rgba(94,157,124,0); }
            100% { box-shadow: 0 0 0 0 rgba(94,157,124,0); }
        }
        .hero {
            padding: 30px clamp(22px, 3.5vw, 40px) 26px;
        }
        .hero h1 {
            margin: 18px 0 14px;
            font-size: clamp(2rem, 5vw, 3.1rem);
            font-weight: 700;
            line-height: 1.05;
            letter-spacing: -0.02em;
        }
        .hero h1 .mute { color: var(--moss); font-weight: 600; }
        .hero p {
            margin: 0;
            color: var(--moss);
            font-size: clamp(1rem, 1.4vw, 1.06rem);
            line-height: 1.65;
            max-width: 58ch;
        }
        .details {
            display: grid;
            gap: 10px;
            padding: 0 clamp(22px, 3.5vw, 40px) 28px;
            grid-template-columns: 1fr;
        }
        @media (min-width: 640px) {
            .details { grid-template-columns: repeat(3, 1fr); }
        }
        .detail {
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255,255,255,0.7);
            border: 1px solid var(--border);
        }
        .detail-label {
            display: block;
            margin-bottom: 4px;
            color: var(--mist);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .detail-value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 0.92rem;
            color: var(--ink);
            word-break: break-word;
        }
        footer {
            padding: 16px clamp(22px, 3.5vw, 40px);
            border-top: 1px solid var(--border);
            background: rgba(244,234,213,0.45);
            font-size: 12px;
            color: var(--moss);
        }
        @media (prefers-reduced-motion: reduce) {
            .aurora::before, .aurora::after, .pill .dot { animation: none; }
        }
    </style>
</head>
<body>
    <div class="aurora" aria-hidden="true"></div>
    <main>
        <div class="top">
            <span class="brand"><span class="mark">d</span> Dply</span>
            <span class="pill"><span class="dot" aria-hidden="true"></span> Worker · running</span>
        </div>
        <section class="hero">
            <h1>{$siteName}<br><span class="mute">runs background workers — there is no website here.</span></h1>
            <p>This host is a worker. It processes queues and scheduled jobs from the deployed code; it does not serve web traffic, so there is no page to visit. Manage and monitor the processes from your Dply dashboard.</p>
        </section>
        <section class="details">
            <div class="detail">
                <span class="detail-label">Mode</span>
                <div class="detail-value">Worker</div>
            </div>
            <div class="detail">
                <span class="detail-label">Runtime</span>
                <div class="detail-value">{$runtimeLabel}</div>
            </div>
            <div class="detail">
                <span class="detail-label">Server</span>
                <div class="detail-value">{$serverName}</div>
            </div>
        </section>
        <footer>
            Served by <strong>Dply</strong> · {$processLabel} configured. This URL is intentionally locked down — the deployed code is not browsable.
        </footer>
    </main>
</body>
</html>
HTML;
    }

    private function backgroundProcessCount(Site $site): int
    {
        $site->loadMissing('processes');

        return $site->processes
            ->whereIn('type', [SiteProcess::TYPE_WORKER, SiteProcess::TYPE_SCHEDULER, SiteProcess::TYPE_CUSTOM])
            ->where('is_active', true)
            ->count();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
