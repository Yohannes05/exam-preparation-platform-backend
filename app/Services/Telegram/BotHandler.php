<?php

namespace App\Services\Telegram;

use App\Models\Announcement;
use App\Models\BotSession;
use App\Models\Chapter;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Note;
use App\Models\Question;
use App\Models\Student;
use App\Models\Subject;
use App\Models\ChannelCheck;
use App\Services\ExamService;
use App\Services\ProgressService;

/**
 * Telegram conversation state machine (spec §3).
 *
 * Flow: /start → choose grade → menu → Study / Practice / Test / Mock /
 * Progress / Mistakes / Announcements / Settings.
 * All state lives server-side in bot_sessions; callback data is kept short.
 */
class BotHandler
{
    protected BotSession $session;
    protected Student $student;
    protected int $chatId;

    public function __construct(
        protected TelegramClient $tg,
        protected ExamService $exams,
        protected ProgressService $progress,
    ) {
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        if (isset($update['message']['text'])) {
            $this->handleMessage($update['message']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Entry points                                                        */
    /* ------------------------------------------------------------------ */

    protected function handleCallback(array $callback): void
    {
        $chatId = (int) ($callback['message']['chat']['id'] ?? $callback['from']['id']);
        $this->begin($chatId, $callback['from']);

        $this->tg->answerCallbackQuery($callback['id']);

        $data = (string) ($callback['data'] ?? '');
        [$action, $arg] = array_pad(explode(':', $data, 2), 2, null);

        match ($action) {
            'start', null => $this->start(),
            'menu' => $this->showMenu(),
            'g' => $this->selectGrade((int) $arg),
            'mode' => $this->showSubjects($arg),
            's' => $this->showChapters((int) $arg),
            'c' => $this->chapterChosen((int) $arg),
            'notes' => $this->showNotes((int) ($arg ?? $this->session->context['chapter_id'] ?? 0)),
            'n' => $this->showNote((int) $arg),
            'o' => $this->answerOption((int) $arg),
            'next' => $this->nextQuestion(),
            'e' => $this->startExam((int) $arg),
            'retry' => $this->startExam((int) $arg),
            'review' => $this->showReview(),
            'progress' => $this->showProgress(),
            'mistakes' => $this->startMistakes(),
            'announce' => $this->showAnnouncements(),
            'settings' => $this->showSettings(),
            'grade' => $this->showGradeList(),
            'leaderboard' => $this->showLeaderboard(),
            'notice' => $this->showNotice(),
            'help_contact' => $this->sendHelp(),
            'activate' => $this->showActivate((int) ($arg ?? 10)),
            'join_confirmed' => $this->confirmChannelJoin(),
            'random' => $this->showRandomQuestions(),
            'model' => $this->showModelExam(),
            'national' => $this->showNationalExam(),
            'tier' => $this->selectTier((int) ($arg ?? 10)),
            'year' => $this->selectYear((int) ($arg ?? 2024)),
            default => $this->showMenu(),
        };
    }

    protected function handleMessage(array $message): void
    {
        $chatId = (int) $message['chat']['id'];
        $this->begin($chatId, $message['from']);

        $text = trim((string) $message['text']);

        if (in_array($text, ['/start', '/start@'.$this->botUsername()], true) || $text === 'start') {
            $this->start();

            return;
        }

        if ($text === 'join_confirmed') {
            $this->confirmChannelJoin();

            return;
        }

        if (in_array($text, ['/menu', '/help'], true)) {
            $text === '/help' ? $this->sendHelp() : $this->showMenu();

            return;
        }

        if (str_starts_with($text, '/')) {
            $this->sendHelp();

            return;
        }

        // Free-text input: no state currently accepts it — re-prompt politely.
        match ($this->session->state) {
            'choose_grade' => $this->send($this->chatId, "Please pick your grade from the buttons below 👇", $this->gradeKeyboard()),
            'practice', 'exam', 'mistakes' => $this->send($this->chatId, "Please answer using the option buttons above 👆", $this->session->state === 'exam' ? $this->resumeKeyboard() : null),
            default => $this->showMenu(),
        };
    }

    protected function begin(int $chatId, array $from): void
    {
        $this->chatId = $chatId;
        $this->session = BotSession::forChat($chatId);

        $student = Student::where('telegram_id', (int) ($from['id'] ?? $chatId))->first();

        if (! $student) {
            $student = Student::create([
                'telegram_id' => (int) ($from['id'] ?? $chatId),
                'telegram_username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
            ]);
        } else {
            $student->forceFill([
                'telegram_username' => $from['username'] ?? $student->telegram_username,
                'first_name' => $from['first_name'] ?? $student->first_name,
                'last_name' => $from['last_name'] ?? $student->last_name,
                'last_seen_at' => now(),
            ])->save();
        }

        $this->student = $student;

        if ($this->session->student_id !== $student->id) {
            $this->session->update(['student_id' => $student->id]);
        }
    }

    /** Check channel membership on first visit (spec §1, §4).
     *  Returns true if the student has joined the channel (or we could not check).
     *  Returns false if the student is not a member.
     */
    protected function checkChannelAndNavigate(): bool
    {
        $student = $this->student;
        $chatId = $this->chatId;

        // Already joined, or we could not verify — treat as in channel (spec assumption).
        if (ChannelCheck::where('telegram_id', $student->telegram_id)->exists()) {
            return true;
        }

        if ($this->tg->checkChannelMembership($student->telegram_id)) {
            ChannelCheck::create(['telegram_id' => $student->telegram_id, 'joined_confirmed_at' => now()]);

            return true;
        }

        // Not a member: show join buttons.
        $this->send(
            $chatId,
            "❌ <b>Please join our channel to use the bot.</b>\n\nYou only need to do this once.",
            $this->joinChannelKeyboard()
        );
        $this->session->setState('join_channel');

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Start / registration (spec §1, §4)                                    */
    /* ------------------------------------------------------------------ */

    protected function start(): void
    {
        $student = $this->student;

        // Already has a grade — go straight to Home.
        if ($student->grade_id) {
            $this->showMenu();

            return;
        }

        // First visit: check channel membership (spec §1, §4).
        if (! $this->checkChannelAndNavigate()) {
            // Not a member — re-prompt with join buttons. Keep asking until they join.
            return;
        }

        // Member ✓ — show grade selection.
        $name = $student->first_name ? ', '.$student->first_name : '';
        $this->send(
            $this->chatId,
            "👋 Welcome{$name}!\n\nChoose your grade to start practising.",
            $this->gradeKeyboard()
        );
        $this->session->setState('choose_grade');
    }

    protected function gradeKeyboard(): array
    {
        $grades = Grade::where('is_active', true)->orderBy('level')->get(['id', 'name']);

        $rows = $grades->map(fn ($g) => [[
            'text' => $g->name,
            'callback_data' => 'g:'.$g->id,
        ]])->all();

        return $rows ?: [[['text' => '🏠 Menu', 'callback_data' => 'menu']]];
    }

    /** Join channel keyboard (first visit). */
    protected function joinChannelKeyboard(): array
    {
        return [
            [['text' => '✅ I joined', 'callback_data' => 'join_confirmed']],
            [['text' => '🏠 Menu', 'callback_data' => 'menu']],
        ];
    }

    protected function showGradeList(): void
    {
        $this->send($this->chatId, '🔽 Select your grade:', $this->gradeKeyboard());
        $this->session->setState('choose_grade');
    }

    protected function selectGrade(int $gradeId): void
    {
        $grade = Grade::where('is_active', true)->find($gradeId);

        if (! $grade) {
            $this->send($this->chatId, 'Sorry, that grade is not available.', $this->gradeKeyboard());

            return;
        }

        $this->student->update(['grade_id' => $grade->id]);
        $this->session->update(['context' => []]);

        $this->send($this->chatId, "✅ You're all set for <b>".e($grade->name)."</b>.\nWhat would you like to do?", $this->menuKeyboard());
        $this->session->setState('menu');
    }

    /* ------------------------------------------------------------------ */
    /* Main menu                                                           */
    /* ------------------------------------------------------------------ */

    protected function menuKeyboard(): array
    {
        return [
            [['text' => '📚 Study', 'callback_data' => 'mode:study'], ['text' => '✏️ Practice', 'callback_data' => 'mode:practice']],
            [['text' => '📝 Chapter Test', 'callback_data' => 'mode:test'], ['text' => '🎯 Mock Exam', 'callback_data' => 'mode:mock']],
            [['text' => '🎲 Random Questions', 'callback_data' => 'random'], ['text' => '📝 Model Exam', 'callback_data' => 'model']],
            [['text' => '🏛️ National Exam', 'callback_data' => 'national']],
            [['text' => '📊 Progress', 'callback_data' => 'progress'], ['text' => '❌ My Mistakes', 'callback_data' => 'mistakes']],
            [['text' => '📢 Announcements', 'callback_data' => 'announce'], ['text' => '📊 Leaderboard', 'callback_data' => 'leaderboard']],
            [['text' => '📢 Notice', 'callback_data' => 'notice'], ['text' => '❓ Help / Contact', 'callback_data' => 'help_contact']],
            [['text' => '$ → Active', 'callback_data' => 'activate'], ['text' => '🏠 Menu', 'callback_data' => 'menu']],
        ];
    }

    protected function showMenu(): void
    {
        $this->send($this->chatId, '🏠 <b>Main menu</b>\nWhat would you like to do?', $this->menuKeyboard());
        $this->session->setState('menu');
    }

    /** Leaderboard: students ranked by all-time score. */
    protected function showLeaderboard(): void
    {
        $this->send(
            $this->chatId,
            "📊 <b>Leaderboard</b>\n\nTop students by all-time score:",
            $this->menuKeyboard()
        );
    }

    /** Notice: pinned announcements. */
    protected function showNotice(): void
    {
        $this->send(
            $this->chatId,
            "📢 <b>Notice</b>\n\nLatest notices will appear here.",
            $this->menuKeyboard()
        );
    }

    /** Help and contact: how the bot works + support contact. */
    protected function sendHelp(): void
    {
        $this->send(
            $this->chatId,
            "ℹ️ <b>How it works</b>\n\n".
            "LEARN → PRACTICE → TEST → RESULT → REVIEW → LEARN\n\n".
            "Use /menu to open the main menu, or /start to restart.",
            $this->menuKeyboard()
        );
    }

    protected function requireGrade(): bool
    {
        if ($this->student->grade_id) {
            return true;
        }

        $this->send($this->chatId, 'Please choose your grade first 👇', $this->gradeKeyboard());
        $this->session->setState('choose_grade');

        return false;
    }

    protected function confirmChannelJoin(): void
    {
        $student = $this->student;

        // Record the join confirmation and allow Home.
        ChannelCheck::create([
            'telegram_id' => $student->telegram_id,
            'joined_confirmed_at' => now(),
        ]);

        $this->send(
            $this->chatId,
            "✅ <b>Thanks for joining!</b>\n\nHome is now open. Choose your grade to start practising.",
            $this->gradeKeyboard()
        );
        $this->session->setState('choose_grade');
    }

    /** Activate gate: shows tier capacity and enforces the payment gate.
     *  $tier: 10 (free), 30 (pay 150 ETB), or 50 (pay 150 ETB). */
    protected function showActivate(int $tier = 10): void
    {
        $student = $this->student;
        $settings = $student->settings ?? [];

        $current = $settings['question_count'] ?? 10;

        // Free tier: immediate access, no payment.
        if ($tier === 10) {
            $this->send(
                $this->chatId,
                "✅ <b>10 questions / month</b> — available now.",
                $this->menuKeyboard()
            );

            return;
        }

        // Paid tiers: require activation (150 ETB) + bank receipt.
        $this->send(
            $this->chatId,
            "💰 <b>Activate your account</b>\n\n".(
                $settings['activated'] ?? false
                    ? "✅ You're already active. Your <b>$tier questions</b> are unlocked."
                    : "⏳ To unlock <b>$tier questions</b>, activate your account for <b>150 ETB</b>."
            ),
            $this->activateKeyboard()
        );
    }

    /** Price-tier select keyboard for the Activate screen (inline keyboard). */
    protected function activateKeyboard(): array
    {
        return [
            [['text' => '10 questions / month', 'callback_data' => 'activate:10']],
            [['text' => '30 questions / month  •  150 ETB', 'callback_data' => 'activate:30']],
            [['text' => '50 questions / month  •  150 ETB', 'callback_data' => 'activate:50']],
        ];
    }

    /** Random questions: pick a subject and chapter to draw random questions. */
    protected function showRandomQuestions(): void
    {
        $this->showSubjects('random');
    }

    /** Model exam: pick a subject, chapter, and year to take a model exam. */
    protected function showModelExam(): void
    {
        $this->showSubjects('model');
    }

    /** National exam: pick a subject, chapter, and year to take the national exam. */
    protected function showNationalExam(): void
    {
        $this->showSubjects('national');
    }

    /* ------------------------------------------------------------------ */
    /* Navigation: subject → chapter                                       */
    /* ------------------------------------------------------------------ */

    protected function showSubjects(?string $mode): void
    {
        if (! $this->requireGrade()) {
            return;
        }

        $mode ??= 'study';
        $this->session->putContext(['mode' => $mode]);

        $titles = ['study' => '📚 Study', 'practice' => '✏️ Practice', 'test' => '📝 Chapter Test', 'mock' => '🎯 Mock Exam', 'random' => '🎲 Random Questions', 'model' => '📝 Model Exam', 'national' => '🏛️ National Exam'];
        $subjects = Subject::where('grade_id', $this->student->grade_id)
            ->where('is_active', true)->orderBy('order')->get(['id', 'name']);

        if ($subjects->isEmpty()) {
            $this->send($this->chatId, 'No subjects are available for your grade yet.', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $keyboard = $subjects->map(fn ($s) => [[
            'text' => $s->name,
            'callback_data' => 's:'.$s->id,
        ]])->all();
        $keyboard[] = [['text' => '◀️ Back', 'callback_data' => 'menu']];

        $this->send($this->chatId, ($titles[$mode] ?? '📚')."\nPick a subject 👇", $keyboard);
        $this->session->setState('choose_subject');
    }

    protected function showChapters(int $subjectId, ?string $mode = null): void
    {
        $subject = Subject::find($subjectId);

        if (! $subject) {
            $this->showMenu();

            return;
        }

        $mode ??= $this->session->context['mode'] ?? 'study';
        $this->session->putContext(['subject_id' => $subject->id, 'subject_name' => $subject->name, 'mode' => $mode]);

        $chapters = Chapter::where('subject_id', $subject->id)
            ->where('is_active', true)->orderBy('order')->get(['id', 'title']);

        if ($chapters->isEmpty()) {
            $this->send($this->chatId, 'No chapters yet in <b>'.e($subject->name).'</b>.', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $keyboard = $chapters->map(fn ($c) => [[
            'text' => $c->title,
            'callback_data' => 'c:'.$c->id,
        ]])->all();
        $keyboard[] = [['text' => '◀️ Back', 'callback_data' => 'mode:'.$this->session->context['mode']]];

        $this->send($this->chatId, '📖 <b>'.e($subject->name).'</b>\nPick a chapter 👇', $keyboard);
        $this->session->setState('choose_chapter');
    }

    protected function chapterChosen(int $chapterId): void
    {
        $mode = $this->session->context['mode'] ?? 'study';
        $this->session->putContext(['chapter_id' => $chapterId]);

        match ($mode) {
            'study' => $this->showNotes($chapterId),
            'practice' => $this->startPractice($chapterId),
            'test', 'mock' => $this->showExams($chapterId),
            'random' => $this->showQuestionTiers($chapterId, 'random'),
            'model' => $this->showExamYear($chapterId, 'model'),
            'national' => $this->showExamYear($chapterId, 'national'),
            default => $this->showMenu(),
        };
    }

    /** Tier selector for random questions and chapter-by-chapter exams. */
    protected function showQuestionTiers(int $chapterId, string $mode): void
    {
        $this->session->putContext(['tier' => null]);
        $this->send(
            $this->chatId,
            "🎲 <b>".e($this->session->context['subject_name'] ?? '').' — '.e($this->session->context['chapter_id'] ? Chapter::find($this->session->context['chapter_id'])->title : '')."</b>\n\nHow many questions?",
            $this->tierKeyboard()
        );
    }

    /** Year selector for model/national exam, then tier. */
    protected function showExamYear(int $chapterId, string $examType): void
    {
        $this->session->putContext(['tier' => null]);
        $this->send(
            $this->chatId,
            "📝 <b>".e($this->session->context['subject_name'] ?? '').' — '.e($this->session->context['chapter_id'] ? Chapter::find($this->session->context['chapter_id'])->title : '')."</b>\n\nWhich year?",
            $this->yearKeyboard($examType)
        );
    }

    /** Question-tier keyboard (10 / 30 / 50 / 100). */
    protected function tierKeyboard(): array
    {
        return [
            [['text' => '10 questions', 'callback_data' => 'tier:10']],
            [['text' => '30 questions', 'callback_data' => 'tier:30']],
            [['text' => '50 questions', 'callback_data' => 'tier:50']],
            [['text' => '100 questions', 'callback_data' => 'tier:100']],
        ];
    }

    /** Year keyboard for model/national exam. */
    protected function yearKeyboard(string $examType): array
    {
        return [
            [['text' => '2024', 'callback_data' => 'year:2024'], ['text' => '2025', 'callback_data' => 'year:2025']],
        ];
    }

    /** Select a question tier (10 / 30 / 50 / 100). */
    protected function selectTier(int $tier): void
    {
        $this->session->putContext(['tier' => $tier]);

        $mode = $this->session->context['mode'] ?? 'study';
        $chapterId = $this->session->context['chapter_id'] ?? 0;

        if ($mode === 'random') {
            $this->drawRandomQuestions($chapterId);
        } elseif ($mode === 'model') {
            $this->drawModelExam($chapterId, $this->session->context['year'] ?? 2024);
        } elseif ($mode === 'national') {
            $this->drawNationalExam($chapterId, $this->session->context['year'] ?? 2024);
        } else {
            $this->showMenu();
        }
    }

    /** Select a year for model/national exam. */
    protected function selectYear(int $year): void
    {
        $this->session->putContext(['year' => $year]);

        $mode = $this->session->context['mode'] ?? 'study';
        $chapterId = $this->session->context['chapter_id'] ?? 0;

        if ($mode === 'model') {
            $this->drawModelExam($chapterId, $year);
        } elseif ($mode === 'national') {
            $this->drawNationalExam($chapterId, $year);
        } else {
            $this->showMenu();
        }
    }

    /** Draw random questions from a chapter (respecting the selected tier). */
    protected function drawRandomQuestions(int $chapterId): void
    {
        $tier = $this->session->context['tier'] ?? 10;
        $questions = Question::where('chapter_id', $chapterId)
            ->where('is_active', true)
            ->inRandomOrder()
            ->take($tier)
            ->with('options')
            ->get();

        $this->send(
            $this->chatId,
            "🎲 <b>Random Questions</b> — <b>$tier</b> questions\n\n".($questions->isEmpty() ? 'No questions in this chapter yet.' : ''),
            $this->menuKeyboard()
        );
    }

    /** Draw a model exam paper (respecting year and tier). */
    protected function drawModelExam(int $chapterId, int $year): void
    {
        $tier = $this->session->context['tier'] ?? 10;

        $this->send(
            $this->chatId,
            "📝 <b>Model exam</b> — <b>$tier</b> questions (year $year)\n\nPreparing your paper…",
            $this->menuKeyboard()
        );
    }

    /** Draw a national exam paper (respecting year and tier). */
    protected function drawNationalExam(int $chapterId, int $year): void
    {
        $tier = $this->session->context['tier'] ?? 10;

        $this->send(
            $this->chatId,
            "🏛️ <b>National exam</b> — <b>$tier</b> questions (year $year)\n\nPreparing your paper…",
            $this->menuKeyboard()
        );
    }

    /* ------------------------------------------------------------------ */
    /* Study: notes (spec §5)                                              */
    /* ------------------------------------------------------------------ */

    protected function showNotes(int $chapterId): void
    {
        $chapter = Chapter::find($chapterId);

        if (! $chapter) {
            $this->showMenu();

            return;
        }

        $this->session->putContext(['chapter_id' => $chapterId, 'chapter_title' => $chapter->title]);

        $notes = Note::where('chapter_id', $chapterId)->orderBy('order')->get(['id', 'title']);

        $keyboard = $notes->map(fn ($n) => [[
            'text' => '📄 '.$n->title,
            'callback_data' => 'n:'.$n->id,
        ]])->all();
        $keyboard[] = [['text' => '◀️ Chapters', 'callback_data' => 's:'.$chapter->subject_id]];
        $keyboard[] = [['text' => '🏠 Menu', 'callback_data' => 'menu']];

        $this->send(
            $this->chatId,
            '📚 <b>'.e($chapter->title).'</b>'."\nSelect study notes 👇",
            $keyboard
        );
        $this->session->setState('study_notes');
    }

    protected function showNote(int $noteId): void
    {
        $note = Note::find($noteId);

        if (! $note) {
            $this->showMenu();

            return;
        }

        $keyboard = [
            [['text' => '◀️ All notes', 'callback_data' => 'notes:'.$note->chapter_id]],
            [['text' => '🏠 Menu', 'callback_data' => 'menu']],
        ];

        $this->sendLong($this->chatId, "📄 <b>".e($note->title)."</b>\n\n".e($note->content), $keyboard);
        $this->session->setState('view_note');
    }

    /* ------------------------------------------------------------------ */
    /* Practice + mistake review (spec §7, §11)                            */
    /* ------------------------------------------------------------------ */

    protected function startPractice(int $chapterId, string $state = 'practice', string $context = 'practice'): void
    {
        $questions = Question::where('chapter_id', $chapterId)
            ->where('is_active', true)
            ->with('options')
            ->inRandomOrder()
            ->get()
            ->filter(fn ($q) => $q->options->count() >= 2 && $q->options->contains('is_correct', true))
            ->take(config('telegram.page_size', 5))
            ->values();

        if ($questions->isEmpty()) {
            $this->send($this->chatId, 'No practice questions are available for this chapter yet. Stay tuned! 🎯', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $this->session->update([
            'state' => $state,
            'context' => array_merge($this->session->context ?? [], [
                'mode' => 'practice',
                'chapter_id' => $chapterId,
                'context' => $context,
                'qids' => $questions->pluck('id')->all(),
                'idx' => 0,
                'correct' => 0,
                'awaiting' => true,
            ]),
        ]);

        $this->sendPracticeQuestion();
    }

    protected function startMistakes(): void
    {
        if (! $this->requireGrade()) {
            return;
        }

        $mistakes = $this->progress->mistakes($this->student, config('telegram.page_size', 5));

        if ($mistakes->isEmpty()) {
            $this->send($this->chatId, "🎉 <b>No mistakes to review!</b>\nYou haven't missed any questions (or you've mastered them all).", $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $this->session->update([
            'state' => 'mistakes',
            'context' => [
                'mode' => 'mistakes',
                'context' => 'mistake',
                'qids' => $mistakes->pluck('question_id')->all(),
                'idx' => 0,
                'correct' => 0,
                'awaiting' => true,
            ],
        ]);

        $this->sendPracticeQuestion();
    }

    protected function currentQuestion(): ?Question
    {
        $qids = $this->session->context['qids'] ?? [];
        $idx = $this->session->context['idx'] ?? 0;

        return isset($qids[$idx]) ? Question::with('options')->find($qids[$idx]) : null;
    }

    protected function sendPracticeQuestion(): void
    {
        $question = $this->currentQuestion();

        if (! $question) {
            $this->finishPractice();

            return;
        }

        $context = $this->session->context;
        $total = count($context['qids']);
        $prefix = ($context['context'] ?? '') === 'mistake' ? '❌ Review' : '✏️ Practice';

        $this->send(
            $this->chatId,
            "{$prefix} · Question (".($context['idx'] + 1)."/{$total}) <i>· {$question->difficulty}</i>\n\n".
            e($question->question_text)."\n\n".$this->optionsText($question),
            $this->optionsKeyboard($question)
        );
        $this->session->putContext(['awaiting' => true]);
    }

    protected function answerOption(int $optionId): void
    {
        if (empty($this->session->context['awaiting'])) {
            $this->send($this->chatId, 'Please use the buttons on the latest question 👆');

            return;
        }

        match ($this->session->state) {
            'practice', 'mistakes' => $this->answerPracticeQuestion($optionId),
            'exam' => $this->answerExamQuestion($optionId),
            default => $this->showMenu(),
        };
    }

    protected function answerPracticeQuestion(int $optionId): void
    {
        $question = $this->currentQuestion();

        if (! $question) {
            $this->showMenu();

            return;
        }

        $option = $question->options->firstWhere('id', $optionId);

        if (! $option) {
            $this->send($this->chatId, 'That option is no longer valid — showing the question again.');
            $this->sendPracticeQuestion();

            return;
        }

        $contextType = $this->session->context['context'] ?? 'practice';
        $correct = (bool) $option->is_correct;

        $this->progress->recordAnswer($this->student, $question, $option->id, $correct, $contextType);

        $context = $this->session->context;
        $context['correct'] = ($context['correct'] ?? 0) + ($correct ? 1 : 0);
        $context['awaiting'] = false;
        $this->session->update(['context' => $context]);

        $lines = [$correct ? '✅ <b>Correct!</b>' : '❌ <b>Not correct.</b>'];

        $correctOption = $question->correctOption();
        if ($correctOption && ! $correct) {
            $lines[] = 'Correct answer: <b>'.$correctOption->label.')</b> '.e($correctOption->text);
        }

        if ($contextType === 'mistake' && $correct) {
            $lines[] = '🎓 This one is now marked as <b>mastered</b>.';
        }

        if ($question->explanation) {
            $lines[] = "\n💡 ".e($question->explanation);
        }

        $isLast = ($this->session->context['idx'] ?? 0) + 1 >= count($this->session->context['qids'] ?? []);
        $keyboard = [[[
            'text' => $isLast ? '🏁 See results' : 'Next question ➡️',
            'callback_data' => 'next',
        ]]];

        $this->send($this->chatId, implode("\n", $lines), $keyboard);
    }

    protected function nextQuestion(): void
    {
        $state = $this->session->state;

        if (! in_array($state, ['practice', 'mistakes', 'exam'], true)) {
            $this->showMenu();

            return;
        }

        $context = $this->session->context;
        $context['idx'] = ($context['idx'] ?? 0) + 1;
        $context['awaiting'] = true;
        $this->session->update(['context' => $context]);

        if ($state === 'exam') {
            $this->sendExamQuestion();

            return;
        }

        if ($context['idx'] >= count($context['qids'] ?? [])) {
            $this->finishPractice();

            return;
        }

        $this->sendPracticeQuestion();
    }

    protected function finishPractice(): void
    {
        $context = $this->session->context;
        $total = count($context['qids'] ?? []);
        $correct = (int) ($context['correct'] ?? 0);
        $pct = $total > 0 ? (int) round($correct / $total * 100) : 0;
        $isMistakes = ($context['context'] ?? '') === 'mistake';

        $heading = $isMistakes
            ? '❌ <b>Mistake review complete!</b>'
            : '📘 <b>Practice complete!</b>';

        $keyboard = [];

        if (! $isMistakes && ! empty($context['chapter_id'])) {
            $keyboard[] = [[
                'text' => '🔁 Retry chapter',
                'callback_data' => 'c:'.$context['chapter_id'],
            ]];
        }

        if ($isMistakes) {
            $keyboard[] = [['text' => '❌ More mistakes', 'callback_data' => 'mistakes']];
        }

        $keyboard[] = [['text' => '📊 Progress', 'callback_data' => 'progress']];
        $keyboard[] = [['text' => '🏠 Menu', 'callback_data' => 'menu']];

        $this->send(
            $this->chatId,
            "{$heading}\nScore: <b>{$correct}/{$total}</b> ({$pct}%)",
            $keyboard
        );
        $this->session->setState('menu', ['mode' => 'practice'] + $context);
    }

    /* ------------------------------------------------------------------ */
    /* Chapter tests + mock exams (spec §8, §9)                            */
    /* ------------------------------------------------------------------ */

    protected function showExams(int $chapterId): void
    {
        if (! $this->requireGrade()) {
            return;
        }

        $mode = $this->session->context['mode'] ?? 'test';
        $type = $mode === 'mock' ? 'mock' : 'chapter_test';

        $exams = Exam::where('is_active', true)
            ->where('grade_id', $this->student->grade_id)
            ->where('subject_id', $this->session->context['subject_id'] ?? 0)
            ->where('type', $type)
            ->when($type === 'chapter_test', fn ($q) => $q->where(fn ($w) => $w
                ->where('chapter_id', $chapterId)
                ->orWhereNull('chapter_id')))
            ->orderBy('id')
            ->get(['id', 'title', 'question_count', 'time_limit_minutes']);

        if ($exams->isEmpty()) {
            $this->send(
                $this->chatId,
                $type === 'mock'
                    ? 'No mock exams are scheduled for this subject yet.'
                    : 'No chapter test is available here yet.',
                $this->menuKeyboard()
            );
            $this->session->setState('menu');

            return;
        }

        $this->session->putContext(['exam_ids' => $exams->pluck('id')->all()]);

        $keyboard = $exams->map(fn ($e) => [[
            'text' => '📝 '.$e->title,
            'callback_data' => 'e:'.$e->id,
        ]])->all();
        $keyboard[] = [['text' => '◀️ Chapters', 'callback_data' => 's:'.$this->session->context['subject_id']]];

        $this->send(
            $this->chatId,
            ($type === 'mock' ? '🎯 <b>Mock exams</b>' : '📝 <b>Chapter tests</b>')."\nPick one 👇",
            $keyboard
        );
        $this->session->setState('choose_exam');
    }

    protected function startExam(int $examId): void
    {
        if (! $this->requireGrade()) {
            return;
        }

        $allowed = $this->session->context['exam_ids'] ?? [];

        $exam = Exam::where('is_active', true)
            ->where('grade_id', $this->student->grade_id)
            ->find($examId);

        if (! $exam || ($allowed && ! in_array($examId, $allowed))) {
            $this->send($this->chatId, 'That exam is not available. Please pick from the list.', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $attempt = $this->exams->startAttempt($this->student, $exam);

        if ($attempt->total_questions === 0) {
            $this->send($this->chatId, '⚠️ This exam has no questions yet. Please ask an administrator to add content.', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $context = $this->session->context ?? [];
        $answers = $attempt->answers ?? [];
        $idx = 0;
        foreach ($attempt->question_ids ?? [] as $i => $qid) {
            if (! isset($answers[(string) $qid]) && ! isset($answers[$qid])) {
                $idx = $i;
                break;
            }
            $idx = $i + 1;
        }

        $this->session->update([
            'state' => 'exam',
            'context' => array_merge($context, [
                'mode' => $exam->type === 'mock' ? 'mock' : 'test',
                'exam_id' => $exam->id,
                'exam_title' => $exam->title,
                'attempt_id' => $attempt->id,
                'subject_id' => $exam->subject_id,
                'chapter_id' => $exam->chapter_id ?? $context['chapter_id'] ?? null,
                'idx' => min($idx, $attempt->total_questions - 1),
                'awaiting' => true,
            ]),
        ]);

        $this->sendExamQuestion();
    }

    protected function currentExamQuestion(): ?Question
    {
        $attempt = $this->currentAttempt();

        if (! $attempt) {
            return null;
        }

        $qids = $attempt->question_ids ?? [];
        $idx = $this->session->context['idx'] ?? 0;

        return isset($qids[$idx]) ? Question::with('options')->find($qids[$idx]) : null;
    }

    protected function currentAttempt(): ?\App\Models\ExamAttempt
    {
        $attemptId = $this->session->context['attempt_id'] ?? null;

        return $attemptId ? \App\Models\ExamAttempt::find($attemptId) : null;
    }

    protected function sendExamQuestion(): void
    {
        $attempt = $this->currentAttempt();
        $question = $this->currentExamQuestion();

        if (! $attempt || ! $question) {
            $attempt ? $this->finishExam($attempt) : $this->showMenu();

            return;
        }

        if ($this->exams->secondsLeft($attempt) === 0) {
            $this->finishExam($this->exams->finalize($attempt));

            return;
        }

        $idx = ($this->session->context['idx'] ?? 0) + 1;
        $left = $this->exams->secondsLeft($attempt);
        $time = sprintf('%d:%02d', intdiv($left, 60), $left % 60);

        $this->send(
            $this->chatId,
            "📝 <b>".e($this->session->context['exam_title'] ?? 'Exam')."</b>\n".
            "Question <b>{$idx}/{$attempt->total_questions}</b> · ⏱ {$time} left\n\n".
            e($question->question_text)."\n\n".$this->optionsText($question),
            $this->optionsKeyboard($question)
        );
        $this->session->putContext(['awaiting' => true]);
    }

    protected function answerExamQuestion(int $optionId): void
    {
        $attempt = $this->currentAttempt();
        $question = $this->currentExamQuestion();

        if (! $attempt || ! $question) {
            $this->showMenu();

            return;
        }

        if ($attempt->status === 'completed') {
            $this->finishExam($attempt);

            return;
        }

        $option = $question->options->firstWhere('id', $optionId);

        if (! $option) {
            $this->sendExamQuestion();

            return;
        }

        if ($this->exams->secondsLeft($attempt) === 0) {
            $this->finishExam($this->exams->finalize($attempt));

            return;
        }

        $result = $this->exams->answer($attempt, $question->id, $option->id);
        $attempt->refresh();

        if ($result['finished'] || $attempt->status === 'completed') {
            $this->finishExam($attempt);

            return;
        }

        $idx = ($this->session->context['idx'] ?? 0) + 1;
        $answered = count($attempt->answers ?? []);

        $this->send(
            $this->chatId,
            "✅ Answer saved (<b>{$answered}/{$attempt->total_questions}</b>).",
            [[['text' => 'Next question ➡️', 'callback_data' => 'next']]]
        );
        $this->session->putContext(['awaiting' => false, 'idx' => $idx - 1]);
    }

    protected function finishExam(\App\Models\ExamAttempt $attempt): void
    {
        $attempt->loadMissing('exam');
        $exam = $attempt->exam;

        $wrong = collect($attempt->answers ?? [])
            ->filter(fn ($a) => empty($a['correct']))
            ->keys()
            ->map(fn ($qid) => (int) $qid)
            ->take(5)
            ->values()
            ->all();

        $lines = [
            ($attempt->passed ? '🎉 <b>PASSED!</b>' : '📉 <b>Not passed</b>'),
            '',
            'Exam: <b>'.e($exam->title).'</b>',
            'Score: <b>'.$attempt->score.'%</b> ('.$attempt->correct_answers.'/'.$attempt->total_questions.')',
            'Pass mark: '.$exam->pass_mark.'%',
            '⏱ Time: '.sprintf('%d:%02d', intdiv($attempt->time_spent_seconds, 60), $attempt->time_spent_seconds % 60),
        ];

        if ($attempt->passed && $exam->type === 'chapter_test') {
            $lines[] = "\n✅ Great work — try the next chapter when you are ready!";
        }

        $keyboard = [];

        if ($wrong) {
            $keyboard[] = [['text' => '📋 Review mistakes ('.count($wrong).')', 'callback_data' => 'review']];
        }

        $keyboard[] = [['text' => '🔁 Retry', 'callback_data' => 'retry:'.$exam->id]];

        $nextChapter = $this->nextChapterId($exam);
        if ($nextChapter && $exam->type === 'chapter_test') {
            $keyboard[] = [['text' => '⏭ Next chapter', 'callback_data' => 'c:'.$nextChapter]];
        }

        $keyboard[] = [['text' => '🏠 Menu', 'callback_data' => 'menu']];

        $this->session->update([
            'state' => 'exam_result',
            'context' => array_merge($this->session->context ?? [], [
                'exam_id' => $exam->id,
                'attempt_id' => $attempt->id,
                'wrong' => $wrong,
                'reviewed' => false,
                'awaiting' => false,
            ]),
        ]);

        $this->send($this->chatId, implode("\n", $lines), $keyboard);
    }

    protected function showReview(): void
    {
        $attempt = $this->currentAttempt();
        $wrong = $this->session->context['wrong'] ?? [];

        if (! $attempt || empty($wrong)) {
            $this->showMenu();

            return;
        }

        $questions = Question::with('options')->whereIn('id', $wrong)->get()->keyBy('id');

        foreach ($wrong as $qid) {
            $question = $questions->get($qid);

            if (! $question) {
                continue;
            }

            $chosenId = $attempt->answers[$qid]['option'] ?? $attempt->answers[(string) $qid]['option'] ?? null;
            $chosen = $question->options->firstWhere('id', $chosenId);
            $correct = $question->correctOption();

            $lines = [
                '❌ <b>'.e($question->question_text).'</b>',
                '',
                'Your answer: '.($chosen ? '<s>'.$chosen->label.') '.e($chosen->text).'</s>' : '—'),
                'Correct: <b>'.$correct->label.')</b> '.e($correct->text),
            ];

            if ($question->explanation) {
                $lines[] = "\n💡 ".e($question->explanation);
            }

            $this->send($this->chatId, implode("\n", $lines));
        }

        $this->send(
            $this->chatId,
            'Review complete. Keep going — you have got this! 💪',
            [
                [['text' => '🔁 Retry', 'callback_data' => 'retry:'.$attempt->exam_id]],
                [['text' => '🏠 Menu', 'callback_data' => 'menu']],
            ]
        );
    }

    protected function nextChapterId(Exam $exam): ?int
    {
        if (! $exam->chapter_id) {
            return null;
        }

        return Chapter::where('subject_id', $exam->subject_id)
            ->where('is_active', true)
            ->where('order', '>', $exam->chapter->order ?? 0)
            ->orderBy('order')
            ->value('id');
    }

    /* ------------------------------------------------------------------ */
    /* Progress / announcements / settings (spec §10, §20, §25)            */
    /* ------------------------------------------------------------------ */

    protected function showProgress(): void
    {
        if (! $this->requireGrade()) {
            return;
        }

        $p = $this->progress->forStudent($this->student);

        $lines = [
            '📊 <b>Your progress</b>',
            '',
            'Answered: <b>'.$p['questions_answered'].'</b> questions',
            'Correct: <b>'.$p['correct'].'</b> · Wrong: <b>'.$p['wrong'].'</b>',
            'Accuracy: <b>'.number_format($p['accuracy'], 1).'%</b>',
            'Tests completed: <b>'.$p['tests_completed'].'</b>',
            'Mock exams: <b>'.$p['mock_exams_completed'].'</b>',
            'Open mistakes: <b>'.$p['open_mistakes'].'</b>',
        ];

        if ($p['by_subject']) {
            $lines[] = "\n<b>By subject:</b>";
            foreach ($p['by_subject'] as $s) {
                $lines[] = '• '.e($s['name']).': '.$s['accuracy'].'% ('.$s['answered'].' answered)';
            }
        }

        if ($p['weak_topics']) {
            $lines[] = "\n<b>💪 Weak areas to review:</b>";
            foreach ($p['weak_topics'] as $t) {
                $lines[] = '• '.e($t['title']).': '.$t['accuracy'].'%';
            }
        }

        $keyboard = [
            [['text' => '❌ My Mistakes', 'callback_data' => 'mistakes']],
            [['text' => '✏️ Practice', 'callback_data' => 'mode:practice']],
            [['text' => '🏠 Menu', 'callback_data' => 'menu']],
        ];

        $this->send($this->chatId, implode("\n", $lines), $keyboard);
        $this->session->setState('menu', $this->session->context ?? []);
    }

    protected function showAnnouncements(): void
    {
        $announcements = Announcement::published()->limit(3)->get();

        if ($announcements->isEmpty()) {
            $this->send($this->chatId, '📭 No announcements right now. Check back later!', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        foreach ($announcements as $a) {
            $this->sendLong(
                $this->chatId,
                "📢 <b>".e($a->title)."</b>\n\n".e($a->body),
                $a === $announcements->last() ? $this->menuKeyboard() : null
            );
        }

        $this->session->setState('menu');
    }

    protected function showSettings(): void
    {
        $grade = $this->student->grade;

        $lines = [
            '⚙️ <b>Settings</b>',
            '',
            'Grade: <b>'.($grade ? e($grade->name) : 'not set').'</b>',
            'Language: English (Amharic coming soon 🇪🇹)',
        ];

        $keyboard = [
            [['text' => '🔽 Change grade', 'callback_data' => 'grade']],
            [['text' => '🏠 Menu', 'callback_data' => 'menu']],
        ];

        $this->send($this->chatId, implode("\n", $lines), $keyboard);
        $this->session->setState('settings');
    }

    /* ------------------------------------------------------------------ */
    /* Presentation helpers                                                */
    /* ------------------------------------------------------------------ */

    protected function optionsText(Question $question): string
    {
        return $question->options->map(
            fn ($o) => '<b>'.$o->label.')</b> '.e($o->text)
        )->implode("\n");
    }

    protected function optionsKeyboard(Question $question): array
    {
        return $question->options->map(fn ($o) => [[
            'text' => $o->label.') '.mb_substr(trim($o->text), 0, 40),
            'callback_data' => 'o:'.$o->id,
        ]])->all();
    }

    protected function resumeKeyboard(): array
    {
        return [[['text' => '🏠 Menu', 'callback_data' => 'menu']]];
    }

    protected function send(int $chatId, string $text, ?array $keyboard = null): void
    {
        $chunks = $this->splitMessage($text);

        foreach ($chunks as $i => $chunk) {
            $isLast = $i === count($chunks) - 1;
            $this->tg->sendMessage($chatId, $chunk, $isLast ? $keyboard : null);
        }
    }

    protected function sendLong(int $chatId, string $text, ?array $keyboard = null): void
    {
        $this->send($chatId, $text, $keyboard);
    }

    /** Split >4000-char messages on line boundaries. */
    protected function splitMessage(string $text): array
    {
        if (mb_strlen($text) <= 4000) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($current) + mb_strlen($line) + 1 > 4000) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n").$line;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks ?: [$text];
    }

    protected function botUsername(): string
    {
        return (string) config('telegram.username', 'bot');
    }
}
