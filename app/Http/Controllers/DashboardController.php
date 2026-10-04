<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\Student;
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
                'new_this_week' => Student::where('created_at', '>=', now()->subWeek())->count(),
            ],
            'content' => [
                'questions' => Question::count(),
                'announcements' => Announcement::count(),
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
