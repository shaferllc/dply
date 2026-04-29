@php
    $dplyTheme = 'system';
    if (auth()->check()) {
        $dplyTheme = auth()->user()->mergedUiPreferences()['theme'] ?? 'system';
    }
@endphp
<meta name="dply-theme" content="{{ $dplyTheme }}">
<script>
    (function () {
        function dplyThemeIsDark(theme) {
            if (theme === 'dark') {
                return true;
            }
            if (theme === 'light') {
                return false;
            }
            return window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        var meta = document.querySelector('meta[name="dply-theme"]');
        var theme = (meta && meta.getAttribute('content')) || 'system';
        document.documentElement.classList.toggle('dark', dplyThemeIsDark(theme));
    })();
</script>
