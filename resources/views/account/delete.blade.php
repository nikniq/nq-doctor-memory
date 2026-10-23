@extends('layouts.app', ['pageTitle' => 'Delete your account', 'noindex' => true])

@section('content')
    <x-auth-card title="Delete your account?">
        <p class="panel__intro">
            This removes the account for <strong>{{ $user->email }}</strong>, every game and top score it recorded,
            and signs you out on every device. It cannot be undone.
        </p>

        <form method="POST" action="{{ $action }}" class="form">
            @csrf
            <button type="submit" class="btn btn--danger">Yes, delete my account</button>
        </form>

        <x-slot:footer>
            <a href="{{ route('account.settings') }}">Keep my account</a>
        </x-slot:footer>
    </x-auth-card>
@endsection
