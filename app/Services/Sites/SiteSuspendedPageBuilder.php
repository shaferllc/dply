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
        $reasonHtml = $message !== ''
            ? '<p class="reason">'.$this->escape($message).'</p>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{$siteName} — suspended</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f8f4;
            --panel: #ffffff;
            --ink: #163127;
            --muted: #5e7369;
            --accent: #8b5a3c;
            --accent-soft: #f3e8e0;
            --border: rgba(22, 49, 39, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top, rgba(139, 90, 60, 0.12), transparent 40%),
                linear-gradient(180deg, #faf8f6 0%, var(--bg) 100%);
            color: var(--ink);
            display: grid;
            place-items: center;
            padding: 32px 20px;
        }
        main {
            width: min(640px, 100%);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 24px 70px rgba(22, 49, 39, 0.08);
            overflow: hidden;
        }
        .hero {
            padding: 32px 32px 28px;
            border-bottom: 1px solid var(--border);
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        h1 {
            margin: 18px 0 10px;
            font-size: clamp(1.5rem, 4vw, 2.25rem);
            line-height: 1.15;
        }
        p {
            margin: 0;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.7;
        }
        p.reason {
            margin-top: 16px;
            padding: 16px 18px;
            border-radius: 16px;
            background: #f8f9f8;
            border: 1px solid rgba(22, 49, 39, 0.08);
            color: var(--ink);
        }
        .detail {
            padding: 20px 32px 32px;
        }
        .detail-label {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .detail-value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 0.95rem;
            color: var(--ink);
            word-break: break-word;
        }
    </style>
</head>
<body>
    <main>
        <section class="hero">
            <div class="eyebrow">Suspended</div>
            <h1>{$siteName} is not available</h1>
            <p>This site has been temporarily suspended by the operator.</p>
            {$reasonHtml}
        </section>
        <section class="detail">
            <span class="detail-label">Hostname</span>
            <div class="detail-value">{$hostname}</div>
        </section>
    </main>
</body>
</html>
HTML;
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
