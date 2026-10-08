<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\ProgressService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    /** GET /api/v1/students — list with grade and accuracy aggregates. */
    public function index(Request $request): JsonResponse
    {
        $query = Student::with('grade')->withCount([
            'questionAttempts',
            'referrals',
            'referrals as qualified_referrals_count' => fn ($referrals) => $referrals->whereNotNull('qualified_at'),
        ])->orderByDesc('id');

        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('telegram_username', 'like', $term);
            });
        }

        if ($request->filled('grade_id')) {
            $query->where('grade_id', $request->integer('grade_id'));
        }

        $students = $query->paginate(min((int) $request->input('per_page', 15), 100));

        // Attach accuracy per student.
        $ids = collect($students->items())->pluck('id');
        $stats = \App\Models\QuestionAttempt::whereIn('student_id', $ids)
            ->selectRaw('student_id, count(*) as answered, sum(case when is_correct then 1 else 0 end) as correct')
            ->groupBy('student_id')
            ->get()->keyBy('student_id');

        $students->getCollection()->transform(function ($student) use ($stats) {
            $s = $stats->get($student->id);
            $student->answered = $s ? (int) $s->answered : 0;
            $student->accuracy = $s && $s->answered > 0
                ? round($s->correct / $s->answered * 100, 1)
                : null;

            return $student;
        });

        return response()->json($students);
    }

    /** GET /api/v1/students/{id} — profile plus full progress breakdown. */
    public function show(int $id, ProgressService $progress): JsonResponse
    {
        $student = Student::with('grade')->findOrFail($id);

        return response()->json([
            'student' => $student,
            'progress' => $progress->forStudent($student),
            'mistakes' => $progress->mistakes($student, 20),
            'attempts' => $student->attempts()->with('exam')->latest()->limit(20)->get(),
        ]);
    }

    /** PATCH /api/v1/students/{id} — admin edit (activate, change grade). */
    public function update(Request $request, int $id, TelegramClient $telegram): JsonResponse
    {
        $student = Student::findOrFail($id);

        $data = $request->validate([
            'grade_id' => 'nullable|exists:grades,id',
            'is_active' => 'boolean',
            'activate_for_days' => 'nullable|integer|in:30',
            'revoke_activation' => 'nullable|boolean',
        ]);

        $revokeActivation = (bool) ($data['revoke_activation'] ?? false);
        unset($data['revoke_activation']);

        if ($revokeActivation) {
            $data['activated_until'] = null;
            unset($data['activate_for_days']);
        } elseif (isset($data['activate_for_days'])) {
            $startsAt = $student->activated_until?->isFuture() ? $student->activated_until : now();
            $data['activated_until'] = $startsAt->copy()->addDays($data['activate_for_days']);
            unset($data['activate_for_days']);
        }

        $student->update($data);

        if (array_key_exists('activated_until', $data)) {
            $message = $student->activated_until
                ? '✅ Your account is active until <b>'
                    .e($student->activated_until->timezone('Africa/Addis_Ababa')->format('M j, Y'))
                    .'</b>. You can now use unlimited questions.'
                : 'Your account activation has ended. Free access is still available.';
            $telegram->sendMessage((int) $student->telegram_id, $message);
        }

        return response()->json($student->fresh('grade'));
    }
}
