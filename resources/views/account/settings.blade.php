@extends('layouts.app', ['pageTitle' => 'Settings'])

@section('content')
    <main class="page page--narrow">
        <h1 class="page__title">Settings</h1>

        <section class="panel" aria-labelledby="nameTitle">
            <h2 id="nameTitle" class="panel__title">Player name</h2>
            <p class="panel__intro">This is the name on the rankings and your top scores.</p>

            <form method="POST" action="{{ route('account.name') }}" class="form">
                @csrf
                @method('PATCH')
                <x-field name="name" label="Player name" :value="$user->name" maxlength="24" autocomplete="nickname" required />
                @if ($nextNameChange)
                    <p class="form__hint">You can change your name again on {{ $nextNameChange->toFormattedDateString() }}.</p>
                @else
                    <p class="form__hint">You can change it once a week.</p>
                @endif
                <button type="submit" class="btn btn--primary">Save name</button>
            </form>
        </section>

        <section class="panel" aria-labelledby="privacyTitle">
            <h2 id="privacyTitle" class="panel__title">Privacy</h2>

            <form method="POST" action="{{ route('account.privacy') }}" class="form">
                @csrf
                @method('PATCH')
                <input type="hidden" name="show_in_rankings" value="0">
                <label class="check">
                    <input type="checkbox" name="show_in_rankings" value="1" @checked(old('show_in_rankings', $user->show_in_rankings))>
                    Show me in the public player rankings
                </label>

                @if ($user->avatar_url)
                    <input type="hidden" name="show_avatar" value="0">
                    <label class="check">
                        <input type="checkbox" name="show_avatar" value="1" @checked(old('show_avatar', $user->show_avatar))>
                        Show my Google photo next to my name
                    </label>
                @else
                    <input type="hidden" name="show_avatar" value="0">
                @endif

                <p class="form__hint">Your games are still saved to your account either way.</p>
                <button type="submit" class="btn btn--primary">Save privacy settings</button>
            </form>
        </section>

        <p class="panel__footer"><a href="{{ route('account') }}">Back to your games</a></p>
    </main>
@endsection
