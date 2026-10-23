@php($colors = config('game.colors'))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($pageTitle) ? $pageTitle.' · ' : '' }}{{ config('game.title') }}</title>

    {{-- Never on pages whose URLs carry tokens or personal data. --}}
    @if (($gaId = config('services.google_analytics.measurement_id')) && ! request()->routeIs('password.reset', 'password.request', 'login', 'register', 'verification.*'))
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @js($gaId), { page_location: location.origin + location.pathname });
        </script>
    @endif

    <style>
        :root {
            --game-bg: {{ $colors['background'] }};
            --game-card-back: {{ $colors['card_back'] }};
            --game-card-front: {{ $colors['card_front'] }};
            --game-text: {{ $colors['text'] }};
            --game-button: {{ $colors['button'] }};
            --game-button-text: {{ $colors['button_text'] }};
            --game-font: '{{ config('game.font_family') }}', Arial, sans-serif;
            --game-font-size: {{ (int) config('game.font_size') / 16 }}rem; /* rem, so browser text size settings apply */
        }
    </style>

    @vite(['resources/css/app.css', 'resources/js/game.js'])
</head>
<body class="site h-full m-0 p-0">
    @include('partials.nav')

    @if (session('status'))
        <p class="flash" role="status">{{ session('status') }}</p>
    @endif

    @yield('content')

    <footer class="sitefoot">
        <a href="{{ route('privacy') }}">Privacy</a>
        <a href="{{ route('leaderboard') }}">Rankings</a>
    </footer>
</body>
</html>
