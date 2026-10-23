@extends('layouts.app')

@section('content')
    <div
        id="app"
        class="game w-full"
        data-cards='@json($cards)'
        data-difficulties='@json($difficulties)'
        data-default-difficulty="{{ $defaultDifficulty }}"
        data-today="{{ $today }}"
        data-daily-resets="{{ now()->addDay()->startOfDay()->toIso8601String() }}"
        data-title="{{ $title }}"
        data-win-message="{{ $winMessage }}"
        data-games-url="{{ $gamesUrl }}"
        data-finish-url="{{ route('games.finish', ['game' => '__GAME__']) }}"
        data-player-name="{{ $playerName }}"
        data-daily-played='@json($dailyPlayed)'
        data-initial-mode="{{ $initialMode }}"
        data-initial-grid="{{ $initialGrid }}"
        data-scores-url="{{ route('scores.index') }}"
        data-daily-stats-url="{{ route('daily.stats') }}"
        data-store-url="{{ route('scores.store') }}"
    >
        <div class="game__wrap">
            <header class="game__header">
                <button type="button" id="soundBtn" class="game__icon-button game__sound" aria-pressed="true" aria-label="Sound on" title="Toggle sound">🔊</button>

                <h1 id="gameTitle" class="game__title">{{ $title }}</h1>
                @auth
                    @unless (auth()->user()->hasVerifiedEmail())
                        <p class="game__notice">
                            Confirm your email to save games to your account.
                            <a href="{{ route('verification.notice') }}">Resend the link</a>
                        </p>
                    @endunless
                @else
                    <p class="game__notice">
                        <a href="{{ route('register') }}">Register</a> or <a href="{{ route('login') }}">log in</a> to keep every game you finish and join the
                        <a href="{{ route('leaderboard') }}">player rankings</a>.
                    </p>
                @endauth

                <div class="game__toolbar" id="gameToolbar">
                    <div class="game__modes" role="group" aria-label="Game mode">
                        <button type="button" class="game__mode" data-mode="free" aria-pressed="true">Free play</button>
                        <button type="button" class="game__mode" data-mode="daily" aria-pressed="false">Daily challenge</button>
                    </div>

                    <label class="game__difficulty">
                        <span>Grid</span>
                        <select id="difficulty">
                            @foreach ($difficulties as $key => $level)
                                <option value="{{ $key }}" @selected($key === $defaultDifficulty)>
                                    {{ $level['label'] }} · {{ $level['pairs'] }} pairs
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="game__difficulty">
                        <span>Players</span>
                        <select id="players">
                            <option value="1">1 player</option>
                            <option value="2">2 players</option>
                        </select>
                    </label>

                    <button type="button" id="resetBtn" class="game__button">New Game</button>
                    {{-- Phones only, while playing: brings back the settings the focus mode hides. --}}
                    <button type="button" id="settingsToggle" class="game__icon-button game__settings" aria-expanded="false" aria-controls="gameToolbar" aria-label="Game settings" title="Game settings">⚙️</button>
                </div>

                <p id="modeHint" class="game__hint">
                    Same board for everyone today. Fewest turns wins.
                    <span id="streak" class="game__streak" hidden></span>
                </p>
                <p id="practiceNotice" class="game__hint" hidden></p>
                <p id="dailyNotice" class="game__hint game__hint--alert" role="status" hidden>A new daily challenge is ready. Press New Game to play it.</p>

                <div id="playersBar" class="players" hidden>
                    <div class="players__card" data-player="0">
                        <span class="players__name">Player 1</span>
                        <span class="players__score" id="playerScore0">0</span>
                    </div>
                    <div class="players__card" data-player="1">
                        <span class="players__name">Player 2</span>
                        <span class="players__score" id="playerScore1">0</span>
                    </div>
                </div>

                <dl class="game__stats">
                    <div class="game__stat">
                        <dt>Pairs</dt>
                        <dd><span id="pairsCount">0</span> / <span id="pairsTotal">0</span></dd>
                    </div>
                    <div class="game__stat">
                        <dt>Turns</dt>
                        <dd>
                            <span id="moves">0</span>
                            <span id="starMeter" class="stat__meter" aria-hidden="true"></span>
                        </dd>
                    </div>
                    <div class="game__stat">
                        <dt>Time</dt>
                        <dd id="timer">0:00</dd>
                    </div>
                    <div class="game__stat" id="bestStat">
                        <dt>Your best</dt>
                        <dd id="best">—</dd>
                    </div>
                </dl>
            </header>

            <main class="game__main">
                <p id="boardHelp" class="sr-only">Use the arrow keys to move between cards, Home and End to jump to the first and last card, and Enter or Space to flip one. Each turn's result is read out.</p>
                <p id="announcer" class="sr-only" aria-live="polite" aria-atomic="true"></p>
                <p id="introLine" class="game__intro" hidden>Find every pair in as few turns as you can. A turn is two flips; time only breaks ties.</p>
                <div id="gameBoard" class="game__board" role="group" aria-label="Memory cards" aria-describedby="boardHelp"></div>

                <section class="leaderboard" aria-labelledby="leaderboardTitle">
                    <h2 id="leaderboardTitle" class="leaderboard__title"></h2>
                    <ol id="leaderboard" class="leaderboard__list"></ol>
                    <p id="leaderboardEmpty" class="leaderboard__empty" hidden>No scores yet. Be the first!</p>
                    <p id="leaderboardError" class="leaderboard__empty" hidden>Could not load scores.</p>
                    <p class="leaderboard__more"><a href="{{ route('leaderboard') }}">See the player rankings</a></p>
                </section>
            </main>
        </div>

        <dialog id="winDialog" class="win" aria-labelledby="winMessage">
            {{-- Inside the dialog so it draws in the top layer, above the backdrop. --}}
            <div id="confetti" class="confetti" aria-hidden="true"></div>
            <div class="win__emoji" aria-hidden="true">🎉</div>
            <h2 id="winMessage" class="win__message">{{ $winMessage }}</h2>
            <div id="winStars" class="win__stars" role="img" aria-label=""></div>
            <p id="winSummary" class="win__summary"></p>
            <p id="winRecord" class="win__record" hidden>New personal best!</p>
            <p id="winShort" class="win__summary" hidden></p>
            <div id="dailyResult" class="daily-result" hidden>
                <p id="dailyRank" class="daily-result__rank"></p>
                <div id="dailyChart" class="daily-chart" role="img" aria-label=""></div>
            </div>
            <p id="unrankedNotice" class="win__note" hidden></p>
            @guest
                <p class="win__note win__note--guest"><a href="{{ route('register') }}">Register</a> to save your games and join the player rankings.</p>
            @endguest
            <p id="winSaved" class="win__saved" hidden>
                @auth
                    @if (auth()->user()->hasVerifiedEmail())
                        Saved to <a href="{{ route('account') }}">your games</a>.
                    @endif
                @endauth
            </p>

            <form id="scoreForm" class="win__form" method="dialog">
                <label for="playerName" id="scoreLabel" class="win__label">
                    @if ($playerName)
                        Add this game to the top scores as {{ $playerName }}
                    @else
                        Add your score to the top scores
                    @endif
                </label>
                <div class="win__row">
                    <input id="playerName" name="player_name" type="text" maxlength="24" placeholder="Your name" autocomplete="nickname" @if ($playerName) hidden value="{{ $playerName }}" @else required @endif>
                    <button type="submit" id="submitScore" class="game__button">Submit</button>
                </div>
                <p id="signedOutNotice" class="win__error" hidden>You were signed out, so this game was not saved to your account. Log in again to keep future games.</p>
                <p id="scoreError" class="win__error" role="alert" hidden></p>
            </form>

            <div class="win__actions">
                <button type="button" id="shareBtn" class="game__button game__button--secondary">Share result</button>
                <button type="button" id="nextGridBtn" class="game__button game__button--secondary" hidden></button>
                <button type="button" id="playAgainBtn" class="game__button">Play Again</button>
            </div>
        </dialog>

        <div id="fact" class="fact" hidden>
            <span id="factIcon" class="fact__icon" aria-hidden="true"></span>
            <span id="factText" class="fact__text"></span>
        </div>

    </div>
@endsection
