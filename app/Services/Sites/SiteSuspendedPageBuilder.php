<?php

namespace App\Services\Sites;

use App\Models\Site;

class SiteSuspendedPageBuilder
{
    public function render(Site $site): string
    {
        $siteName = $this->escape($site->name !== '' ? $site->name : 'Site');
        $hostname = $this->escape($this->primaryHostname($site));
        $message = trim($site->suspendedPublicMessage());
        $reason = $this->escape($this->humanizeReason($message));
        $reasonHtml = $message !== ''
            ? '<div class="reason"><span class="reason-label">Reason</span><span class="reason-value">'.$reason.'</span></div>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{$siteName} — unavailable</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f8f4;
            --panel: #ffffff;
            --ink: #163127;
            --muted: #5e7369;
            --faint: #8a9c93;
            --accent: #8b5a3c;
            --accent-soft: #f3e8e0;
            --border: rgba(22, 49, 39, 0.10);
            --hairline: rgba(22, 49, 39, 0.07);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(900px circle at 50% -10%, rgba(139, 90, 60, 0.10), transparent 55%),
                linear-gradient(180deg, #faf8f6 0%, var(--bg) 100%);
            color: var(--ink);
            display: grid;
            place-items: center;
            padding: 32px 20px;
        }
        main {
            width: min(540px, 100%);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 28px;
            box-shadow: 0 1px 2px rgba(22, 49, 39, 0.04), 0 30px 80px -24px rgba(22, 49, 39, 0.18);
            overflow: hidden;
        }
        .hero {
            padding: 40px 40px 32px;
        }
        .mark {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }
        .glyph {
            display: grid;
            place-items: center;
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--accent-soft);
            color: var(--accent);
            flex: none;
        }
        .glyph svg { width: 24px; height: 24px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.10em;
            text-transform: uppercase;
        }
        .badge .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 2.2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.8); }
        }
        @media (prefers-reduced-motion: reduce) {
            .badge .dot { animation: none; }
        }
        h1 {
            margin: 0 0 12px;
            font-size: clamp(1.6rem, 4.5vw, 2.1rem);
            line-height: 1.12;
            letter-spacing: -0.02em;
            font-weight: 700;
        }
        h1 .name { color: var(--ink); }
        p.lede {
            margin: 0;
            color: var(--muted);
            font-size: 1.0625rem;
            line-height: 1.6;
        }
        .reason {
            margin-top: 24px;
            padding: 16px 18px;
            border-radius: 16px;
            background: #f7f9f7;
            border: 1px solid var(--hairline);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .reason-label {
            color: var(--faint);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.10em;
            text-transform: uppercase;
        }
        .reason-value {
            color: var(--ink);
            font-size: 0.975rem;
            line-height: 1.55;
        }
        .meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 40px;
            border-top: 1px solid var(--hairline);
            background: #fcfdfc;
        }
        .meta-block { min-width: 0; }
        .meta-label {
            display: block;
            margin-bottom: 4px;
            color: var(--faint);
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.10em;
            text-transform: uppercase;
        }
        .meta-value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 0.9rem;
            color: var(--ink);
            word-break: break-word;
        }
        .by {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--faint);
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            flex: none;
        }
        .by b { color: var(--muted); font-weight: 700; }
    </style>
</head>
<body>
    <main>
        <section class="hero">
            <div class="mark">
                <span class="glyph" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9"></circle>
                        <line x1="10" y1="9" x2="10" y2="15"></line>
                        <line x1="14" y1="9" x2="14" y2="15"></line>
                    </svg>
                </span>
                <span class="badge"><span class="dot"></span>Suspended</span>
            </div>
            <h1><span class="name">{$siteName}</span> is temporarily unavailable</h1>
            <p class="lede">This site has been paused by the operator. Please check back soon.</p>
            {$reasonHtml}
        </section>
        <section class="meta">
            <div class="meta-block">
                <span class="meta-label">Hostname</span>
                <div class="meta-value">{$hostname}</div>
            </div>
            <span class="by">Powered by <b>dply</b></span>
        </section>
    </main>
</body>
</html>
HTML;
    }

    /**
     * Turn a machine reason code (e.g. `server_maintenance`) into readable text
     * while leaving operator-authored sentences untouched.
     */
    private function humanizeReason(string $reason): string
    {
        if (preg_match('/^[a-z0-9]+([_-][a-z0-9]+)+$/i', $reason) !== 1) {
            return $reason;
        }

        return ucfirst(strtolower(str_replace(['_', '-'], ' ', $reason)));
    }

    private function primaryHostname(Site $site): string
    {
        $site->loadMissing('domains');
        $hostname = (string) collect($site->domains)
            ->firstWhere('is_primary', true)?->hostname;

        if ($hostname === '') {
            $hostname = (string) collect($site->domains)->first()?->hostname;
        }

        if ($hostname === '') {
            $hostname = $site->testingHostname();
        }

        return $hostname !== '' ? $hostname : '—';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
