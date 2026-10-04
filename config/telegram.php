<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot
    |--------------------------------------------------------------------------
    | Token is read from the environment (TELEGRAM_BOT_TOKEN). When the token
    | is empty the bot runs in "mock" mode: outgoing calls are recorded in
    | memory instead of hitting api.telegram.org (used by the test suite).
    */

    'token' => env('TELEGRAM_BOT_TOKEN'),
    'secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),

    'mock' => env('TELEGRAM_MOCK', false),

    'page_size' => 5, // questions per message chunk / menu page
];
