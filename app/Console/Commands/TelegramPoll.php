<?php

namespace App\Console\Commands;

use App\Services\Telegram\BotHandler;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/**
 * Runs the bot with getUpdates long-polling — useful when there is no
 * publicly reachable URL for a webhook (local development / demos).
 *
 *   php artisan telegram:poll            # run until Ctrl+C
 *   php artisan telegram:poll --once     # process pending updates once
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll
        {--once : Process currently pending updates once and exit}
        {--max-secs=0 : Stop after this many seconds (0 = run forever)}';

    protected $description = 'Run the Telegram bot via long-polling (no public webhook needed)';

    public function handle(TelegramClient $tg, BotHandler $bot): int
    {
        if ($tg->mock()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set — nothing to poll.');

            return self::FAILURE;
        }

        $offset = 0;
        $started = time();
        $maxSecs = (int) $this->option('max-secs');

        $this->info('Polling for updates… (Ctrl+C to stop)');

        do {
            // Telegram can be briefly unreachable — never let one failed poll kill the loop.
            try {
                $updates = $tg->getUpdates($offset, $this->option('once') ? 0 : 50);
            } catch (\Throwable $e) {
                report($e);
                $this->warn('  network error, retrying in 5s: '.$e->getMessage());
                sleep(5);
                continue;
            }

            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;

                try {
                    $bot->handle($update);
                    $kind = isset($update['message']) ? 'message' : 'callback';
                    $this->line(sprintf('  handled update %d (%s)', $update['update_id'], $kind));
                } catch (\Throwable $e) {
                    report($e);
                    $this->error('  failed: '.$e->getMessage());
                }
            }
        } while ($this->keepGoing($maxSecs, $started));

        $this->info('Stopped.');

        return self::SUCCESS;
    }

    protected function keepGoing(int $maxSecs, int $started): bool
    {
        if ($this->option('once')) {
            return false;
        }

        return $maxSecs === 0 || (time() - $started) < $maxSecs;
    }
}
