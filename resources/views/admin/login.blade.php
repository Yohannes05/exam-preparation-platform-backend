<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · {{ config('app.name', 'Exam Prep') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="logo">
            <svg width="40" height="40" viewBox="0 0 44 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="44" height="40" rx="8" fill="#2563eb"/>
                <path d="M14 14 L22 14 L22 22 L14 22 Z" fill="#fff"/>
                <rect x="16" y="16" width="4" height="8" rx="1.5" fill="#2563eb"/>
                <rect x="22" y="14" width="4" height="12" rx="1.5" fill="#2563eb"/>
                <rect x="28" y="16" width="4" height="8" rx="1.5" fill="#2563eb"/>
                <path d="M22 10 L22 14" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
                <path d="M14 18 L22 22" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </div>
        <h1>Exam Prep Admin</h1>
        <p class="sub">Sign in to manage content and students</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" style="width:100%">Sign in</button>
            </div>
        </form>
    </div>
</div>
<div class="toast-holder" aria-live="polite"></div>
</body>
</html>
