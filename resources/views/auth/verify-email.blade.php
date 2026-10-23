@extends('layouts.app', ['pageTitle' => 'Verify your email'])

@section('content')
    <x-auth-card title="Check your inbox">
        <p class="panel__intro">
            We sent a link to <strong>{{ auth()->user()->email }}</strong>. Open it to finish registering.
            Until then you can still play, but games are not saved to your account.
        </p>

        <form method="POST" action="{{ route('verification.send') }}" class="form">
            @csrf
            <button type="submit" class="btn btn--primary">Send the link again</button>
        </form>

        <x-slot:footer>
            <a href="{{ route('game') }}">Back to the game</a>
        </x-slot:footer>
    </x-auth-card>
@endsection
