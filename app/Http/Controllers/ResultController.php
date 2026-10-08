<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Models\QuestionAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    /** GET /api/results/practice — individual answers to practice questions. */
    public function practice(Request $request): JsonResponse
    {
        $attempts = QuestionAttempt::with([
            'student.grade',
            'question.chapter',
            'question.subject',
            'question.options',
        ])->whereNull('exam_attempt_id')
            ->latest('answered_at')
            ->paginate(min((int) $request->input('per_page', 15), 100));

        return response()->json($attempts);
    }

    /** GET /api/v1/results — completed exam attempts with filters. */
    public function index(Request $request): JsonResponse
    {
        $query = ExamAttempt::with(['exam.grade', 'exam.subject', 'student'])
            ->where('status', 'completed')
            ->orderByDesc('completed_at');

        foreach (['exam_id', 'student_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        if ($request->filled('type')) {
            $query->whereHas('exam', fn ($q) => $q->where('type', $request->input('type')));
        }

        return response()->json($query->paginate(min((int) $request->input('per_page', 15), 100)));
    }

    /** GET /api/v1/results/{id} — attempt with per-question breakdown. */
    public function show(int $id): JsonResponse
    {
        $attempt = ExamAttempt::with(['exam', 'student'])
            ->findOrFail($id);

        $questionIds = $attempt->question_ids ?? [];
        $questions = \App\Models\Question::with('options')
            ->whereIn('id', $questionIds)
            ->get()->keyBy('id');

        $breakdown = collect($questionIds)->map(function ($qid) use ($questions, $attempt) {
            $question = $questions->get($qid);
            if (! $question) {
                return null;
            }

            $answer = $attempt->answers[$qid] ?? null;

            return [
                'question' => $question->only(['id', 'question_text', 'explanation', 'difficulty']),
                'options' => $question->options,
                'chosen_option_id' => $answer['option'] ?? null,
                'is_correct' => (bool) ($answer['correct'] ?? false),
            ];
        })->filter()->values();

        return response()->json([
            'attempt' => $attempt,
            'breakdown' => $breakdown,
        ]);
    }
}
