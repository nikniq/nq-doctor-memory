@extends('layouts.app', ['pageTitle' => 'Choose a new password', 'noindex' => true])

@section('content')
    <x-auth-card title="Choose a new password">
        <form method="POST" action="{{ route('password.store') }}" class="form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-field name="email" label="Email" type="email" :value="$email" autocomplete="email" required />
            <x-field name="password" label="New password" type="password" autocomplete="new-password" required autofocus />
            <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />
            <button type="submit" class="btn btn--primary">Save password</button>
        </form>
    </x-auth-card>
@endsection
