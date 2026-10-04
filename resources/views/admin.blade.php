<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') · {{ config('app.name', 'Laravel') }}</title>
    <link rel="stylesheet" href="{{ asset('admin-assets/app.css') }}">
</head>
<body>
    <div id="app" class="app-root">
        <div class="boot-loading">Loading…</div>
    </div>
    <div id="toast-holder" class="toast-holder" aria-live="polite"></div>
    <script src="{{ asset('admin-assets/app.js') }}"></script>
</body>
</html>
