<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CrudController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API (Sanctum token auth)
|--------------------------------------------------------------------------
| Content resources (grades, subjects, chapters, topics, notes, questions,
| exams, announcements) are served by the config-driven CrudController.
*/

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{id}', [StudentController::class, 'show']);
    Route::patch('/students/{id}', [StudentController::class, 'update']);

    Route::get('/results', [ResultController::class, 'index']);
    Route::get('/results/{id}', [ResultController::class, 'show']);

    // Content resources share the config-driven CRUD controller:
    // GET/POST /api/v1/{resource}, GET/PUT/DELETE /api/v1/{resource}/{id}
    $resourcePattern = implode('|', array_keys(config('platform')));

    Route::prefix('v1')->group(function () use ($resourcePattern) {
        Route::get('{resource}', [CrudController::class, 'index'])->where('resource', $resourcePattern);
        Route::post('{resource}', [CrudController::class, 'store'])->where('resource', $resourcePattern);
        Route::get('{resource}/{id}', [CrudController::class, 'show'])
            ->where('resource', $resourcePattern)->whereNumber('id');
        Route::put('{resource}/{id}', [CrudController::class, 'update'])
            ->where('resource', $resourcePattern)->whereNumber('id');
        Route::patch('{resource}/{id}', [CrudController::class, 'update'])
            ->where('resource', $resourcePattern)->whereNumber('id');
        Route::delete('{resource}/{id}', [CrudController::class, 'destroy'])
            ->where('resource', $resourcePattern)->whereNumber('id');
    });
});

/*
|--------------------------------------------------------------------------
| Telegram bot webhook (spec §3)
|--------------------------------------------------------------------------
| Secured with X-Telegram-Bot-Api-Secret-Token when TELEGRAM_WEBHOOK_SECRET
| is set. Stateless HTTP: all conversation state lives in bot_sessions.
*/

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
