@extends('layouts.app', ['pageTitle' => 'Privacy'])

@section('content')
    <main class="page page--narrow">
        <h1 class="page__title">Privacy</h1>

        <section class="panel">
            <h2 class="panel__title">What others can see</h2>
            <ul class="prose-list">
                <li>Your player name and your results (turns and times) on the top scores and the player rankings.</li>
                <li>Your Google photo, only if you turn it on in Settings.</li>
                <li>You can hide yourself from the player rankings in Settings at any time.</li>
            </ul>
        </section>

        <section class="panel">
            <h2 class="panel__title">What stays private</h2>
            <ul class="prose-list">
                <li>Your email address. We use it to verify your account, reset your password and confirm a deletion. We never show it to other players.</li>
                <li>Your sign-in sessions (device and IP address), kept so you stay signed in.</li>
                <li>Guest players: the name you type on a top score, and your browser session.</li>
                <li>Games that are started but never finished are deleted after a day.</li>
            </ul>
        </section>

        <section class="panel">
            <h2 class="panel__title">Analytics</h2>
            <p>
                @if (config('services.google_analytics.measurement_id'))
                    We use Google Analytics to count visits. It never runs on the sign-in, registration, password or verification pages, and it never receives web addresses with personal details in them.
                @else
                    This site does not use analytics.
                @endif
            </p>
        </section>

        <section class="panel">
            <h2 class="panel__title">Taking your data or deleting it</h2>
            <p>
                Signed-in players can download everything we keep about them, or delete their account and all of its games and scores, from
                @auth
                    <a href="{{ route('account.settings') }}">Settings</a>.
                @else
                    Settings, after logging in.
                @endauth
            </p>
        </section>
    </main>
@endsection
