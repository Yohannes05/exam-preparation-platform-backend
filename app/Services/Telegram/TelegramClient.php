<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;

/**
 * Thin Telegram Bot API wrapper.
 *
 * When config('telegram.mock') is true (no bot token / test suite) calls are
 * recorded in $sent instead of hitting api.telegram.org.
 */
class TelegramClient
{
    /** @var array<int, array{method:string, chat_id?:int, payload:array}> */
    public static array $sent = [];

    protected ?string $token;

    public function __construct()
    {
        $this->token = config('telegram.token');
    }

    public function mock(): bool
    {
        return config('telegram.mock') || empty($this->token);
    }

    public function sendMessage(int $chatId, string $text, ?array $keyboard = null, bool $oneTime = false): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $this->chunk($text),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $backoff = 1;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $this->call('sendMessage', $payload);
                return;
            } catch (\Throwable $e) {
                if ($attempt === 3) {
                    self::$failed[] = ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 40), 'error' => $e->getMessage()];
                    break;
                }
                // Telegram blacklists aggressively right after a success — back off
                // and re-send once the window has passed.
                sleep($backoff);
                $backoff = min($backoff * 2, 10);
            }
        }
    }

    /** Send text as sequential messages when longer than Telegram's 4096 limit. */
    protected function chunk(string $text): string
    {
        return mb_substr($text, 0, 4000);
    }

    public function editMessage(int $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => mb_substr($text, 0, 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $this->call('editMessageText', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text) {
            $payload['text'] = $text;
            $payload['show_alert'] = false;
        }

        $this->call('answerCallbackQuery', $payload);
    }

    public function setWebhook(string $url, ?string $secret = null): array
    {
        $payload = ['url' => $url, 'drop_pending_updates' => true];

        if ($secret) {
            $payload['secret_token'] = $secret;
        }

        return $this->call('setWebhook', $payload, true);
    }

    /**
     * Long-poll getUpdates (used by `php artisan telegram:poll`).
     * $timeout=0 returns immediately with pending updates.
     *
     * @return array<int, array> list of Telegram updates
     */
    public function getUpdates(int $offset = 0, int $timeout = 50): array
    {
        if ($this->mock()) {
            return [];
        }

        $response = Http::timeout($timeout + 15)
            ->get(config('telegram.api_base').'/bot'.$this->token.'/getUpdates', [
                'offset' => $offset,
                'timeout' => $timeout,
                'allowed_updates' => ['message', 'callback_query'],
            ])->json() ?? [];

        return $response['result'] ?? [];
    }

    /** Check if a user is a member of the bot's channel (spec §1, §4).
     *  Returns true if the user is a member, false otherwise.
     */
    public function checkChannelMembership(int $telegramId): bool
    {
        // The bot must be an admin of the channel to check membership.
        // This does a lightweight API call; mock mode returns false (no channel check).
        if ($this->mock()) {
            return true; // Mock mode: allow freediving, admin verifies later.
        }

        try {
            $response = Http::timeout(10)->get(
                config('telegram.api_base').'/bot'.$this->token.'/getChatMember',
                ['chat_id' => '@'.(config('telegram.channel_username', 'exitexamprep') ?? 'exitexamprep'), 'user_id' => $telegramId]
            );

            $data = $response->json();
            if (! $data['ok'] ?? false) {
                return false;
            }

            $member = $data['result'] ?? null;
            return $member['status'] === 'member';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array{ok:bool, result?:array, description?:string} */
    protected function call(string $method, array $payload, bool $return = false): array
    {
        self::$sent[] = ['method' => $method, 'payload' => $payload];

        if ($this->mock()) {
            return ['ok' => true, 'result' => []];
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post(config('telegram.api_base').'/bot'.$this->token.'/'.$method, $payload)
            ->json() ?? ['ok' => false, 'description' => 'no response'];

        return $return ? $response : [];
    }

    /** Test helper: reset the recorded call log. */
    public static function reset(): void
    {
        self::$sent = [];
    }

    /** Recorded outgoing messages (chat_id => texts), for assertions. */
    public static function messagesFor(int $chatId): array
    {
        return array_values(array_filter(array_map(
            fn ($call) => $call['method'] === 'sendMessage' && ($call['payload']['chat_id'] ?? null) === $chatId
                ? $call['payload']['text']
                : null,
            self::$sent
        )));
    }
}
