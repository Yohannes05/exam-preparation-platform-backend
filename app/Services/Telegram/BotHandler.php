<?php

namespace App\Services\Telegram;

use App\Models\Announcement;
use App\Models\BotSession;
use App\Models\Chapter;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Note;
use App\Models\Question;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TelegramPayment;
use App\Models\QuestionAttempt;
use App\Models\ChannelCheck;
use App\Services\ExamService;
use App\Services\ProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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
    protected int $callbackMessageId = 0;

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

        if (isset($update['message']['photo'])) {
            $this->handleReceiptPhoto($update['message']);

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
        $data = (string) ($callback['data'] ?? '');
        $this->callbackMessageId = (int) ($callback['message']['message_id'] ?? 0);
        [$action, $arg] = array_pad(explode(':', $data, 2), 2, null);

        if (in_array($action, ['payment_approve', 'payment_reject'], true)) {
            $this->reviewPayment($callback, $action, (int) $arg);

            return;
        }

        $chatId = (int) ($callback['message']['chat']['id'] ?? $callback['from']['id']);
        $this->begin($chatId, $callback['from']);

        if (! empty($callback['id'])) {
            $this->tg->answerCallbackQuery($callback['id']);
        }

        // Do not let crafted or stale callbacks bypass the first-visit channel gate.
        // Help and contact are intentionally available before joining (spec §4).
        if (! in_array($action, ['join_confirmed', 'help_contact', 'help', 'howto', 'contact'], true)
            && ! ChannelCheck::where('telegram_id', $this->student->telegram_id)->exists()
            && ! $this->checkChannelAndNavigate()) {
            return;
        }

        match ($action) {
            'start', null => $this->start(),
            'menu' => $this->showMenu(),
            'subjects' => $this->showSubjects(null),
            'g' => $this->selectGrade((int) $arg),
            'mode' => $this->showSubjects($arg),
            's' => $this->showChapters((int) $arg),
            'c' => $this->chapterChosen((int) $arg),
            'chapter_notes' => $this->openChapterNote((int) $arg),
            'chapter_quiz' => $this->startPractice((int) $arg),
            'practice_questions' => $this->showPracticeSources(),
            'notes' => $this->showNotes((int) ($arg ?? $this->session->context['chapter_id'] ?? 0)),
            'n' => $this->showNote((int) $arg),
            'note_prev' => $this->showNote((int) $arg),
            'note_next' => $this->showNote((int) $arg),
            'pdf' => $this->downloadNotePdf((int) $arg),
            'chapter_pdf' => $this->downloadChapterPdf((int) $arg),
            'o' => $this->answerOption((int) $arg),
            'next' => $this->nextQuestion(),
            'stop' => $this->finishPractice(),
            'quiz_retry' => $this->retryQuestionSet(),
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
            'help' => $this->sendHelp(),
            'howto' => $this->showHowTo(),
            'contact' => $this->sendContact(),
            'activate' => $this->showActivate(),
            'referrals' => $this->showReferrals(),
            'receipt' => $this->requestReceipt((string) ($arg ?? '')),
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
        $text = trim((string) $message['text']);
        $referrerTelegramId = null;
        $isStartCommand = preg_match('/^\/start(?:@\w+)?(?:\s+ref_(\d+))?$/i', $text, $startParts) === 1;
        if ($isStartCommand && ! empty($startParts[1])) {
            $referrerTelegramId = (int) $startParts[1];
        }

        $this->begin($chatId, $message['from'], $referrerTelegramId);

        if ($isStartCommand || $text === 'start') {
            $this->start();

            return;
        }

        if ($text === 'join_confirmed') {
            $this->confirmChannelJoin();

            return;
        }

        if (in_array($text, ['/menu', '/help'], true)) {
            if ($text === '/help') {
                $this->sendHelp();
            } elseif (ChannelCheck::where('telegram_id', $this->student->telegram_id)->exists() || $this->checkChannelAndNavigate()) {
                $this->showMenu();
            }

            return;
        }

        if (str_starts_with($text, '/')) {
            $this->sendHelp();

            return;
        }

        $keyboardActions = Cache::get('telegram.reply_keyboard.'.$chatId, []);
        $selectedAction = $keyboardActions[$text] ?? null;
        if (is_string($selectedAction) && str_starts_with($selectedAction, 'url:')) {
            $url = substr($selectedAction, 4);
            $this->send($this->chatId, '🔗 <a href="'.e($url).'">Open link</a>');
            return;
        }

        if (! ChannelCheck::where('telegram_id', $this->student->telegram_id)->exists()
            && ! $this->checkChannelAndNavigate()) {
            return;
        }

        if (! $this->student->grade_id
            && preg_match('/^(?:grade\s*)?(6|8|12)(?:st|nd|th)?$/i', $text, $gradeMatch)) {
            $grade = Grade::where('level', (int) $gradeMatch[1])
                ->where('is_active', true)->first();
            if ($grade) {
                $this->selectGrade((int) $grade->id);
            } else {
                $this->send($this->chatId, 'That grade is not available. Please choose Grade 6, 8, or 12.', $this->gradeKeyboard());
            }

            return;
        }

        if (is_string($selectedAction)) {
            $this->handleCallback([
                'id' => '',
                'from' => $message['from'],
                'message' => ['chat' => ['id' => $chatId, 'type' => 'private']],
                'data' => $selectedAction,
            ]);
            return;
        }

        // Free-text input: no state currently accepts it — re-prompt politely.
        match ($this->session->state) {
            'choose_grade' => $this->send($this->chatId, "Please pick your grade from the buttons below 👇", $this->gradeKeyboard()),
            'practice', 'exam', 'mistakes' => $this->send($this->chatId, "Please answer using the option buttons above 👆", $this->session->state === 'exam' ? $this->resumeKeyboard() : null),
            default => $this->showMenu(),
        };
    }

    protected function begin(int $chatId, array $from, ?int $referrerTelegramId = null): void
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

            if ($referrerTelegramId && $referrerTelegramId !== $student->telegram_id) {
                $referrer = Student::where('telegram_id', $referrerTelegramId)->first();
                if ($referrer) {
                    Referral::create([
                        'referrer_student_id' => $referrer->id,
                        'referred_student_id' => $student->id,
                    ]);
                }
            }
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

        // Check cached membership on every entry, including returning users.
        if (! $this->checkChannelAndNavigate()) {
            return;
        }

        $this->qualifyReferral();

        // Already has a grade — go straight to Home.
        if ($student->grade_id) {
            $this->showMenu();

            return;
        }

        // Home combines grade choices with the main destinations.
        $name = $student->first_name ? ', '.$student->first_name : '';
        $this->send(
            $this->chatId,
            "👋 Welcome{$name}!\n\nChoose your grade to start practising, or pick another option:",
            $this->menuKeyboard()
        );
        $this->session->setState('menu');
    }

    protected function gradeKeyboard(): array
    {
        $grades = Grade::where('is_active', true)->orderBy('level')->get(['id', 'name', 'level']);

        $buttons = $grades->map(fn ($g) => [
            'text' => $g->name.' · '.$this->amharicGrade($g->level),
            'callback_data' => 'g:'.$g->id,
        ])->all();
        $rows = array_chunk($buttons, 2);

        return $rows ?: [[['text' => '🏠 Menu', 'callback_data' => 'menu']]];
    }

    /** Join channel keyboard (first visit). */
    protected function joinChannelKeyboard(): array
    {
        return [
            [['text' => '📢 Join channel', 'url' => config('telegram.channel_url')]],
            [['text' => '✅ I joined', 'callback_data' => 'join_confirmed']],
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

        $this->session->update(['context' => ['mode' => 'study']]);
        $this->showSubjects(null);
    }

    /* ------------------------------------------------------------------ */
    /* Main menu                                                           */
    /* ------------------------------------------------------------------ */

    protected function menuKeyboard(): array
    {
        $grades = Grade::where('is_active', true)->orderBy('level')->get(['id', 'name', 'level']);
        $keyboard = [
            [['text' => 'አጠቃቀሙ · How to use', 'callback_data' => 'howto']],
        ];

        foreach ($grades->chunk(2) as $gradeRow) {
            $keyboard[] = $gradeRow->map(fn ($grade) => [
                'text' => $grade->name.' · '.$this->amharicGrade($grade->level),
                'callback_data' => 'g:'.$grade->id,
            ])->values()->all();
        }

        return array_merge($keyboard, [
            [['text' => '💰 To Activate', 'callback_data' => 'activate'], ['text' => '📊 Leaderboard', 'callback_data' => 'leaderboard']],
            [['text' => '📢 Notice', 'callback_data' => 'notice'], ['text' => '📢 Join Channel', 'url' => config('telegram.channel_url')]],
            [['text' => '❓ Help', 'callback_data' => 'help'], ['text' => '☎️ Contact', 'callback_data' => 'contact']],
        ]);
    }

    protected function showMenu(): void
    {
        $this->send($this->chatId, '🏠 <b>Main menu</b>\nWhat would you like to do?', $this->menuKeyboard());
        $this->session->setState('menu');
    }

    /** Show the student's weekly and lifetime points/ranks within their grade. */
    protected function showLeaderboard(): void
    {
        if (! $this->requireGrade()) {
            return;
        }

        $weekly = $this->progress->weeklyScore($this->student);
        $weeklyRank = $this->progress->weeklyRank($this->student);
        $allTime = $this->progress->allTimeScore($this->student);
        $allTimeRank = $this->progress->allTimeRank($this->student);

        $this->send(
            $this->chatId,
            '📊 <b>Your leaderboard · '.e($this->student->grade?->name ?? '')."</b>\n\n".
            '⭐ Weekly score: <b>'.$weekly.'</b> · Rank: <b>'.($weeklyRank ? '#'.$weeklyRank : '—')."</b>\n".
            '🏫 All-time score: <b>'.$allTime.'</b> · Rank: <b>'.($allTimeRank ? '#'.$allTimeRank : '—').'</b>',
            [[['text' => '🏠 Home', 'callback_data' => 'menu']]]
        );
    }

    /** Show the student's unique invite link and progress to their next reward. */
    protected function showReferrals(): void
    {
        $username = ltrim((string) config('telegram.username', ''), '@');
        if ($username === '' || $username === 'bot') {
            $username = Cache::remember('telegram.bot_username', now()->addDay(), fn () => $this->tg->getBotUsername() ?? '');
        }
        if ($username === '' || $username === 'bot') {
            $this->send($this->chatId, '🎁 Referral links are being configured. Please check back soon.', [
                [['text' => '🏠 Home', 'callback_data' => 'menu']],
            ]);

            return;
        }

        $link = 'https://t.me/'.$username.'?start=ref_'.$this->student->telegram_id;
        $qualified = Referral::where('referrer_student_id', $this->student->id)
            ->whereNotNull('qualified_at')->count();
        $rewardsEarned = ReferralReward::where('referrer_student_id', $this->student->id)->count();
        $remainingInGroup = $qualified % 5;
        $shareUrl = 'https://t.me/share/url?url='.urlencode($link)
            .'&text='.urlencode('Join me on the Grade Quiz bot and practise together!');

        $this->send(
            $this->chatId,
            "🎁 <b>Invite friends and earn activation</b>\n\n".
            "Share your personal link:\n<code>{$link}</code>\n\n".
            'Eligible friends: <b>'.$qualified.'</b> · Rewards earned: <b>'.$rewardsEarned.'</b> · Progress: <b>'.$remainingInGroup.'/5</b> toward your next 30-day reward.' .
            "\n\nA friend counts after joining the required channel. Every five unique friends earns you 30 days of activation.",
            [
                [['text' => '📤 Share my invite link', 'url' => $shareUrl]],
                [['text' => '🏠 Home', 'callback_data' => 'menu']],
            ]
        );
    }

    /** Qualify one referred student and award each five-referral milestone once. */
    protected function qualifyReferral(): void
    {
        $reward = DB::transaction(function () {
            $referral = Referral::where('referred_student_id', $this->student->id)
                ->lockForUpdate()->first();

            if (! $referral || $referral->qualified_at) {
                return null;
            }

            $referrer = Student::whereKey($referral->referrer_student_id)->lockForUpdate()->first();
            $referral->update(['qualified_at' => now()]);

            if (! $referrer) {
                return null;
            }

            $qualifiedCount = Referral::where('referrer_student_id', $referrer->id)
                ->whereNotNull('qualified_at')->count();

            if ($qualifiedCount < 5 || $qualifiedCount % 5 !== 0) {
                return null;
            }

            $startsAt = $referrer->activated_until?->isFuture() ? $referrer->activated_until : now();
            $validUntil = $startsAt->copy()->addDays(30);
            ReferralReward::create([
                'referrer_student_id' => $referrer->id,
                'referral_milestone' => $qualifiedCount,
                'activated_until' => $validUntil,
            ]);
            $referrer->update(['activated_until' => $validUntil]);

            return ['referrer' => $referrer, 'valid_until' => $validUntil];
        });

        if ($reward) {
            $this->tg->sendMessage(
                (int) $reward['referrer']->telegram_id,
                '🎉 Five of your invited students have joined! Your account now has 30 more days of activation and is active until <b>'.
                    e($reward['valid_until']->timezone('Africa/Addis_Ababa')->format('M j, Y')).'</b>.'
            );
        }
    }

    /** Notice: pinned announcements. */
    protected function showNotice(): void
    {
        $notice = Announcement::published()
            ->where(function ($query) {
                $query->where('audience', 'all')
                    ->orWhere(fn ($grade) => $grade->where('audience', 'grade')->where('grade_id', $this->student->grade_id))
                    ->orWhere('audience', 'activated');
            })
            ->when(! $this->isActivated(), fn ($query) => $query->where('audience', '!=', 'activated'))
            ->first();

        $this->send(
            $this->chatId,
            $notice
                ? "📢 <b>".e($notice->title)."</b>\n\n".e($notice->body)
                : '📭 There are no notices for your grade yet.',
            [[['text' => '🏠 Home', 'callback_data' => 'menu']]]
        );
    }

    /** Help and contact: how the bot works + support contact. */
    protected function sendHelp(): void
    {
        $this->send(
            $this->chatId,
            "ℹ️ <b>Help · እገዛ</b>\n\n".
            "Choose a grade, subject and chapter. Read notes or answer the quiz buttons one at a time. Each answer shows the correct choice and explanation.\n\n".
            'Free accounts can answer up to '.(int) config('telegram.free_daily_limit', 10)." questions per day. Use To Activate for unlimited practice.\n\n".
            'Use /menu to return home.',
            [[['text' => '☎️ Contact · አግኙን', 'callback_data' => 'contact']], [['text' => '🏠 Home', 'callback_data' => 'menu']]]
        );
    }

    protected function showHowTo(): void
    {
        $this->send(
            $this->chatId,
            "📘 <b>አጠቃቀሙ · How to use</b>\n\n".
            "1. Choose your grade and subject.\n2. Choose a chapter and read its notes.\n3. Take a chapter quiz or choose a question set.\n4. Tap an answer to see the correct answer and explanation.\n5. Review mistakes and check your progress.",
            [[['text' => '🏠 Home', 'callback_data' => 'menu']]]
        );
    }

    protected function sendContact(): void
    {
        $username = ltrim((string) config('telegram.payment.admin_username', ''), '@');
        if ($username === '') {
            $this->send($this->chatId, 'Support contact is not configured yet.', $this->menuKeyboard());

            return;
        }

        $this->send($this->chatId, '☎️ Contact support for questions or payment help: @'.e($username), [
            [['text' => 'Message @'.$username, 'url' => 'https://t.me/'.$username]],
            [['text' => '🏠 Home', 'callback_data' => 'menu']],
        ]);
    }

    protected function amharicGrade(?int $level): string
    {
        if ($level === null) {
            return 'Grade';
        }

        return match ($level) {
            6 => '6ኛ ክፍል',
            8 => '8ኛ ክፍል',
            12 => '12ኛ ክፍል',
            default => $level.'ኛ ክፍል',
        };
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

        if (! $this->tg->checkChannelMembership($student->telegram_id)) {
            $this->send(
                $this->chatId,
                "❌ <b>I couldn't find you in the channel yet.</b> Please join, then try again.",
                $this->joinChannelKeyboard()
            );
            $this->session->setState('join_channel');

            return;
        }

        ChannelCheck::updateOrCreate(['telegram_id' => $student->telegram_id], [
            'telegram_id' => $student->telegram_id,
            'joined_confirmed_at' => now(),
        ]);

        $this->qualifyReferral();

        $this->send(
            $this->chatId,
            "✅ <b>Thanks for joining!</b>\n\nHome is now open. Choose your grade to start practising.",
            $this->gradeKeyboard()
        );
        $this->session->setState('choose_grade');
    }

    /** Persist and forward a student payment receipt for admin review. */
    protected function handleReceiptPhoto(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $this->begin($chatId, $message['from'] ?? []);

        if ($this->session->state !== 'awaiting_payment_receipt') {
            $this->send($chatId, 'To submit a payment receipt, open To Activate first.', $this->menuKeyboard());

            return;
        }

        $photos = $message['photo'] ?? [];
        $photo = is_array($photos) ? end($photos) : null;
        $fileId = is_array($photo) ? ($photo['file_id'] ?? null) : null;

        if (! $fileId) {
            $this->send($chatId, 'I could not read that image. Please send the receipt as a photo.');

            return;
        }

        $payment = TelegramPayment::create([
            'student_id' => $this->student->id,
            'receipt_file_id' => $fileId,
            'method' => $this->session->context['payment_method'] ?? 'unknown',
            'amount' => (int) config('telegram.payment.price', 150),
            'status' => 'pending',
        ]);

        $adminChatId = config('telegram.admin_chat_id');
        if ($adminChatId) {
            $method = $payment->method === 'telebirr' ? 'Telebirr' : 'CBE Birr';
            $grade = $this->student->grade;
            $username = $this->student->telegram_username ? ' (@'.e($this->student->telegram_username).')' : '';
            $caption = "🧾 <b>New payment receipt #{$payment->id}</b>\n".
                'Student: '.e($this->student->display_name).$username."\n".
                'Grade: '.e($grade?->name ?? 'not selected')."\n".
                "Method: <b>{$method}</b>\nAmount: <b>{$payment->amount} birr</b>";

            $this->tg->sendPhoto((int) $adminChatId, $fileId, $caption, [
                [['text' => '✅ Approve', 'callback_data' => 'payment_approve:'.$payment->id]],
                [['text' => '❌ Reject', 'callback_data' => 'payment_reject:'.$payment->id]],
            ]);

            $reply = '✅ Receipt received. An admin will review it and message you here.';
        } else {
            $reply = '✅ Receipt saved, but the admin chat is not configured yet. Please contact support.';
        }

        $this->send($chatId, $reply, $this->menuKeyboard());
        $this->session->setState('menu', []);
    }

    /** Approve or reject a submitted payment from the configured admin account. */
    protected function reviewPayment(array $callback, string $action, int $paymentId): void
    {
        $adminId = config('telegram.admin_chat_id');
        $fromId = (int) ($callback['from']['id'] ?? 0);

        if (! $adminId || $fromId !== (int) $adminId) {
            $this->tg->answerCallbackQuery((string) ($callback['id'] ?? ''), 'You are not allowed to review payments.');

            return;
        }

        $payment = TelegramPayment::with('student')->find($paymentId);
        if (! $payment || $payment->status !== 'pending') {
            $this->tg->answerCallbackQuery((string) ($callback['id'] ?? ''), 'This receipt has already been reviewed.');

            return;
        }

        if ($action === 'payment_approve') {
            $startsAt = $payment->student->activated_until?->isFuture()
                ? $payment->student->activated_until
                : now();
            $validUntil = $startsAt->copy()->addDays(30);

            $payment->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'valid_until' => $validUntil,
            ]);
            $payment->student->update(['activated_until' => $validUntil]);

            $this->tg->sendMessage(
                (int) $payment->student->telegram_id,
                '✅ Your payment was approved. Your account is active until <b>'.e($validUntil->timezone('Africa/Addis_Ababa')->format('M j, Y')).'</b>.'
            );
            $this->tg->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Payment approved.');

            return;
        }

        $payment->update(['status' => 'rejected', 'reviewed_at' => now()]);
        $this->send(
            (int) $payment->student->telegram_id,
            '❌ We could not approve your receipt. Please send a clearer or correct receipt from To Activate.',
            [[['text' => '💰 To Activate', 'callback_data' => 'activate']]]
        );
        $this->tg->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Payment rejected.');
    }

    /** Explain monthly activation and direct the student to manual receipt review. */
    protected function showActivate(): void
    {
        $price = (int) config('telegram.payment.price', 150);
        $telebirr = config('telegram.payment.telebirr_number');
        $cbeAccount = config('telegram.payment.cbe_account');
        $cbeName = config('telegram.payment.cbe_account_name');
        $activeUntil = $this->student->activated_until;
        $status = $activeUntil && $activeUntil->isFuture()
            ? "✅ Your account is active until <b>".e($activeUntil->timezone('Africa/Addis_Ababa')->format('M j, Y'))."</b>. You can renew below.\n\n"
            : '';

        $paymentDetails = "Telebirr: <b>".e($telebirr ?: 'Please contact support for the number')."</b>\n".
            'CBE Birr: <b>'.e($cbeAccount ? trim(($cbeName ? $cbeName.' · ' : '').$cbeAccount) : 'Please contact support for the account').'</b>';

        $this->send(
            $this->chatId,
            "💰 <b>Activate your account</b>\n{$status}".
            "Pay <b>{$price} birr</b> for 30 days using one of these methods:\n{$paymentDetails}\n\n".
            'After paying, send your receipt screenshot manually to <b>'.e(config('telegram.payment.admin_username', '@J0519')).'</b>. An admin will review it and activate your account.',
            [
                [['text' => '☎️ Message support', 'url' => 'https://t.me/'.ltrim((string) config('telegram.payment.admin_username', '@J0519'), '@')]],
                [['text' => '🏠 Home', 'callback_data' => 'menu']],
            ]
        );
    }

    protected function requestReceipt(string $method): void
    {
        if (! in_array($method, ['telebirr', 'cbe_birr'], true)) {
            $this->showActivate();

            return;
        }

        $this->session->update([
            'state' => 'awaiting_payment_receipt',
            'context' => ['payment_method' => $method],
        ]);

        $label = $method === 'telebirr' ? 'Telebirr' : 'CBE Birr';
        $this->send(
            $this->chatId,
            "📷 Please send a clear photo of your <b>{$label}</b> payment receipt.\n\nAn admin will review it and message you here.",
            [[['text' => 'Cancel', 'callback_data' => 'activate']]]
        );
    }

    /** Random questions: pick a subject and chapter to draw random questions. */
    protected function showRandomQuestions(): void
    {
        if (! empty($this->session->context['subject_id'])) {
            $this->session->putContext(['scope' => 'subject', 'chapter_id' => null]);
            $this->showQuestionTiers(0, 'random');
            return;
        }
        $this->showSubjects('random');
    }

    /** Model exam: pick a subject, chapter, and year to take a model exam. */
    protected function showModelExam(): void
    {
        if (! empty($this->session->context['subject_id'])) {
            $this->session->putContext(['scope' => 'subject', 'chapter_id' => null]);
            $this->showExamYear(0, 'model');
            return;
        }
        $this->showSubjects('model');
    }

    /** National exam: pick a subject, chapter, and year to take the national exam. */
    protected function showNationalExam(): void
    {
        if (! empty($this->session->context['subject_id'])) {
            $this->session->putContext(['scope' => 'subject', 'chapter_id' => null]);
            $this->showExamYear(0, 'national');
            return;
        }
        $this->showSubjects('national');
    }

    protected function showPracticeSources(): void
    {
        $this->send($this->chatId, '✏️ <b>Practice Questions</b>\nChoose a question set:', [
            [['text' => '🎲 All Random', 'callback_data' => 'random']],
            [['text' => '📝 Model Exams', 'callback_data' => 'model']],
            [['text' => '🏛️ National Exams', 'callback_data' => 'national']],
            [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.(int) ($this->session->context['subject_id'] ?? 0)]],
            [['text' => '🏠 Home', 'callback_data' => 'menu']],
        ]);
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

        $buttons = $subjects->map(fn ($s) => [
            'text' => $s->name,
            'callback_data' => 's:'.$s->id,
        ])->all();
        $keyboard = array_chunk($buttons, 2);
        $keyboard[] = [['text' => '◀️ Back to Home', 'callback_data' => 'menu']];

        $this->send($this->chatId, e($this->student->grade?->name ?? 'Grade')." — Choose a subject 👇", $keyboard);
        $this->session->setState('choose_subject');
    }

    protected function showChapters(int $subjectId, ?string $mode = null): void
    {
        $subject = Subject::where('grade_id', $this->student->grade_id)->find($subjectId);

        if (! $subject) {
            $this->showMenu();

            return;
        }

        $mode ??= $this->session->context['mode'] ?? 'study';
        $this->session->putContext(['subject_id' => $subject->id, 'subject_name' => $subject->name, 'mode' => $mode]);

        if (in_array($mode, ['random', 'model', 'national'], true)) {
            $this->session->putContext(['scope' => 'subject', 'chapter_id' => null]);
            $mode === 'random'
                ? $this->showQuestionTiers(0, $mode)
                : $this->showExamYear(0, $mode);

            return;
        }

        $this->session->putContext(['scope' => 'chapter']);

        $chapters = Chapter::where('subject_id', $subject->id)
            ->where('is_active', true)->orderBy('order')->orderBy('id')->get(['id', 'title', 'order', 'requires_activation', 'telegraph_url', 'pdf_file']);

        if ($chapters->isEmpty()) {
            $this->send($this->chatId, 'No chapters yet in <b>'.e($subject->name).'</b>.', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $keyboard = [];
        foreach ($chapters as $index => $chapter) {
            $number = $index + 1;
            $keyboard[] = [
                ['text' => ($chapter->requires_activation ? '🔒 ' : '')."Chapter {$number} · Notes", 'callback_data' => 'chapter_notes:'.$chapter->id],
                ['text' => ($chapter->requires_activation ? '🔒 ' : '')."Chapter {$number} · Quiz (10 Q)", 'callback_data' => 'chapter_quiz:'.$chapter->id],
            ];
        }
        $keyboard[] = [['text' => 'Practice Questions', 'callback_data' => 'practice_questions']];
        $keyboard[] = [['text' => '◀️ Back to subjects', 'callback_data' => 'subjects']];

        $this->send($this->chatId, '📖 <b>'.e($subject->name)."</b>\nChoose notes or a chapter quiz:", $keyboard);
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
        $this->session->putContext(['tier' => null, 'mode' => $mode]);
        $this->showTierSelector($mode);
    }

    /** Year selector for model/national exam, then tier. */
    protected function showExamYear(int $chapterId, string $examType): void
    {
        $this->session->putContext(['tier' => null, 'mode' => $examType]);
        $years = $this->questionPoolQuery($examType)
            ->select('year')->distinct()->whereNotNull('year')->orderBy('year')->pluck('year')->all();

        if (! $years) {
            $this->send($this->chatId, 'No '.e($examType).' exam questions are available for this subject yet.', [
                [['text' => '◀️ Back', 'callback_data' => 'mode:'.$examType]],
                [['text' => '🏠 Home', 'callback_data' => 'menu']],
            ]);

            return;
        }

        $buttons = array_map(fn ($year) => [
            'text' => $year.' E.C.',
            'callback_data' => 'year:'.$year,
        ], $years);
        $keyboard = array_chunk($buttons, 2);
        $keyboard[] = [['text' => '◀️ Back', 'callback_data' => 'mode:'.$examType]];
        $keyboard[] = [['text' => '🏠 Home', 'callback_data' => 'menu']];

        $this->send(
            $this->chatId,
            '📝 <b>'.e($this->session->context['subject_name'] ?? '').' · '.e(ucfirst($examType))." Exams</b>\n\nChoose a year:",
            $keyboard
        );
    }

    /** Question-tier keyboard (10 / 30 / 50 / 100). */
    protected function tierKeyboard(array $availableSizes): array
    {
        $buttons = [];
        foreach ($availableSizes as $size) {
            $locked = ! $this->isActivated() && $size > 10;
            $buttons[] = [
                'text' => $size.' questions'.($locked ? ' 🔒' : ''),
                'callback_data' => 'tier:'.$size,
            ];
        }
        $keyboard = array_chunk($buttons, 2);
        $keyboard[] = [['text' => '◀️ Back', 'callback_data' => 'mode:'.($this->session->context['mode'] ?? 'practice')]];
        $keyboard[] = [['text' => '🏠 Home', 'callback_data' => 'menu']];

        return $keyboard;
    }

    protected function showTierSelector(string $mode): void
    {
        $query = $this->questionPoolQuery($mode, $this->session->context['year'] ?? null);
        $available = (int) (clone $query)->count();
        $sizes = array_values(array_filter([10, 30, 50, 100], fn ($size) => $available >= $size));

        if (! $sizes) {
            $this->send($this->chatId, 'There are not enough questions in this set yet.', [
                [['text' => '◀️ Back', 'callback_data' => 'mode:'.$mode]],
                [['text' => '🏠 Home', 'callback_data' => 'menu']],
            ]);

            return;
        }

        $source = match ($mode) {
            'model' => 'Model exam',
            'national' => 'National exam',
            default => 'Random practice',
        };
        $year = $this->session->context['year'] ?? null;
        $title = e($this->session->context['subject_name'] ?? 'Questions');
        $this->send(
            $this->chatId,
            "🎯 <b>{$title} · {$source}".($year ? ' '.e((string) $year).' E.C.' : '')."</b>\n\n".
            'Choose the number of questions:'.(! $this->isActivated() ? "\n🔒 Larger sets require activation. Free students can answer up to ".(int) config('telegram.free_daily_limit', 10)." questions per day." : ''),
            $this->tierKeyboard($sizes)
        );
    }

    protected function questionPoolQuery(?string $mode = null, ?int $year = null): Builder
    {
        $mode ??= $this->session->context['mode'] ?? 'random';
        $query = Question::query()
            ->where('is_active', true)
            ->where('grade_id', $this->student->grade_id)
            ->where('subject_id', $this->session->context['subject_id'] ?? 0)
            ->whereHas('options')
            ->whereHas('options', fn ($options) => $options->where('is_correct', true));

        if (! $this->isActivated()) {
            $freeChapterIds = Chapter::where('subject_id', $this->session->context['subject_id'] ?? 0)
                ->where('is_active', true)
                ->where('requires_activation', false)
                ->pluck('id');
            $query->whereIn('chapter_id', $freeChapterIds);
        }

        if (($this->session->context['scope'] ?? 'subject') === 'chapter'
            && ! empty($this->session->context['chapter_id'])) {
            $query->where('chapter_id', $this->session->context['chapter_id']);
        }

        if (in_array($mode, ['model', 'national'], true)) {
            $query->where('source', 'like', '%'.$mode.'%');
        }

        if ($year !== null) {
            $query->where('year', $year);
        }

        return $query;
    }

    protected function quizMetadata(): array
    {
        return array_intersect_key($this->session->context ?? [], array_flip([
            'subject_id', 'subject_name', 'chapter_id', 'scope', 'year', 'tier',
        ]));
    }

    /** Select a question tier (10 / 30 / 50 / 100). */
    protected function selectTier(int $tier): void
    {
        if (! in_array($tier, [10, 30, 50, 100], true)) {
            $this->showMenu();

            return;
        }
        if ($tier > 10 && ! $this->isActivated()) {
            $this->showActivate();

            return;
        }

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
        if (! $this->questionPoolQuery((string) ($this->session->context['mode'] ?? ''), $year)->exists()) {
            $this->showMenu();

            return;
        }

        $this->session->putContext(['year' => $year]);

        $mode = $this->session->context['mode'] ?? 'study';
        if (! in_array($mode, ['model', 'national'], true)) {
            $this->showMenu();

            return;
        }

        $this->showQuestionTiers((int) ($this->session->context['chapter_id'] ?? 0), $mode);
    }

    /** Draw random questions from a chapter (respecting the selected tier). */
    protected function drawRandomQuestions(int $chapterId): void
    {
        $tier = $this->session->context['tier'] ?? 10;
        $questions = $this->questionPoolQuery('random')->with('options')->inRandomOrder()->take($tier)->get();
        $this->startQuestionSet($questions, 'Random practice', 'random', 'practice', 'practice', $this->quizMetadata());
    }

    /** Draw a model exam paper (respecting year and tier). */
    protected function drawModelExam(int $chapterId, int $year): void
    {
        $tier = $this->session->context['tier'] ?? 10;
        $questions = $this->questionPoolQuery('model', $year)->with('options')->inRandomOrder()->take($tier)->get();
        $this->startQuestionSet($questions, "Model exam {$year} E.C.", 'model', 'practice', 'practice', $this->quizMetadata());
    }

    /** Draw a national exam paper (respecting year and tier). */
    protected function drawNationalExam(int $chapterId, int $year): void
    {
        $tier = $this->session->context['tier'] ?? 10;
        $questions = $this->questionPoolQuery('national', $year)->with('options')->inRandomOrder()->take($tier)->get();
        $this->startQuestionSet($questions, "National exam {$year} E.C.", 'national', 'practice', 'practice', $this->quizMetadata());
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

        $buttons = $notes->map(fn ($n) => [
            'text' => '📄 '.$n->title,
            'callback_data' => 'n:'.$n->id,
        ])->all();
        $keyboard = array_chunk($buttons, 2);
        $keyboard[] = [['text' => '◀️ Chapters', 'callback_data' => 's:'.$chapter->subject_id]];
        $keyboard[] = [['text' => '🏠 Menu', 'callback_data' => 'menu']];

        $this->send(
            $this->chatId,
            '📚 <b>'.e($chapter->title).'</b>'."\nSelect study notes 👇",
            $keyboard
        );
        $this->session->setState('study_notes');
    }

    protected function openChapterNote(int $chapterId): void
    {
        $chapter = Chapter::whereKey($chapterId)
            ->where('is_active', true)
            ->whereHas('subject', fn ($query) => $query->where('grade_id', $this->student->grade_id)->where('is_active', true))
            ->first();
        if (! $chapter) {
            $this->showMenu();
            return;
        }
        if ($chapter->requires_activation && ! $this->isActivated()) {
            $this->send($this->chatId,
                '🔒 <b>This chapter requires account activation.</b> Activate your account to open its notes and quizzes.',
                [
                    [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                    [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.$chapter->subject_id]],
                    [['text' => '🏠 Home', 'callback_data' => 'menu']],
                ]
            );
            return;
        }
        if ($chapter->telegraph_url) {
            $keyboard = [[['text' => '📖 Read chapter notes', 'url' => $chapter->telegraph_url]]];
            if ($chapter->pdf_file) {
                $keyboard[] = [['text' => $this->isActivated() ? '⬇ Download chapter PDF' : '🔒 Chapter PDF (activate)', 'callback_data' => 'chapter_pdf:'.$chapter->id]];
            }
            $keyboard[] = [['text' => '📝 Take chapter quiz', 'callback_data' => 'chapter_quiz:'.$chapter->id]];
            $keyboard[] = [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.$chapter->subject_id]];
            $keyboard[] = [['text' => '🏠 Home', 'callback_data' => 'menu']];
            $this->send($this->chatId, '📚 <b>Chapter '.($chapter->order ?: '').' · '.e($chapter->title)."</b>\nRead the notes or download the PDF.", $keyboard);
            return;
        }
        $note = Note::where('chapter_id', $chapterId)
            ->where('grade_id', $this->student->grade_id)
            ->orderBy('page_number')->orderBy('order')->orderBy('id')->first();
        if (! $note) {
            $this->send($this->chatId, 'No notes are available for this chapter yet.', [
            [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.(int) ($this->session->context['subject_id'] ?? 0)]],
                [['text' => '🏠 Home', 'callback_data' => 'menu']],
            ]);
            return;
        }
        $this->showNote((int) $note->id);
    }

    protected function showNote(int $noteId): void
    {
        $note = Note::where('grade_id', $this->student->grade_id)->find($noteId);

        if (! $note) {
            $this->showMenu();

            return;
        }

        $chapter = Chapter::whereKey($note->chapter_id)->first();
        if (! $chapter || ! $chapter->is_active) {
            $this->showMenu();
            return;
        }
        if ($chapter->requires_activation && ! $this->isActivated()) {
            $this->send($this->chatId,
                '🔒 <b>This chapter requires account activation.</b> Activate your account to open its notes and quizzes.',
                [
                    [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                    [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.$chapter->subject_id]],
                    [['text' => '🏠 Home', 'callback_data' => 'menu']],
                ]
            );
            return;
        }

        $siblings = Note::where('chapter_id', $note->chapter_id)
            ->where('grade_id', $this->student->grade_id)
            ->orderBy('page_number')->orderBy('order')->orderBy('id')->get(['id', 'title']);
        $index = $siblings->search(fn ($item) => $item->id === $note->id);
        $previous = $index !== false && $index > 0 ? $siblings[$index - 1] : null;
        $next = $index !== false ? ($siblings[$index + 1] ?? null) : null;

        $keyboard = [];
        $navigation = [];
        if ($previous) {
            $navigation[] = ['text' => '◀ Previous', 'callback_data' => 'note_prev:'.$previous->id];
        }
        if ($next) {
            $navigation[] = ['text' => 'Next ▶', 'callback_data' => 'note_next:'.$next->id];
        }
        if ($navigation) {
            $keyboard[] = $navigation;
        }
        if ($note->pdf_file) {
            $keyboard[] = [['text' => '⬇ Download PDF notes', 'callback_data' => 'pdf:'.$note->id]];
        }
        $keyboard[] = [['text' => '📝 Take chapter quiz', 'callback_data' => 'chapter_quiz:'.$note->chapter_id]];
        $keyboard[] = [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.$note->chapter?->subject_id]];
        $keyboard[] = [['text' => '🏠 Home', 'callback_data' => 'menu']];

        $page = $index === false ? 1 : $index + 1;
        $noteText = '📄 <b>'.e($note->title)."</b> · Page {$page}/".$siblings->count()."\n\n".e($note->content);
        if ($this->callbackMessageId > 0 && in_array(($this->session->state ?? ''), ['view_note'], true)) {
            $this->tg->editMessage($this->chatId, $this->callbackMessageId, $noteText, $keyboard);
        } else {
            $this->sendLong($this->chatId, $noteText, $keyboard);
        }

        if ($note->image_file) {
            $this->tg->sendPhoto($this->chatId, $this->publicFileUrl($note->image_file), '');
        }
        $this->session->setState('view_note');
    }

    protected function downloadNotePdf(int $noteId): void
    {
        $note = Note::where('grade_id', $this->student->grade_id)->find($noteId);
        if (! $note || ! $note->pdf_file) {
            $this->send($this->chatId, 'There is no PDF file available for this note yet.', $this->menuKeyboard());

            return;
        }

        if (! $this->isActivated()) {
            $this->send($this->chatId, '🔒 Downloadable PDF notes are available to activated students.', [
                [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                [['text' => '◀️ Back to note', 'callback_data' => 'n:'.$note->id]],
            ]);

            return;
        }

        $this->tg->sendDocument($this->chatId, $this->publicFileUrl($note->pdf_file), $note->title);
    }

    protected function downloadChapterPdf(int $chapterId): void
    {
        $chapter = Chapter::whereKey($chapterId)->where('is_active', true)
            ->whereHas('subject', fn ($query) => $query->where('grade_id', $this->student->grade_id)->where('is_active', true))
            ->first();
        if (! $chapter || ! $chapter->pdf_file) {
            $this->send($this->chatId, 'There is no chapter PDF available yet.', $this->menuKeyboard());
            return;
        }
        if (! $this->isActivated()) {
            $this->send($this->chatId, '🔒 Downloadable chapter PDFs are available to activated students.', [
                [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                [['text' => '◀️ Back to chapter', 'callback_data' => 'chapter_notes:'.$chapter->id]],
            ]);
            return;
        }
        $this->tg->sendDocument($this->chatId, $this->publicFileUrl($chapter->pdf_file), 'Chapter '.($chapter->order ?: '').' · '.$chapter->title);
    }

    protected function publicFileUrl(string $path): string
    {
        return app(\App\Services\SupabasePublicStorage::class)->url($path);
    }

    /* ------------------------------------------------------------------ */
    /* Practice + mistake review (spec §7, §11)                            */
    /* ------------------------------------------------------------------ */

    protected function startPractice(int $chapterId, string $state = 'practice', string $context = 'practice'): void
    {
        if (! $this->requireGrade()) {
            return;
        }

        $chapter = Chapter::whereKey($chapterId)
            ->where('is_active', true)
            ->whereHas('subject', fn ($q) => $q->where('grade_id', $this->student->grade_id)->where('is_active', true))
            ->first();

        if (! $chapter) {
            $this->showMenu();

            return;
        }

        if ($chapter->requires_activation && ! $this->isActivated()) {
            $chapterNumber = $this->chapterNumber($chapter);
            $this->send($this->chatId,
                '🔒 <b>Chapter '.($chapterNumber)." quiz requires activation.</b>\n\n".
                'Chapter quizzes for Chapters 1–3 are available to free students. Activate your account to practise from Chapter 4 onward.',
                [
                    [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                    [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.$chapter->subject_id]],
                    [['text' => '🏠 Home', 'callback_data' => 'menu']],
                ]
            );
            return;
        }

        if (! $this->isActivated() && $this->dailyQuestionsRemaining() <= 0) {
            $this->showDailyLimit();

            return;
        }

        $limit = $this->isActivated()
            ? 10
            : min(10, $this->dailyQuestionsRemaining());
        $questions = Question::where('chapter_id', $chapterId)
            ->where('grade_id', $this->student->grade_id)
            ->where('is_active', true)
            ->with('options')
            ->inRandomOrder()
            ->get()
            ->filter(fn ($q) => $q->options->count() >= 2 && $q->options->contains('is_correct', true))
            ->take($limit)
            ->values();

        if ($questions->isEmpty()) {
            $this->send($this->chatId, 'No practice questions are available for this chapter yet. Stay tuned! 🎯', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $this->startQuestionSet($questions, 'Practice · '.($chapter?->title ?? ''), 'chapter', $state, $context, [
            'chapter_id' => $chapterId,
            'subject_id' => $chapter?->subject_id,
            'subject_name' => $chapter?->subject?->name,
        ]);
    }

    /** Chapter numbering follows the ordered active list shown to students. */
    protected function chapterNumber(Chapter $chapter): int
    {
        return Chapter::where('subject_id', $chapter->subject_id)
            ->where('is_active', true)
            ->where(function ($query) use ($chapter) {
                $query->where('order', '<', $chapter->order)
                    ->orWhere(function ($query) use ($chapter) {
                        $query->where('order', $chapter->order)->where('id', '<', $chapter->id);
                    });
            })
            ->count() + 1;
    }

    /** Start a one-question-at-a-time quiz from a prepared, random question set. */
    protected function startQuestionSet($questions, string $title, string $quizMode, string $state = 'practice', string $contextType = 'practice', array $metadata = []): void
    {
        $questions = $questions->filter(fn ($q) => $q->options->count() >= 2 && $q->options->contains('is_correct', true))->values();

        if (! $this->isActivated()) {
            $remaining = $this->dailyQuestionsRemaining();
            if ($remaining <= 0) {
                $this->showDailyLimit();

                return;
            }
            $questions = $questions->take($remaining)->values();
        }

        if ($questions->isEmpty()) {
            $this->send($this->chatId, 'No questions are available in this set yet.', $this->menuKeyboard());
            $this->session->setState('menu');

            return;
        }

        $this->session->update([
            'state' => $state,
            'context' => array_merge($metadata, [
                'mode' => $state === 'mistakes' ? 'mistakes' : 'practice',
                'quiz_mode' => $quizMode,
                'quiz_title' => $title,
                'context' => $contextType,
                'qids' => $questions->pluck('id')->all(),
                'idx' => 0,
                'correct' => 0,
                'answered' => 0,
                'wrong_qids' => [],
                'answers' => [],
                'awaiting' => true,
            ]),
        ]);

        $this->sendPracticeQuestion();
    }

    protected function isActivated(): bool
    {
        return $this->student->activated_until?->isFuture() ?? false;
    }

    protected function dailyQuestionsRemaining(): int
    {
        $localStart = now('Africa/Addis_Ababa')->startOfDay()->setTimezone('UTC');
        $answered = QuestionAttempt::where('student_id', $this->student->id)
            ->where('answered_at', '>=', $localStart)
            ->count();

        return max(0, (int) config('telegram.free_daily_limit', 10) - $answered);
    }

    protected function showDailyLimit(): void
    {
        $this->send(
            $this->chatId,
            '⏳ <b>You have used your '.(int) config('telegram.free_daily_limit', 10).' free questions for today.</b>\n\n'.
            'Come back tomorrow, or activate your account to practise without a daily limit.',
            [
                [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                [['text' => '📚 Read chapter notes', 'callback_data' => 'mode:study']],
                [['text' => '🏠 Home', 'callback_data' => 'menu']],
            ]
        );
        $this->session->setState('menu');
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

        $questions = Question::with('options')->whereIn('id', $mistakes->pluck('question_id'))->get();
        $this->startQuestionSet($questions, 'Mistake review', 'mistakes', 'mistakes', 'mistake');
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
        $prefix = ($context['context'] ?? '') === 'mistake' ? '❌ Review' : '✏️ '.e($context['quiz_title'] ?? 'Practice');

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
        $context['answered'] = ($context['answered'] ?? 0) + 1;
        $context['answers'][$question->id] = $correct;
        if (! $correct) {
            $context['wrong_qids'] = array_values(array_unique(array_merge($context['wrong_qids'] ?? [], [$question->id])));
        }
        $context['awaiting'] = false;
        $this->session->update(['context' => $context]);

        $questionNumber = (int) ($context['idx'] ?? 0) + 1;
        $questionTotal = count($context['qids'] ?? []);
        $lines = [
            '✏️ <b>'.e($context['quiz_title'] ?? 'Practice')." · Question {$questionNumber}/{$questionTotal}</b>",
            e($question->question_text),
        ];
        foreach ($question->options as $answerOption) {
            $marker = $answerOption->is_correct ? '✅ ' : ($answerOption->id === $option->id ? '❌ ' : '');
            $lines[] = $marker.'<b>'.$answerOption->label.')</b> '.e($answerOption->text);
        }
        $lines[] = $correct ? "\n✅ <b>Correct!</b>" : "\n❌ <b>Not correct.</b>";

        $correctOption = $question->correctOption();
        if ($correctOption) {
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

        if ($this->callbackMessageId > 0) {
            $this->tg->editMessage($this->chatId, $this->callbackMessageId, implode("\n", $lines), $keyboard);
        } else {
            $this->send($this->chatId, implode("\n", $lines), $keyboard);
        }
    }

    protected function nextQuestion(): void
    {
        $state = $this->session->state;

        if (! in_array($state, ['practice', 'mistakes', 'exam'], true)) {
            $this->showMenu();

            return;
        }

        if (in_array($state, ['practice', 'mistakes'], true)
            && ! $this->isActivated()
            && $this->dailyQuestionsRemaining() <= 0) {
            $this->finishPractice(true);

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

    protected function finishPractice(bool $dailyLimitReached = false): void
    {
        $context = $this->session->context;
        $total = (int) ($context['answered'] ?? 0);
        $correct = (int) ($context['correct'] ?? 0);
        $pct = $total > 0 ? (int) round($correct / $total * 100) : 0;
        $isMistakes = ($context['context'] ?? '') === 'mistake';

        $heading = $isMistakes
            ? '❌ <b>Mistake review complete!</b>'
            : '📘 <b>Practice complete!</b>';

        $answers = $context['answers'] ?? [];
        $questions = Question::with('chapter')->whereIn('id', array_keys($answers))->get()->keyBy('id');
        $byChapter = [];
        foreach ($answers as $questionId => $wasCorrect) {
            $question = $questions->get((int) $questionId);
            if (! $question) {
                continue;
            }
            $chapterId = $question->chapter_id;
            $byChapter[$chapterId] ??= ['title' => $question->chapter?->title ?? 'Unknown chapter', 'total' => 0, 'correct' => 0];
            $byChapter[$chapterId]['total']++;
            $byChapter[$chapterId]['correct'] += $wasCorrect ? 1 : 0;
        }

        $keyboard = [];
        $weakest = null;
        if ($byChapter) {
            $lines[] = "\n<b>By chapter:</b>";
            foreach ($byChapter as $chapterId => $summary) {
                $chapterPct = (int) round($summary['correct'] / $summary['total'] * 100);
                $lines[] = '• '.e($summary['title']).': '.$summary['correct'].'/'.$summary['total'].' ('.$chapterPct.'%)';
                if ($weakest === null || $chapterPct < $weakest['accuracy']) {
                    $weakest = ['id' => $chapterId, 'title' => $summary['title'], 'accuracy' => $chapterPct];
                }
            }
        }

        if (! empty($context['wrong_qids'])) {
            $keyboard[] = [['text' => '📋 Review mistakes ('.count($context['wrong_qids']).')', 'callback_data' => 'review']];
        }
        if ($weakest && ! $isMistakes) {
            $lines[] = "\nWeakest chapter: <b>".e($weakest['title']).'</b>';
            $keyboard[] = [['text' => 'Practice '.$weakest['title'], 'callback_data' => 'chapter_quiz:'.$weakest['id']]];
        }

        if (! $isMistakes) {
            $keyboard[] = [['text' => '🔁 Try again', 'callback_data' => 'quiz_retry']];
        }

        if ($isMistakes) {
            $keyboard[] = [['text' => '❌ More mistakes', 'callback_data' => 'mistakes']];
        }

        if ($dailyLimitReached || (! $this->isActivated() && $this->dailyQuestionsRemaining() <= 0)) {
            $lines[] = "\n⏳ You have used your free questions for today.";
            $keyboard[] = [['text' => '💰 To Activate', 'callback_data' => 'activate']];
        }
        $keyboard[] = [['text' => '📊 Progress', 'callback_data' => 'progress']];
        $keyboard[] = [['text' => '🏠 Menu', 'callback_data' => 'menu']];

        if (! $isMistakes && $total > 0 && empty($context['score_awarded'])) {
            $this->progress->awardQuizPoints($this->student, $correct);
            $context['score_awarded'] = true;
        }

        $this->send(
            $this->chatId,
            "{$heading}\n".e($context['quiz_title'] ?? '')."\nScore: <b>{$correct}/{$total}</b> ({$pct}%)",
            $keyboard
        );
        $this->session->setState('menu', ['mode' => 'practice'] + $context);
    }

    protected function retryQuestionSet(): void
    {
        $context = $this->session->context;
        $mode = $context['quiz_mode'] ?? 'chapter';
        if ($mode === 'chapter' && ! empty($context['chapter_id'])) {
            $this->startPractice((int) $context['chapter_id']);

            return;
        }

        $query = $this->questionPoolQuery($mode, $context['year'] ?? null);
        $questions = $query->with('options')->inRandomOrder()->take((int) ($context['tier'] ?? 10))->get();
        $this->startQuestionSet($questions, $context['quiz_title'] ?? 'Practice', $mode, 'practice', 'practice', [
            'subject_id' => $context['subject_id'] ?? null,
            'subject_name' => $context['subject_name'] ?? null,
            'scope' => $context['scope'] ?? 'subject',
            'year' => $context['year'] ?? null,
            'tier' => $context['tier'] ?? 10,
        ]);
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

        $chapter = Chapter::find($chapterId);
        if (! $this->isActivated() && ($type === 'mock' || ($chapter && $chapter->requires_activation))) {
            $this->send($this->chatId,
                '🔒 <b>This exam requires account activation.</b>\n\nFree quizzes are available for Chapters 1–3.',
                [
                    [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                    [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.(int) ($this->session->context['subject_id'] ?? 0)]],
                    [['text' => '🏠 Home', 'callback_data' => 'menu']],
                ]
            );
            return;
        }

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

        $buttons = $exams->map(fn ($e) => [
            'text' => '📝 '.$e->title,
            'callback_data' => 'e:'.$e->id,
        ])->all();
        $keyboard = array_chunk($buttons, 2);
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

        if (! $this->isActivated()) {
            $examChapter = $exam->chapter_id ? Chapter::find($exam->chapter_id) : null;
            if ($exam->type === 'mock' || ! $examChapter || $examChapter->requires_activation) {
                $this->send($this->chatId,
                    '🔒 <b>This exam requires account activation.</b>\n\nFree quizzes are available for Chapters 1–3.',
                    [
                        [['text' => '💰 To Activate', 'callback_data' => 'activate']],
                        [['text' => '◀️ Back to chapters', 'callback_data' => 's:'.$exam->subject_id]],
                        [['text' => '🏠 Home', 'callback_data' => 'menu']],
                    ]
                );
                return;
            }
        }

        if (! $this->isActivated() && $this->dailyQuestionsRemaining() <= 0) {
            $this->showDailyLimit();

            return;
        }

        $attempt = $this->exams->startAttempt($this->student, $exam);

        // Free students may answer only the questions remaining in today's allowance.
        // Keep already answered questions when resuming an in-progress attempt.
        if (! $this->isActivated()) {
            $questionIds = array_map('intval', $attempt->question_ids ?? []);
            $answers = $attempt->answers ?? [];
            $answeredIds = array_map('intval', array_keys($answers));
            $remaining = $this->dailyQuestionsRemaining();
            $allowed = max(count($answeredIds), min(count($questionIds), count($answeredIds) + $remaining));
            if ($allowed < count($questionIds)) {
                $keep = array_slice($questionIds, 0, $allowed);
                // Preserve answered IDs even if their position is later in the paper.
                $keep = array_values(array_unique(array_merge($answeredIds, $keep)));
                $attempt->question_ids = $keep;
                $attempt->total_questions = count($keep);
                $attempt->save();
            }
        }

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

        $context = $this->session->context ?? [];
        if ($attempt->status === 'completed' && (int) ($context['scored_attempt_id'] ?? 0) !== $attempt->id) {
            $this->progress->awardQuizPoints($this->student, (int) $attempt->correct_answers);
            $context['scored_attempt_id'] = $attempt->id;
        }

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
            'context' => array_merge($context, [
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
        $wrong = $this->session->context['wrong'] ?? $this->session->context['wrong_qids'] ?? [];

        if (! $attempt && ! empty($wrong)) {
            $questions = Question::with('options')->whereIn('id', $wrong)->get();
            $this->startQuestionSet($questions, 'Mistake review', 'mistakes', 'mistakes', 'mistake', [
                'subject_id' => $this->session->context['subject_id'] ?? null,
                'subject_name' => $this->session->context['subject_name'] ?? null,
                'scope' => $this->session->context['scope'] ?? 'subject',
                'chapter_id' => $this->session->context['chapter_id'] ?? null,
            ]);

            return;
        }

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
        $announcements = Announcement::published()
            ->where(function ($query) {
                $query->where('audience', 'all')
                    ->orWhere(fn ($grade) => $grade->where('audience', 'grade')->where('grade_id', $this->student->grade_id))
                    ->orWhere('audience', 'activated');
            })
            ->when(! $this->isActivated(), fn ($query) => $query->where('audience', '!=', 'activated'))
            ->limit(3)->get();

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
        $keyboard = $question->options->map(fn ($o) => [[
            'text' => $o->label.') '.mb_substr(trim($o->text), 0, 40),
            'callback_data' => 'o:'.$o->id,
        ]])->all();
        $keyboard[] = [['text' => '⏹ Stop quiz', 'callback_data' => 'stop']];

        return $keyboard;
    }

    protected function resumeKeyboard(): array
    {
        return [[['text' => '🏠 Menu', 'callback_data' => 'menu']]];
    }

    protected function send(int $chatId, string $text, ?array $keyboard = null): void
    {
        $chunks = $this->splitMessage($text);
        $replyKeyboard = null;

        if ($keyboard !== null) {
            $replyKeyboard = [];
            $actions = [];
            foreach ($keyboard as $row) {
                $replyRow = [];
                foreach ($row as $button) {
                    $label = (string) ($button['text'] ?? '');
                    if ($label === '') {
                        continue;
                    }
                    $replyRow[] = ['text' => $label];
                    if (isset($button['callback_data'])) {
                        $actions[$label] = (string) $button['callback_data'];
                    } elseif (isset($button['url'])) {
                        $actions[$label] = 'url:'.(string) $button['url'];
                    }
                }
                if ($replyRow) {
                    $replyKeyboard[] = $replyRow;
                }
            }
            Cache::put('telegram.reply_keyboard.'.$chatId, $actions, now()->addDays(30));
        }

        foreach ($chunks as $i => $chunk) {
            $isLast = $i === count($chunks) - 1;
            $this->tg->sendReplyMessage($chatId, $chunk, $isLast ? $replyKeyboard : null);
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
