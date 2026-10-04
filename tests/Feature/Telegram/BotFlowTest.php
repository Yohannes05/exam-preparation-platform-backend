<?php

namespace Tests\Feature\Telegram;

use App\Models\BotSession;
use App\Models\ExamAttempt;
use App\Models\Grade;
use App\Models\Question;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BotFlowTest extends TestCase
{
    use RefreshDatabase;

    protected const CHAT = 777000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        TelegramClient::reset();
    }

    /* ---------------- helpers ---------------- */

    protected function sendUpdate(array $update): TestResponse
    {
        return $this->postJson('/api/telegram/webhook', $update + ['update_id' => random_int(1, 1_000_000)]);
    }

    protected function tap(string $data): TestResponse
    {
        return $this->sendUpdate([
            'callback_query' => [
                'id' => 'cb'.random_int(1, 999999),
                'from' => ['id' => self::CHAT, 'first_name' => 'Almaz', 'username' => 'almaz'],
                'message' => ['chat' => ['id' => self::CHAT, 'type' => 'private']],
                'data' => $data,
            ],
        ]);
    }

    protected function type(string $text): TestResponse
    {
        return $this->sendUpdate([
            'message' => [
                'from' => ['id' => self::CHAT, 'first_name' => 'Almaz', 'username' => 'almaz'],
                'chat' => ['id' => self::CHAT, 'type' => 'private'],
                'text' => $text,
            ],
        ]);
    }

    /** All sent message texts for our chat. */
    protected function sent(): array
    {
        return TelegramClient::messagesFor(self::CHAT);
    }

    protected function lastMessage(): string
    {
        $messages = $this->sent();

        return end($messages) ?: '';
    }

    /** Inline keyboard of the last outgoing sendMessage. */
    protected function lastKeyboard(): array
    {
        $calls = array_values(array_filter(
            TelegramClient::$sent,
            fn ($c) => $c['method'] === 'sendMessage' && $c['payload']['chat_id'] === self::CHAT
        ));

        $last = end($calls);

        return $last['payload']['reply_markup']['inline_keyboard'] ?? [];
    }

    protected function keyboardCallbacks(): array
    {
        return collect($this->lastKeyboard())
            ->flatten(1)
            ->filter(fn ($btn) => isset($btn['callback_data']))
            ->pluck('callback_data')
            ->values()
            ->all();
    }

    protected function botSession(): BotSession
    {
        return BotSession::forChat(self::CHAT);
    }

    /* ---------------- tests ---------------- */

    public function test_webhook_rejects_bad_secret(): void
    {
        config(['telegram.secret' => 'expected-secret']);

        $this->postJson('/api/telegram/webhook', ['update_id' => 1])
            ->assertStatus(403);

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'expected-secret'])
            ->postJson('/api/telegram/webhook', ['update_id' => 1])
            ->assertOk();
    }

    public function test_full_registration_and_navigation_flow(): void
    {
        // /start registers the student and asks for a grade (spec §24).
        $this->type('/start')->assertOk();
        $this->assertStringContainsString('Welcome', $this->lastMessage());
        $this->assertEquals('choose_grade', $this->botSession()->state);

        $student = Student::where('telegram_id', self::CHAT)->first();
        $this->assertNotNull($student);
        $this->assertNull($student->grade_id);

        // Pick Grade 8.
        $g8 = Grade::where('level', 8)->first();
        $this->tap("g:{$g8->id}")->assertOk();
        $this->assertEquals('menu', $this->botSession()->state);
        $this->assertEquals($g8->id, $student->fresh()->grade_id);
        $this->assertStringContainsString("all set for <b>Grade 8</b>", $this->lastMessage());

        // Menu offers everything from the spec (§3).
        $this->assertContains('mode:study', $this->keyboardCallbacks());
        $this->assertContains('mode:practice', $this->keyboardCallbacks());
        $this->assertContains('mode:test', $this->keyboardCallbacks());
        $this->assertContains('mode:mock', $this->keyboardCallbacks());
        $this->assertContains('progress', $this->keyboardCallbacks());
        $this->assertContains('mistakes', $this->keyboardCallbacks());

        // Study: subject → chapter → notes → read a note.
        $this->tap('mode:study');
        $math = Subject::where('grade_id', $g8->id)->where('name', 'Mathematics')->first();
        $this->tap("s:{$math->id}");

        $linear = $math->chapters()->where('title', 'Linear Equations')->first();
        $this->tap("c:{$linear->id}");
        $this->assertEquals('study_notes', $this->botSession()->state);
        $this->assertStringContainsString('Select study notes', $this->lastMessage());

        $noteId = collect($this->keyboardCallbacks())
            ->first(fn ($c) => str_starts_with($c, 'n:'));
        $this->assertNotNull($noteId);

        $this->tap($noteId);
        $this->assertStringContainsString('Linear Equations', $this->lastMessage());
        $this->assertStringContainsString('Isolate the variable', $this->lastMessage());
    }

    public function test_practice_flow_gives_immediate_feedback(): void
    {
        // Register quickly.
        $this->type('/start');
        $g8 = Grade::where('level', 8)->first();
        $this->tap("g:{$g8->id}");

        $math = Subject::where('grade_id', $g8->id)->where('name', 'Mathematics')->first();
        $linear = $math->chapters()->where('title', 'Linear Equations')->first();

        // Practice → subject → chapter.
        $this->tap('mode:practice');
        $this->tap("s:{$math->id}");
        $this->tap("c:{$linear->id}");

        $this->assertEquals('practice', $this->botSession()->state);
        $this->assertStringContainsString('Practice', $this->lastMessage());
        $this->assertStringContainsString('Question (1/', $this->lastMessage());

        // Answer every question correctly (spec §7: immediate feedback + explanation).
        foreach ([0, 1, 2] as $step) {
            $context = $this->botSession()->context;
            $question = Question::with('options')->find($context['qids'][$context['idx']]);
            $correct = $question->options->firstWhere('is_correct', true);

            $this->tap("o:{$correct->id}");

            if ($step === 0) {
                $this->assertStringContainsString('Correct', $this->lastMessage());
                $this->assertNotEmpty($question->explanation);
                $this->assertStringContainsString('💡', $this->lastMessage());
            }

            if ($step < 2) {
                $this->tap('next');
            }
        }

        // Last answer shows the "See results" button → tap next for summary.
        $this->tap('next');
        $this->assertStringContainsString('Practice complete!', $this->lastMessage());
        $this->assertStringContainsString('3/3', $this->lastMessage());

        // Progress now shows answers recorded (spec §10).
        $this->tap('progress');
        $this->assertStringContainsString('Your progress', $this->lastMessage());
        $this->assertStringContainsString('Answered: <b>3</b>', $this->lastMessage());
        $this->assertStringContainsString('Accuracy: <b>100.0%</b>', $this->lastMessage());
    }

    public function test_wrong_answer_lands_in_mistake_review(): void
    {
        $this->type('/start');
        $g8 = Grade::where('level', 8)->first();
        $this->tap("g:{$g8->id}");

        $math = Subject::where('grade_id', $g8->id)->where('name', 'Mathematics')->first();
        $linear = $math->chapters()->where('title', 'Linear Equations')->first();

        $this->tap('mode:practice');
        $this->tap("s:{$math->id}");
        $this->tap("c:{$linear->id}");

        // Answer the first question wrongly.
        $context = $this->botSession()->context;
        $question = Question::with('options')->find($context['qids'][0]);
        $wrong = $question->options->firstWhere('is_correct', false);
        $this->tap("o:{$wrong->id}");

        $this->assertStringContainsString('Not correct', $this->lastMessage());
        $this->assertStringContainsString('Correct answer:', $this->lastMessage());

        $student = Student::where('telegram_id', self::CHAT)->first();
        $this->assertEquals(1, $student->mistakes()->count());

        // My Mistakes serves the missed question for review (spec §11).
        $this->tap('mistakes');
        $this->assertEquals('mistakes', $this->botSession()->state);
        $this->assertStringContainsString('Question (1/1)', $this->lastMessage());

        // Answer it correctly now → mastered.
        $reviewQuestion = Question::with('options')->find($this->botSession()->context['qids'][0]);
        $correct = $reviewQuestion->options->firstWhere('is_correct', true);
        $this->tap("o:{$correct->id}");

        $this->assertStringContainsString('mastered', $this->lastMessage());
        $this->assertTrue($student->mistakes()->first()->is_mastered);
    }

    public function test_chapter_test_scoring_and_result_screen(): void
    {
        $this->type('/start');
        $g8 = Grade::where('level', 8)->first();
        $this->tap("g:{$g8->id}");

        $math = Subject::where('grade_id', $g8->id)->where('name', 'Mathematics')->first();
        $linear = $math->chapters()->where('title', 'Linear Equations')->first();

        // Chapter test navigation: menu → test → subject → chapter → exam list.
        $this->tap('mode:test');
        $this->tap("s:{$math->id}");
        $this->tap("c:{$linear->id}");

        $this->assertEquals('choose_exam', $this->botSession()->state);
        $this->assertStringContainsString('Chapter tests', $this->lastMessage());

        $examId = collect($this->keyboardCallbacks())
            ->first(fn ($c) => str_starts_with($c, 'e:'));
        $this->assertNotNull($examId);

        $this->tap($examId);
        $this->assertEquals('exam', $this->botSession()->state);
        $this->assertStringContainsString('Question <b>1/3</b>', $this->lastMessage());
        $this->assertStringContainsString('left', $this->lastMessage()); // timer shown

        // Answer all three correctly (first two explicitly, third via loop).
        for ($i = 0; $i < 3; $i++) {
            $attempt = ExamAttempt::find($this->botSession()->context['attempt_id']);
            $qid = $attempt->question_ids[$this->botSession()->context['idx']];
            $question = Question::with('options')->find($qid);
            $correct = $question->options->firstWhere('is_correct', true);

            $this->tap("o:{$correct->id}");

            if ($i < 2) {
                $this->assertStringContainsString('Answer saved', $this->lastMessage());
                $this->tap('next');
            }
        }

        // Finalized result screen (spec §8).
        $this->assertStringContainsString('PASSED', $this->lastMessage());
        $this->assertStringContainsString('Score: <b>100%</b>', $this->lastMessage());
        $this->assertContains('retry:'.$this->botSession()->context['exam_id'], $this->keyboardCallbacks());

        $attempt = ExamAttempt::find($this->botSession()->context['attempt_id']);
        $this->assertEquals('completed', $attempt->status);
        $this->assertTrue($attempt->passed);
        $this->assertEquals(100, $attempt->score);
    }

    public function test_mock_exam_and_announcements(): void
    {
        $this->type('/start');
        $g8 = Grade::where('level', 8)->first();
        $this->tap("g:{$g8->id}");

        // No announcements published yet.
        $this->tap('announce');
        $this->assertStringContainsString('No announcements', $this->lastMessage());

        // Admin publishes one.
        \App\Models\Announcement::create([
            'title' => 'Midterm reminder',
            'body' => 'Mocks open on Monday.',
            'audience' => 'all',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->tap('announce');
        $this->assertStringContainsString('Midterm reminder', $this->lastMessage());

        // Mock exam path: menu → mock → physics → chapter → exam.
        $physics = Subject::where('grade_id', $g8->id)->where('name', 'Physics')->first();
        $motion = $physics->chapters()->where('title', 'Motion')->first();

        $this->tap('mode:mock');
        $this->tap("s:{$physics->id}");
        $this->tap("c:{$motion->id}");

        $this->assertStringContainsString('Mock exams', $this->lastMessage());
        $examCb = collect($this->keyboardCallbacks())->first(fn ($c) => str_starts_with($c, 'e:'));
        $this->assertNotNull($examCb);

        $this->tap($examCb);
        $this->assertEquals('exam', $this->botSession()->state);
        $this->assertStringContainsString('Mock Exam #1', $this->lastMessage());
    }

    public function test_unknown_text_returns_help(): void
    {
        $this->type('hello there')->assertOk();
        $this->assertStringContainsString('Main menu', $this->lastMessage());
    }

    public function test_settings_shows_grade_and_language(): void
    {
        $this->type('/start');
        $g6 = Grade::where('level', 6)->first();
        $this->tap("g:{$g6->id}");

        $this->tap('settings');
        $this->assertStringContainsString('Grade 6', $this->lastMessage());
        $this->assertStringContainsString('Amharic', $this->lastMessage());
    }
}
