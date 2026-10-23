<nav class="topnav" aria-label="Main">
    <a href="{{ route('game') }}" class="topnav__brand" @if (request()->routeIs('game')) aria-current="page" @endif>🩺 Play</a>
    <a href="{{ route('leaderboard') }}" class="topnav__link" @if (request()->routeIs('leaderboard')) aria-current="page" @endif>Rankings</a>

    <span class="topnav__spacer"></span>

    @auth
        @if (auth()->user()->hasVerifiedEmail())
            <a href="{{ route('account') }}" class="topnav__link topnav__user" @if (request()->routeIs('account')) aria-current="page" @endif>
                @if (auth()->user()->avatar_url)
                    <img src="{{ auth()->user()->avatar_url }}" alt="" class="topnav__avatar" referrerpolicy="no-referrer">
                @endif
                {{ auth()->user()->name }}
            </a>
        @else
            <a href="{{ route('verification.notice') }}" class="topnav__link topnav__warn">Verify email</a>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="topnav__form">
            @csrf
            <button type="submit" class="topnav__link topnav__button">Log out</button>
        </form>
    @else
        <a href="{{ route('login') }}" class="topnav__link" @if (request()->routeIs('login')) aria-current="page" @endif>Log in</a>
        <a href="{{ route('register') }}" class="topnav__link topnav__cta">Register</a>
    @endauth
</nav>
