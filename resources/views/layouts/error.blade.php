{{-- Error pages: no session, auth or database access, so they render even when those are the cause. --}}
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('game.title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; box-sizing: border-box;
               background: {{ config('game.colors.background') }}; color: #fff; font-family: Arial, sans-serif; }
        main { max-width: 28rem; text-align: center; }
        .code { font-size: 4rem; margin: 0; opacity: .5; }
        h1 { margin: .25rem 0 .5rem; font-size: 1.5rem; }
        p { line-height: 1.5; opacity: .9; }
        a { color: #fff; font-weight: bold; margin: 0 .5rem; }
    </style>
</head>
<body>
    <main>
        <p class="code" aria-hidden="true">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <p><a href="{{ url('/') }}">Play the game</a> <a href="{{ url('/login') }}">Log in</a></p>
    </main>
</body>
</html>
