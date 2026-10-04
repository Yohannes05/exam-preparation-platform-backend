<?php

namespace App\Http\Controllers;

use App\Services\Telegram\BotHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    /**
     * POST /api/telegram/webhook
     *
     * Verifies the Telegram secret token header, then hands the update to the
     * conversation state machine (BotHandler).
     */
    public function handle(Request $request, BotHandler $bot): JsonResponse
    {
        $secret = config('telegram.secret');

        if ($secret) {
            abort_unless(
                $request->header('X-Telegram-Bot-Api-Secret-Token') === $secret,
                403,
                'Invalid webhook secret.'
            );
        }

        abort_unless($request->has('update_id'), 422, 'Not a Telegram update.');

        $bot->handle($request->all());

        return response()->json(['ok' => true]);
    }
}
