<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Chapter;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Grade;
use App\Models\Note;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\Referral;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /** GET /api/v1/dashboard — headline stats per spec §19 (Admin Analytics). */
    public function index(): JsonResponse
    {
        $answered = QuestionAttempt::query();

        $totalAnswered = (clone $answered)->count();
        $totalCorrect = (clone $answered)->where('is_correct', true)->count();

        $mockCompleted = ExamAttempt::where('status', 'completed')
            ->whereHas('exam', fn ($q) => $q->where('type', 'mock'))
            ->count();

        $testsCompleted = ExamAttempt::where('status', 'completed')
            ->whereHas('exam', fn ($q) => $q->where('type', 'chapter_test'))
            ->count();

        return response()->json([
            'students' => [
                'total' => Student::count(),
                'active' => Student::where('is_active', true)->count(),
                'activated' => Student::where('activated_until', '>', now())->count(),
                'new_this_week' => Student::where('created_at', '>=', now()->subWeek())->count(),
            ],
            'referrals' => [
                'total' => Referral::count(),
                'qualified' => Referral::whereNotNull('qualified_at')->count(),
            ],
            'content' => [
                'questions' => Question::count(),
                'announcements' => Announcement::count(),
            ],
            'inventory' => [
                'grades' => [
                    'total' => Grade::count(),
                    'available' => Grade::where('is_active', true)->count(),
                    'description' => 'Visible grade choices in Telegram',
                ],
                'subjects' => [
                    'total' => Subject::count(),
                    'available' => Subject::where('is_active', true)
                        ->whereHas('grade', fn ($q) => $q->where('is_active', true))->count(),
                    'description' => 'Active subjects under visible grades',
                ],
                'chapters' => [
                    'total' => Chapter::count(),
                    'available' => Chapter::where('is_active', true)
                        ->whereHas('subject', fn ($q) => $q->where('is_active', true)
                            ->whereHas('grade', fn ($g) => $g->where('is_active', true)))->count(),
                    'description' => 'Active chapters under visible subjects',
                ],
                'notes' => [
                    'total' => Note::count(),
                    'available' => Note::whereHas('grade', fn ($q) => $q->where('is_active', true))
                        ->whereHas('chapter', fn ($q) => $q->where('is_active', true)
                            ->whereHas('subject', fn ($s) => $s->where('is_active', true)))->count(),
                    'description' => 'Notes reachable from active chapters',
                ],
                'questions' => [
                    'total' => Question::count(),
                    'available' => Question::where('is_active', true)
                        ->whereHas('grade', fn ($q) => $q->where('is_active', true))
                        ->whereHas('subject', fn ($q) => $q->where('is_active', true))
                        ->whereHas('chapter', fn ($q) => $q->where('is_active', true))
                        ->whereHas('options', null, '>=', 2)
                        ->whereHas('options', fn ($q) => $q->where('is_correct', true))->count(),
                    'description' => 'Active questions with answer choices',
                ],
                'topics' => [
                    'total' => Topic::count(),
                    'available' => Topic::whereHas('chapter', fn ($q) => $q->where('is_active', true)
                        ->whereHas('subject', fn ($s) => $s->where('is_active', true)))->count(),
                    'description' => 'Optional topic labels under active chapters',
                ],
                'exams' => [
                    'total' => Exam::count(),
                    'available' => Exam::where('is_active', true)->count(),
                    'description' => 'Active records; not linked to current Telegram menus',
                ],
                'students' => [
                    'total' => Student::count(),
                    'available' => Student::where('is_active', true)->count(),
                    'description' => 'Registered Telegram students',
                ],
                'results' => [
                    'total' => ExamAttempt::where('status', 'completed')->count(),
                    'available' => ExamAttempt::where('status', 'completed')->count(),
                    'description' => 'Completed exam attempts in Results',
                ],
            ],
            'activity' => [
                'questions_answered' => $totalAnswered,
                'accuracy' => $totalAnswered > 0 ? round($totalCorrect / $totalAnswered * 100, 1) : 0,
                'mock_exams_completed' => $mockCompleted,
                'chapter_tests_completed' => $testsCompleted,
            ],
            'popular_subjects' => $this->popularSubjects(),
            'difficult_questions' => $this->difficultQuestions(),
        ]);
    }

    /** Subjects ranked by number of answered questions (spec §19). */
    protected function popularSubjects(): array
    {
        return QuestionAttempt::query()
            ->join('questions', 'questions.id', '=', 'question_attempts.question_id')
            ->join('subjects', 'subjects.id', '=', 'questions.subject_id')
            ->selectRaw('subjects.id, subjects.name, count(*) as answers,
                round(sum(case when question_attempts.is_correct then 1 else 0 end) * 100.0 / count(*), 1) as accuracy')
            ->groupBy('subjects.id', 'subjects.name')
            ->orderByDesc('answers')
            ->limit(7)
            ->get()
            ->toArray();
    }

    /** Questions with the lowest correct rate, min 3 attempts (spec §19). */
    protected function difficultQuestions(): array
    {
        return Question::query()
            ->selectRaw('questions.id, questions.question_text, count(*) as attempts,
                round(sum(case when question_attempts.is_correct then 1 else 0 end) * 100.0 / count(*), 1) as accuracy')
            ->join('question_attempts', 'question_attempts.question_id', '=', 'questions.id')
            ->groupBy('questions.id', 'questions.question_text')
            ->havingRaw('count(*) >= 3')
            ->orderBy('accuracy')
            ->limit(7)
            ->get()
            ->toArray();
    }
}
