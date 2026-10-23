@extends('layouts.app', ['pageTitle' => 'Your games'])

@section('content')
    <main class="page">
        <h1 class="page__title">@include('partials.player', ['user' => $user])</h1>

        <dl class="tiles">
            <div class="tile"><dt>Games finished</dt><dd>{{ $gamesFinished }}</dd></div>
            <div class="tile"><dt>Turns taken</dt><dd>{{ number_format($turnsTaken) }}</dd></div>
            <div class="tile"><dt>Grids played</dt><dd>{{ $gridStats->count() }} / {{ count($grids) }}</dd></div>
        </dl>

        <section class="panel">
            <h2 class="panel__title">Records by grid</h2>
            <p class="panel__intro">Solo games only. Two-player games appear in your history below.</p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th scope="col">Grid</th><th scope="col" class="num">Games</th><th scope="col" class="num">Best turns</th><th scope="col" class="num">Average turns</th><th scope="col" class="num">Fastest</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($grids as $key => $grid)
                            @php($stats = $gridStats->get($key))
                            <tr>
                                <th scope="row">{{ $grid['label'] }} <span class="muted">· {{ $grid['pairs'] }} pairs</span></th>
                                <td class="num">{{ $stats?->games_played ?? 0 }}</td>
                                <td class="num">{{ $stats?->best_turns ?? '—' }}</td>
                                <td class="num">{{ $stats ? number_format($stats->average_turns, 1) : '—' }}</td>
                                <td class="num">{{ $stats ? \App\Support\Duration::format((int) $stats->best_seconds) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2 class="panel__title">Recent games</h2>
            @if ($recentGames->isEmpty())
                <p class="empty">No finished games yet. <a href="{{ route('game') }}">Play one</a>.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th scope="col">When</th><th scope="col">Grid</th><th scope="col">Mode</th><th scope="col" class="num">Turns</th><th scope="col" class="num">Time</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($recentGames as $game)
                                <tr>
                                    <td><time datetime="{{ $game->finished_at->toIso8601String() }}">{{ $game->finished_at->diffForHumans() }}</time></td>
                                    <td>{{ $grids[$game->difficulty]['label'] ?? $game->difficulty }}</td>
                                    <td>{{ $game->players === 2 ? 'Two players' : ($game->isDaily() ? 'Daily' : 'Free play') }}</td>
                                    <td class="num">{{ $game->turns }}</td>
                                    <td class="num">{{ \App\Support\Duration::format($game->seconds) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </main>
@endsection
