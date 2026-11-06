@extends('layouts.app')

@section('title', 'Sign in · Vendor Onboarding')

@section('content')
    <main class="login">
        <form class="card login-card" method="POST" action="{{ route('login') }}">
            @csrf
            <h1>Vendor Onboarding</h1>
            <p class="muted">Sign in as an Ops Coordinator.</p>

            <label>
                Username
                <input name="username" value="{{ old('username') }}" autocomplete="username" autofocus required>
            </label>
            <label>
                Password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>

            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif

            <button type="submit" class="primary">Sign in</button>

            <p class="hint">
                Demo accounts: <code>linh</code>, <code>huy</code>, <code>mai</code>. Password: <code>password</code>
            </p>
        </form>
    </main>
@endsection
