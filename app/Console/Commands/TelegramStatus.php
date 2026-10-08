<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramStatus extends Command
{
    protected $signature = 'telegram:status';

    protected $description = 'Check Telegram bot credentials and webhook state without displaying the bot token';

    public function handle(TelegramClient $telegram): int
    {
        if ($telegram->mock()) {
            $this->error('Bot is in mock mode. Set a valid TELEGRAM_BOT_TOKEN and TELEGRAM_MOCK=false.');

            return self::FAILURE;
        }

        try {
            $username = $telegram->getMe()['result']['username'] ?? null;
            if (! $username) {
                $this->error('Telegram did not return a bot username. Check the token and network connection.');

                return self::FAILURE;
            }

            $webhook = $telegram->getWebhookInfo()['result'] ?? [];
            $this->info('Telegram API connection: OK (@'.$username.')');
            $webhookUrl = (string) ($webhook['url'] ?? '');
            $host = $webhookUrl !== '' ? parse_url($webhookUrl, PHP_URL_HOST) : null;
            $this->line('Webhook: '.($webhookUrl === '' ? 'not set (long polling is available)' : 'set'.($host ? ' for '.$host : '')));
            if (! empty($webhook['last_error_message'])) {
                $this->warn('Last webhook error: '.$webhook['last_error_message']);
                if (! empty($webhook['last_error_date'])) {
                    $this->line('Last webhook error time: '.date(DATE_ATOM, (int) $webhook['last_error_date']));
                }
            }
            if (! empty($webhook['pending_update_count'])) {
                $this->warn('Pending updates: '.(int) $webhook['pending_update_count']);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Telegram status check failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
