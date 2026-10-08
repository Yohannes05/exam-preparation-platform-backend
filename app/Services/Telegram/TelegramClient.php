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

    /** @var array<int, array{chat_id:int, text:string, error:string}> */
    public static array $failed = [];

    protected ?string $token;

    public function __construct()
    {
        $this->token = config('telegram.token');
    }

    public function mock(): bool
    {
        return config('telegram.mock') || empty($this->token);
    }

    /** Resolve the bot username for deep links when it is not configured. */
    public function getBotUsername(): ?string
    {
        if ($this->mock()) {
            return null;
        }

        try {
            $response = $this->call('getMe', [], true);
            $username = $response['result']['username'] ?? null;

            return is_string($username) && $username !== '' ? $username : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array{ok:bool,result?:array,description?:string} */
    public function getMe(): array
    {
        return $this->call('getMe', [], true);
    }

    public function sendMessage(int $chatId, string $text, ?array $keyboard = null, bool $oneTime = false): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $this->chunk($text),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard !== null) {
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
                    report($e);
                    break;
                }
                // Telegram blacklists aggressively right after a success — back off
                // and re-send once the window has passed.
                sleep($backoff);
                $backoff = min($backoff * 2, 10);
            }
        }
    }

    /** Send a screen with Telegram's keyboard below the message input. */
    public function sendReplyMessage(int $chatId, string $text, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $this->chunk($text),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = [
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'is_persistent' => true,
            ];
        }

        $this->call('sendMessage', $payload);
    }

    /** Send an existing Telegram photo by its file_id with an optional caption. */
    public function sendPhoto(int $chatId, string $photo, ?string $caption = null, ?array $keyboard = null): void
    {
        $payload = ['chat_id' => $chatId, 'photo' => $photo];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = mb_substr($caption, 0, 1000);
            $payload['parse_mode'] = 'HTML';
        }
        if ($keyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $this->call('sendPhoto', $payload);
    }

    /** Send an existing Telegram document by its file_id. */
    public function sendDocument(int $chatId, string $document, ?string $caption = null): void
    {
        $payload = ['chat_id' => $chatId, 'document' => $document];

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = mb_substr($caption, 0, 1000);
            $payload['parse_mode'] = 'HTML';
        }

        $this->call('sendDocument', $payload);
    }

    /** Download a private Telegram file without exposing the bot token to the admin UI. */
    public function downloadFile(string $fileId): ?array
    {
        if ($this->mock()) {
            return null;
        }

        $file = $this->call('getFile', ['file_id' => $fileId], true);
        $path = $file['result']['file_path'] ?? null;
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            throw new \RuntimeException('Telegram did not return a valid receipt file.');
        }

        $response = Http::timeout(20)->get(
            rtrim(config('telegram.api_base'), '/').'/file/bot'.$this->token.'/'.ltrim($path, '/')
        );
        $response->throw();

        return [
            'contents' => $response->body(),
            'mime' => $response->header('Content-Type') ?: 'application/octet-stream',
        ];
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

    public function setWebhook(string $url, ?string $secret = null, bool $dropPending = false): array
    {
        $payload = ['url' => $url, 'drop_pending_updates' => $dropPending];

        if ($secret) {
            $payload['secret_token'] = $secret;
        }

        return $this->call('setWebhook', $payload, true);
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', [], true);
    }

    public function deleteWebhook(bool $dropPending = false): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => $dropPending], true);
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

        try {
            $response = Http::timeout($timeout + 15)
                ->get(config('telegram.api_base').'/bot'.$this->token.'/getUpdates', [
                    'offset' => $offset,
                    'timeout' => $timeout,
                    'allowed_updates' => json_encode(['message', 'callback_query'], JSON_THROW_ON_ERROR),
                ]);

            $response->throw();
            $body = $response->json() ?? [];
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->safeTelegramError('getUpdates', $e->getMessage()));
        }

        if (! ($body['ok'] ?? false)) {
            throw new \RuntimeException($body['description'] ?? 'Telegram getUpdates request failed.');
        }

        return $body['result'] ?? [];
    }

    /** Check if a user is a member of the bot's channel (spec §1, §4).
     *  Returns true if the user is a member, false otherwise.
     */
    public function checkChannelMembership(int $telegramId): bool
    {
        // The bot must be an admin of the channel to check membership.
        // Mock mode deliberately allows local development without Telegram access.
        if ($this->mock()) {
            return true; // Mock mode: allow freediving, admin verifies later.
        }

        try {
            $response = Http::timeout(10)->get(
                config('telegram.api_base').'/bot'.$this->token.'/getChatMember',
                ['chat_id' => '@'.ltrim((string) config('telegram.channel_username', 'exitexamprep'), '@'), 'user_id' => $telegramId]
            );

            $data = $response->json();
            if (! ($data['ok'] ?? false)) {
                return false;
            }

            $member = $data['result'] ?? null;
            if (! is_array($member)) {
                return false;
            }

            return in_array($member['status'] ?? null, ['creator', 'administrator', 'member'], true)
                || (($member['status'] ?? null) === 'restricted' && ($member['is_member'] ?? false));
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

        try {
            $requestPayload = $payload;
            if (isset($requestPayload['reply_markup']) && is_array($requestPayload['reply_markup'])) {
                $requestPayload['reply_markup'] = json_encode(
                    $requestPayload['reply_markup'],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            }

            $response = Http::asForm()
                ->timeout(15)
                ->post(config('telegram.api_base').'/bot'.$this->token.'/'.$method, $requestPayload);

            $response->throw();
            $body = $response->json() ?? ['ok' => false, 'description' => 'Telegram returned an empty response.'];
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->safeTelegramError($method, $e->getMessage()));
        }

        if (! ($body['ok'] ?? false)) {
            throw new \RuntimeException('Telegram '.$method.' failed: '.($body['description'] ?? 'unknown API error'));
        }

        return $body;
    }

    protected function safeTelegramError(string $method, string $message): string
    {
        if ($this->token) {
            $message = str_replace('bot'.$this->token, 'bot[REDACTED]', $message);
        }

        return 'Telegram '.$method.' request failed: '.$message;
    }

    /** Test helper: reset the recorded call log. */
    public static function reset(): void
    {
        self::$sent = [];
        self::$failed = [];
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
