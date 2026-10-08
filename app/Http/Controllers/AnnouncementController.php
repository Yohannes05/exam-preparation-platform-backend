<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Student;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function publish(Request $request, TelegramClient $telegram): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'audience' => 'required|in:all,grade,activated',
            'grade_id' => 'required_if:audience,grade|nullable|exists:grades,id',
            'send_as_message' => 'boolean',
        ]);

        $sendAsMessage = (bool) ($data['send_as_message'] ?? false);
        if ($sendAsMessage && $telegram->mock()) {
            return response()->json([
                'message' => 'Configure TELEGRAM_BOT_TOKEN before sending announcements to students.',
            ], 422);
        }

        $announcement = Announcement::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'grade_id' => $data['audience'] === 'grade' ? $data['grade_id'] : null,
            'is_published' => true,
            'published_at' => now(),
            'created_by' => $request->user()?->id,
        ]);

        $sent = 0;
        $failed = 0;
        if ($sendAsMessage) {
            $students = Student::query()
                ->where('is_active', true)
                ->when($data['audience'] === 'grade', fn ($query) => $query->where('grade_id', $data['grade_id']))
                ->when($data['audience'] === 'activated', fn ($query) => $query->where('activated_until', '>', now()))
                ->get(['id', 'telegram_id']);

            foreach ($students as $student) {
                $failedBefore = count(TelegramClient::$failed);
                $telegram->sendMessage(
                    (int) $student->telegram_id,
                    '📢 <b>'.e($announcement->title)."</b>\n\n".e($announcement->body)
                );
                if (count(TelegramClient::$failed) > $failedBefore) $failed++;
                else $sent++;
            }
        }

        return response()->json([
            'announcement' => $announcement->load('grade'),
            'messages_sent' => $sent,
            'messages_failed' => $failed,
        ], 201);
    }
}
