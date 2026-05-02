<?php

namespace App\Services\Sites;

use App\Models\Site;

class SitePlaceholderPageBuilder
{
    public function render(Site $site): string
    {
        $hostname = $site->testingHostname();
        if ($hostname === '') {
            $domains = $site->getRelation('domains');
            $hostname = (string) collect($domains)
                ->firstWhere('is_primary', true)?->hostname;

            if ($hostname === '') {
                $hostname = (string) collect($domains)->first()?->hostname;
            }
        }

        $siteName = $this->escape($site->name !== '' ? $site->name : 'New site');
        $hostname = $this->escape($hostname !== '' ? $hostname : 'Hostname pending');
        $documentRoot = $this->escape($site->effectiveDocumentRoot());
        $siteType = $this->escape(strtoupper($site->type->value));

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$siteName} is ready for deploy</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f8f4;
            --panel: #ffffff;
            --ink: #163127;
            --muted: #5e7369;
            --accent: #3f7d62;
            --accent-soft: #d9eee3;
            --border: rgba(22, 49, 39, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top, rgba(63, 125, 98, 0.18), transparent 38%),
                linear-gradient(180deg, #f7fbf8 0%, var(--bg) 100%);
            color: var(--ink);
            display: grid;
            place-items: center;
            padding: 32px 20px;
        }
        main {
            width: min(720px, 100%);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 24px 70px rgba(22, 49, 39, 0.08);
            overflow: hidden;
        }
        .hero {
            padding: 32px 32px 20px;
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
            font-size: clamp(2rem, 5vw, 3rem);
            line-height: 1.05;
        }
        p {
            margin: 0;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.7;
        }
        .details {
            display: grid;
            gap: 14px;
            padding: 24px 32px 32px;
        }
        .detail {
            padding: 16px 18px;
            border-radius: 18px;
            background: #f8fbf9;
            border: 1px solid rgba(22, 49, 39, 0.08);
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
            <div class="eyebrow">Dply placeholder</div>
            <h1>{$siteName}</h1>
            <p>This temporary page confirms the hostname and web server are wired up. Deploy your app to replace it with the real site.</p>
        </section>
        <section class="details">
            <div class="detail">
                <span class="detail-label">Hostname</span>
                <div class="detail-value">{$hostname}</div>
            </div>
            <div class="detail">
                <span class="detail-label">Site type</span>
                <div class="detail-value">{$siteType}</div>
            </div>
            <div class="detail">
                <span class="detail-label">Document root</span>
                <div class="detail-value">{$documentRoot}</div>
            </div>
        </section>
    </main>
</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
