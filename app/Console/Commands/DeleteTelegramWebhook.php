<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class DeleteTelegramWebhook extends Command
{
    protected $signature = 'telegram:delete-webhook {--drop-pending : Discard updates waiting at Telegram}';

    protected $description = 'Remove the Telegram webhook so the bot can use long polling';

    public function handle(TelegramClient $telegram): int
    {
        if ($telegram->mock()) {
            $this->error('Set TELEGRAM_BOT_TOKEN before changing the Telegram webhook.');

            return self::FAILURE;
        }

        try {
            $result = $telegram->deleteWebhook((bool) $this->option('drop-pending'));
            $this->info($result['description'] ?? 'Telegram webhook removed.');
            $this->line('You can now run php artisan telegram:poll for local testing.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Could not remove Telegram webhook: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
