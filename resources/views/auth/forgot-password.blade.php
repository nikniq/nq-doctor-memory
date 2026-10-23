@extends('layouts.app', ['pageTitle' => 'Reset password'])

@section('content')
    <x-auth-card title="Reset your password" intro="Enter your email and we'll send you a link to choose a new password. This also works if you signed up with Google and want a password too.">
        <form method="POST" action="{{ route('password.email') }}" class="form">
            @csrf
            <x-field name="email" label="Email" type="email" autocomplete="email" required autofocus />
            <button type="submit" class="btn btn--primary">Email me a reset link</button>
        </form>

        <x-slot:footer>
            <a href="{{ route('login') }}">Back to log in</a>
        </x-slot:footer>
    </x-auth-card>
@endsection
