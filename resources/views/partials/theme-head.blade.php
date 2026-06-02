{{-- Dark mode temporarily disabled: theme forced to light regardless of user preference. --}}
<meta name="dply-theme" content="light">
<script>
    (function () {
        document.documentElement.classList.remove('dark');
    })();
</script>

{{-- Favicons (served from public/ root). --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="theme-color" content="#171a0e">
