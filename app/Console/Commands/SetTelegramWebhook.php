<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {url? : Public HTTPS webhook URL} {--drop-pending : Discard updates waiting at Telegram}';

    protected $description = 'Register the Telegram webhook for this Laravel application';

    public function handle(TelegramClient $telegram): int
    {
        if ($telegram->mock()) {
            $this->error('Set TELEGRAM_BOT_TOKEN before configuring a webhook.');

            return self::FAILURE;
        }

        if (! config('telegram.secret')) {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET to a random secret before configuring the production webhook.');
            return self::FAILURE;
        }

        $url = (string) ($this->argument('url') ?: rtrim((string) config('app.url'), '/').'/api/telegram/webhook');
        if (! str_starts_with($url, 'https://')) {
            $this->error('Telegram webhooks require a public HTTPS URL. For local development, use telegram:delete-webhook and telegram:poll.');

            return self::FAILURE;
        }

        try {
            $result = $telegram->setWebhook($url, config('telegram.secret'), (bool) $this->option('drop-pending'));
            $this->info($result['description'] ?? 'Telegram webhook configured successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Could not configure Telegram webhook: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
