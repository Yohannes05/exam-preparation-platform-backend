<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\SessionController;

/*
|--------------------------------------------------------------------------
| Web Routes — Laravel Admin SPA
|--------------------------------------------------------------------------
|
| The admin UI uses Laravel's session login and Blade pages. The content pages
| (grades, subjects, chapters, …) render a generic CRUD shell whose requests
| use the same-origin session cookie.
*/

// --------------------------------------------------------------------------
// Public / auth
// --------------------------------------------------------------------------

Route::get('/login', function () {
    if (auth()->check()) {
        return redirect()->route('admin.dashboard');
    }

    return view('admin.login');
})->name('login');

Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:5,1');
Route::post('/logout', [SessionController::class, 'logout'])->name('logout');

// --------------------------------------------------------------------------
// Authenticated admin pages.
// --------------------------------------------------------------------------

Route::middleware('auth')->group(function () {
    // Keep the legacy /admin URL on the same session-authenticated dashboard
    // as the login form. The old SPA shell used a separate localStorage token
    // and rendered its own login screen over this session-authenticated flow.
    Route::redirect('/admin', '/admin/dashboard')->name('admin');

    // Root redirects to the dashboard.
    Route::get('/', function () {
        return redirect()->route('admin');
    });

    // Resource pages (generic CRUD shell + JS drives the table/CUD)
    Route::get('/admin/dashboard', function () {
        return view('admin.dashboard');
    })->name('admin.dashboard');

    Route::get('/admin/students', function () {
        return view('admin.students');
    })->name('admin.students');

    Route::get('/admin/results', function () {
        return view('admin.results');
    })->name('admin.results');

    Route::get('/admin/payments', function () {
        return view('admin.payments');
    })->name('admin.payments');

    Route::get('/admin/import-questions', function () {
        return view('admin.import');
    })->name('admin.import');

    // Generic CRUD for content resources (reference data + blade shell)
    Route::get('/admin/grades', [ContentController::class, 'show'])->name('admin.grades');
    Route::get('/admin/subjects', [ContentController::class, 'show'])->name('admin.subjects');
    Route::get('/admin/chapters', [ContentController::class, 'show'])->name('admin.chapters');
    Route::get('/admin/topics', [ContentController::class, 'show'])->name('admin.topics');
    Route::get('/admin/notes', [ContentController::class, 'show'])->name('admin.notes');
    Route::get('/admin/questions', [ContentController::class, 'show'])->name('admin.questions');
    Route::get('/admin/exams', [ContentController::class, 'show'])->name('admin.exams');
    Route::get('/admin/announcements', [ContentController::class, 'show'])->name('admin.announcements');
});

// Unknown paths return to the authenticated admin entry point.
Route::fallback(function () {
    return redirect()->route('admin');
});
