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
    'username' => env('TELEGRAM_BOT_USERNAME', 'bot'),
    'secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'channel_username' => env('TELEGRAM_CHANNEL_USERNAME', 'exitexamprep'),
    'channel_url' => env('TELEGRAM_CHANNEL_URL', 'https://t.me/'.ltrim(env('TELEGRAM_CHANNEL_USERNAME', 'exitexamprep'), '@')),
    'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID') ? (int) env('TELEGRAM_ADMIN_CHAT_ID') : null,
    'payment' => [
        'price' => (int) env('TELEGRAM_ACTIVATION_PRICE', 200),
        'telebirr_number' => env('TELEGRAM_TELEBIRR_NUMBER'),
        'cbe_account' => env('TELEGRAM_CBE_ACCOUNT'),
        'cbe_account_name' => env('TELEGRAM_CBE_ACCOUNT_NAME'),
        'admin_username' => env('TELEGRAM_SUPPORT_USERNAME'),
    ],
    'free_daily_limit' => (int) env('TELEGRAM_FREE_DAILY_LIMIT', 10),

    'mock' => env('TELEGRAM_MOCK', false),

    'page_size' => 5, // questions per message chunk / menu page
];
