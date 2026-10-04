<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin SPA shell (single-page app; all state lives client-side and talks to /api/*).
Route::view('/admin', 'admin')->name('admin');

// Named "login" route: the framework's default guest-redirect helper needs it
// (API requests still return JSON 401s — see shouldRenderJsonWhen in bootstrap).
Route::redirect('/login', '/')->name('login');
