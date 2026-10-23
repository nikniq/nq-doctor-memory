@extends('layouts.app', ['pageTitle' => 'Register', 'noindex' => true])

@section('content')
    <x-auth-card title="Create an account" intro="Save every game you finish, track your turns and appear in the player rankings.">
        <x-google-button>Sign up with Google</x-google-button>

        <form method="POST" action="{{ route('register') }}" class="form">
            @csrf
            <x-field name="name" label="Player name" maxlength="24" autocomplete="nickname" required autofocus />
            <x-field name="email" label="Email" type="email" autocomplete="email" required />
            <x-field name="password" label="Password" type="password" autocomplete="new-password" required />
            <x-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />
            <p class="form__hint">We'll email you a link to confirm your address before your games count.</p>
            <button type="submit" class="btn btn--primary">Register</button>
        </form>

        <p class="form__hint form__hint--center"><a href="{{ route('privacy') }}">How we use your data</a></p>

        <x-slot:footer>
            Already have an account? <a href="{{ route('login') }}">Log in</a>
        </x-slot:footer>
    </x-auth-card>
@endsection
