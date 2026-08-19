{{--
    Hand-off page for a desktop database client.

    The body carries a live credential, so it must not be cached, indexed, or
    referred onward — the controller sets no-store + no-referrer, and the URI is
    never written into the document where a screenshot or the DOM would retain
    it. It is passed to the client and the variable is cleared immediately.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Opening :name…', ['name' => $label]) }}</title>
    <style>
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; margin: 0; background: #faf7f2; color: #2f3b30;
        }
        .card {
            max-width: 26rem; padding: 2rem; text-align: center;
            border: 1px solid rgba(47, 59, 48, 0.1); border-radius: 0.75rem; background: #fff;
        }
        h1 { font-size: 1rem; margin: 0 0 0.5rem; }
        p { font-size: 0.8125rem; line-height: 1.6; margin: 0 0 0.5rem; color: #5c6b5d; }
        button {
            margin-top: 1rem; padding: 0.5rem 1rem; font: inherit; font-size: 0.8125rem;
            font-weight: 600; border-radius: 0.5rem; border: 1px solid rgba(47, 59, 48, 0.15);
            background: #fff; color: #2f3b30; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Opening :name in your database client…', ['name' => $label]) }}</h1>
        <p>{{ __('If nothing happens, your system may not have a client registered for this database type.') }}</p>
        <p>{{ __('You can close this tab once the connection opens.') }}</p>
        <button type="button" id="retry">{{ __('Try again') }}</button>
    </div>

    <script>
        (function () {
            // Held in a closure and dropped after hand-off, so the credential is
            // never attached to the document or reachable from the console.
            var uri = @json($uri);

            function handoff() {
                window.location.href = uri;
            }

            document.getElementById('retry').addEventListener('click', handoff);
            handoff();

            // The link is single-use in spirit: once handed off, forget it and
            // replace the signed URL in history so a back-navigation cannot
            // re-request it from this tab.
            setTimeout(function () {
                uri = '';
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, document.title, '/');
                }
            }, 2000);
        })();
    </script>
</body>
</html>
