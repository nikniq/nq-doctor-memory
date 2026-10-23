@extends('layouts.app', ['pageTitle' => 'Log in'])

@section('content')
    <x-auth-card title="Log in">
        <x-google-button />

        <form method="POST" action="{{ route('login') }}" class="form">
            @csrf
            <x-field name="email" label="Email" type="email" autocomplete="email" required autofocus />
            <x-field name="password" label="Password" type="password" autocomplete="current-password" required />
            <div class="form__row">
                <label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label>
                <a href="{{ route('password.request') }}">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn--primary">Log in</button>
        </form>

        <x-slot:footer>
            New here? <a href="{{ route('register') }}">Create an account</a>
        </x-slot:footer>
    </x-auth-card>
@endsection
