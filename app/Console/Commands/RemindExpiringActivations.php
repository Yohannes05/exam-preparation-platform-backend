<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class RemindExpiringActivations extends Command
{
    protected $signature = 'telegram:remind-expiring-activations';

    protected $description = 'Remind students whose paid activation ends in three days';

    public function handle(TelegramClient $telegram): int
    {
        $expiryDay = now('Africa/Addis_Ababa')->addDays(3)->startOfDay();
        $windowStart = $expiryDay->copy()->setTimezone('UTC');
        $windowEnd = $expiryDay->copy()->endOfDay()->setTimezone('UTC');

        Student::query()
            ->whereBetween('activated_until', [$windowStart, $windowEnd])
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($students) use ($telegram): void {
                foreach ($students as $student) {
                    $telegram->sendMessage(
                        (int) $student->telegram_id,
                        '⏳ Your activation ends in 3 days ('
                            .e($student->activated_until->timezone('Africa/Addis_Ababa')->format('M j, Y'))
                            .'). Open To Activate to renew and keep unlimited access.',
                        [[['text' => '💰 To Activate', 'callback_data' => 'activate']]]
                    );
                }
            });

        return self::SUCCESS;
    }
}
