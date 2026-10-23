@extends('layouts.app', ['pageTitle' => 'Player rankings'])

@section('content')
    <main class="page">
        <h1 class="page__title">Player rankings</h1>
        <p class="page__intro">
            Every solo game a registered player finishes counts. Free play and daily challenges both count.
            <a href="{{ route('register') }}">Register</a> to get on the table.
        </p>

        <section class="panel">
            <h2 class="panel__title">Overall</h2>
            <p class="panel__intro">
                Ranked by average turns per pair across all grids. 1.00 is a perfect game.
                Players need {{ $minimumGames }} finished {{ \Illuminate\Support\Str::plural('game', $minimumGames) }} to be ranked.
            </p>

            @if ($overallRankings->isEmpty())
                <p class="empty">Nobody has finished {{ $minimumGames }} {{ \Illuminate\Support\Str::plural('game', $minimumGames) }} yet.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th scope="col">#</th><th scope="col">Player</th><th scope="col" class="num">Turns per pair</th><th scope="col" class="num">Games</th><th scope="col" class="num">Pairs found</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($overallRankings as $row)
                                <tr @if ($row->user_id === auth()->id()) class="table__mine" @endif>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>@include('partials.player', ['user' => $row->user])</td>
                                    <td class="num">{{ number_format($row->turns_per_pair, 2) }}</td>
                                    <td class="num">{{ $row->games_played }}</td>
                                    <td class="num">{{ $row->pairs_found }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="panel">
            <h2 class="panel__title">By grid</h2>

            <nav class="tabs" aria-label="Grid size">
                @foreach ($grids as $key => $option)
                    <a href="{{ route('leaderboard', ['grid' => $key]) }}" class="tabs__tab" @if ($key === $grid) aria-current="page" @endif>
                        {{ $option['label'] }}
                    </a>
                @endforeach
            </nav>

            @if ($gridRankings->isEmpty())
                <p class="empty">No registered player has finished a {{ $grids[$grid]['label'] }} game yet.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th scope="col">#</th><th scope="col">Player</th><th scope="col" class="num">Best turns</th><th scope="col" class="num">Average turns</th><th scope="col" class="num">Fastest</th><th scope="col" class="num">Games</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($gridRankings as $row)
                                <tr @if ($row->user_id === auth()->id()) class="table__mine" @endif>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>@include('partials.player', ['user' => $row->user])</td>
                                    <td class="num">{{ $row->best_turns }}</td>
                                    <td class="num">{{ number_format($row->average_turns, 1) }}</td>
                                    <td class="num">{{ \App\Support\Duration::format((int) $row->best_seconds) }}</td>
                                    <td class="num">{{ $row->games_played }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </main>
@endsection
